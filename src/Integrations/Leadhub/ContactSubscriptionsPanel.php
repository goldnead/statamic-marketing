<?php

namespace Goldnead\Marketing\Integrations\Leadhub;

use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * "Which mailing lists is this person on?", answered on LeadHub's contact page.
 *
 * The two addons were already married underneath — a subscription resolves to a
 * LeadHub contact, campaign audiences come from LeadHub segments, and LeadHub's
 * `do_not_contact` is what an unsubscribe sets. None of that was visible: the
 * contact screen showed tags, tasks and a timeline, and said nothing about the
 * newsletter the person had been receiving for a year.
 *
 * Contributed from this side through LeadHub's panel registry rather than read
 * from LeadHub's side, because marketing requires leadhub and leadhub requires
 * nobody. Reversing that for one panel would make an optional sibling a hard
 * dependency of the CRM.
 */
class ContactSubscriptionsPanel
{
    /** The badge colour per subscription status, in LeadHub's Badge vocabulary. */
    protected const COLORS = [
        Subscription::STATUS_SUBSCRIBED => 'green',
        Subscription::STATUS_PENDING => 'amber',
        Subscription::STATUS_UNSUBSCRIBED => 'default',
        Subscription::STATUS_BOUNCED => 'red',
        Subscription::STATUS_COMPLAINED => 'red',
    ];

    public function __construct(protected MailingListRepository $lists) {}

    /**
     * @param  mixed  $contact  A LeadHub Contact model. Typed loosely on purpose:
     *                          this class must not import from the sibling.
     * @return array<string, mixed>|null
     */
    public function __invoke(mixed $contact): ?array
    {
        $subscriptions = $this->subscriptionsFor($contact);

        if ($subscriptions === null) {
            return null;
        }

        // Names come from the list repository, once per handle. The handle is
        // what a subscription stores, and showing `chorleiter-brief` where the
        // list is called "Der Chorleiter-Brief" would be showing the database
        // rather than the list.
        $names = $this->lists->all()->mapWithKeys(
            fn ($list) => [$list->handle => $list->name]
        );

        $rows = $subscriptions->map(fn (Subscription $subscription) => [
            'label' => $names[$subscription->list_handle] ?? $subscription->list_handle,
            'url' => $this->listUrl($subscription->list_handle),
            'meta' => $this->meta($subscription),
            'badge' => [
                'text' => $this->statusLabel($subscription->status),
                'color' => self::COLORS[$subscription->status] ?? 'default',
            ],
        ])->values()->all();

        return [
            'heading' => __('marketing::leadhub.panel_heading'),
            'description' => __('marketing::leadhub.panel_description'),
            'empty' => __('marketing::leadhub.panel_empty'),
            'rows' => $rows,
            'action' => $this->addAction($contact, $subscriptions),
        ];
    }

    /**
     * "Put this person on a list", offered on the contact screen.
     *
     * The panel could only ever report until now: a reader who saw that
     * somebody was on no list had to leave, find the list, and type the
     * address back in. Expressed through the registry's select-shaped action,
     * so LeadHub posts what it is handed and still knows nothing about lists.
     *
     * Null when the reader may not manage subscribers, or when every list
     * already has this person — an empty picker is a worse answer than none.
     */
    protected function addAction(mixed $contact, mixed $subscriptions): ?array
    {
        if (! Gate::allows('manage marketing subscribers')) {
            return null;
        }

        $email = is_object($contact) ? ($contact->email ?? null) : null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        // Lists this person is on. A second subscribe to the same list is
        // harmless underneath, but offering it says the row above is not there.
        $taken = collect($subscriptions)
            ->reject(fn (Subscription $s) => $s->status === Subscription::STATUS_UNSUBSCRIBED)
            ->pluck('list_handle')
            ->all();

        $options = $this->lists->all()
            ->reject(fn ($list) => in_array($list->handle, $taken, true))
            ->map(fn ($list) => [
                'value' => $list->handle,
                'label' => $list->name,
                'url' => cp_route('marketing.lists.subscribers.store', $list->handle),
                'payload' => ['email' => $email],
            ])
            ->values()
            ->all();

        if ($options === []) {
            return null;
        }

        return [
            'text' => __('marketing::leadhub.panel_add'),
            'icon' => 'plus',
            'select' => [
                'placeholder' => __('marketing::leadhub.panel_add_placeholder'),
                'options' => $options,
            ],
        ];
    }

    /**
     * This person's subscriptions, newest first.
     *
     * Matched on the normalised address, not on `contact_uuid`. The uuid is only
     * filled once a subscription has been confirmed and synced, so a pending
     * double-opt-in sign-up carries none — and a pending sign-up is exactly the
     * thing somebody opens this page to check. The address is what both sides
     * agree on from the first second.
     *
     * Returns null when there is no address to match on, which is a contact
     * that cannot be on a list at all — a different answer from "on no list".
     */
    protected function subscriptionsFor(mixed $contact): mixed
    {
        $email = is_object($contact) ? ($contact->email ?? null) : null;

        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return Subscription::query()
            ->where('email_normalized', EmailNormalizer::normalize($email))
            ->orderByDesc('subscribed_at')
            ->orderByDesc('id')
            ->get();
    }

    /** Since when, in one line. */
    protected function meta(Subscription $subscription): ?string
    {
        if ($subscription->status === Subscription::STATUS_UNSUBSCRIBED && $subscription->unsubscribed_at) {
            return __('marketing::leadhub.since_unsubscribed', [
                'date' => $subscription->unsubscribed_at->isoFormat('LL'),
            ]);
        }

        if ($subscription->subscribed_at) {
            return __('marketing::leadhub.since_subscribed', [
                'date' => $subscription->subscribed_at->isoFormat('LL'),
            ]);
        }

        return null;
    }

    /**
     * A status this addon does not know still has to read as something.
     *
     * `__()` hands back the key it could not find, so a row imported from an
     * older schema or another system printed the literal string
     * "marketing::leadhub.status_confirmed" into the Control Panel. Falling
     * back to the raw value is not pretty, but it is a word rather than a bug
     * report addressed to the reader.
     */
    protected function statusLabel(string $status): string
    {
        $schluessel = 'marketing::leadhub.status_'.$status;

        return ($label = (string) __($schluessel)) === $schluessel
            ? Str::headline($status)
            : $label;
    }

    /**
     * The list's own screen, when the reader may open it.
     *
     * Null rather than a link they cannot follow: a Control Panel user with the
     * CRM permissions and none of marketing's would otherwise get a row that
     * 403s when clicked.
     */
    protected function listUrl(string $handle): ?string
    {
        if (! Gate::allows('view marketing')) {
            return null;
        }

        return cp_route('marketing.lists.show', $handle);
    }
}
