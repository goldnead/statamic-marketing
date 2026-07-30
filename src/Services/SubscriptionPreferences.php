<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\ListPreference;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Data\PreferenceCenter;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Goldnead\Suppression\Support\EmailNormalizer;
use Illuminate\Support\Collection;

/**
 * The preference centre: one unsubscribe token, every list of that token's
 * brand, and the consent behind each of them.
 *
 * **Why the token is enough to switch a list back on.** The token was
 * delivered to the address it belongs to. That is the same proof a
 * confirmation mail collects — send something to the mailbox, see it come
 * back — so asking for a second double opt-in would re-establish a fact the
 * click has already established. The re-subscription is written to the
 * LeadHub timeline with the reason it happened, so the consent record says
 * how it was given rather than merely that it was.
 *
 * **What the token is not enough for.** It is in every mail this brand sends,
 * which makes it a credential a lot of software has touched. It may therefore
 * end consent, and it may restore consent the person themselves ended. It may
 * never lift a *block*: a hard bounce, a spam complaint or an opt-out recorded
 * by hand are decisions made about the address rather than by the reader of
 * one mail, and a click in a mail client must not be able to undo them. That
 * is `$suppressed` below, and it is enforced here rather than in the template,
 * because a disabled checkbox is a suggestion and a crafted POST is not.
 *
 * **Where "blocked" is read from — two sources, and both of them bind.** This
 * page must ask what the send paths ask, or the rule above is a decoration. It
 * therefore reads `do_not_contact` on the LeadHub contact *and* the suppression
 * gate that 1.8.0 put in front of all four send paths, and a block from either
 * one is a block. One source is not enough in either direction: a provider
 * bounce or complaint now lands only in `suppressions`, and an editor's opt-out
 * in the CRM still lands only on the contact.
 */
class SubscriptionPreferences
{
    public function __construct(
        protected MailingListRepository $lists,
        protected SubscriptionService $subscriptions,
        protected ContactRepository $contacts,
        protected SuppressionGate $gate,
    ) {
    }

    /**
     * Build the page for a token, or null when the token addresses nothing.
     *
     * Nothing here filters by brand, and that is the point: the brand was
     * already derived from this token by the route middleware, so the model
     * scope and the list repository are both answering for that one brand.
     * A second, hand-written brand filter would be a second place to get it
     * wrong — and one that a single-brand install would never exercise.
     */
    public function forToken(string $token): ?PreferenceCenter
    {
        $subscription = Subscription::query()->where('token', $token)->first();

        return $subscription ? $this->forSubscription($subscription) : null;
    }

    public function forSubscription(Subscription $subscription): PreferenceCenter
    {
        $contactSuppressed = $this->contactIsSuppressed($subscription);
        $mine = $this->subscriptionsOfThePerson($subscription);
        $gate = $this->gateVerdict($subscription, $mine);

        $rows = $this->lists->all()->map(function (MailingList $list) use ($mine, $subscription, $contactSuppressed, $gate) {
            $row = $mine->get($list->handle);
            $rowSuppressed = $row ? $this->statusIsSuppression($row->status) : false;

            // The address this row would actually be mailed at: its own, if the
            // person holds this list under a second mailbox tied to the same
            // contact, and otherwise the one the token was delivered to.
            $gateSuppressed = $gate['unavailable'] || isset($gate['blocked'][
                (string) EmailNormalizer::normalize((string) ($row->email ?? $subscription->email))
            ]);

            return new ListPreference(
                list: $list,
                subscription: $row,
                active: (bool) $row?->isSubscribed(),
                suppressed: $contactSuppressed || $rowSuppressed || $gateSuppressed,
                suppressionReason: match (true) {
                    $gate['unavailable'] => 'suppression_unavailable',
                    $gateSuppressed => 'suppression_list',
                    $contactSuppressed => 'contact',
                    $rowSuppressed => (string) $row->status,
                    default => null,
                },
                current: $list->handle === $subscription->list_handle,
            );
        })->values();

        return new PreferenceCenter(
            subscription: $subscription,
            rows: $rows,
            contactSuppressed: $contactSuppressed,
        );
    }

    /**
     * Apply a wanted set of list handles.
     *
     * Returns what was actually done, so the page can report it instead of
     * redrawing itself and leaving the reader to guess. Refusals are part of
     * that return value: a request to switch on a blocked list is answered
     * out loud, never dropped.
     *
     * @param  list<string>  $wanted
     * @return array{subscribed:list<string>, unsubscribed:list<string>, refused:list<string>, unknown:list<string>}
     */
    public function apply(PreferenceCenter $center, array $wanted): array
    {
        $wanted = array_values(array_unique(array_filter($wanted, 'is_string')));
        $result = ['subscribed' => [], 'unsubscribed' => [], 'refused' => [], 'unknown' => []];

        // Handles that name no list of this brand. Another brand's list lands
        // here too, and deliberately reads the same as a handle nobody has:
        // the answer must not tell a stranger which brands exist beside this
        // one.
        $known = $center->rows->map(fn (ListPreference $row) => $row->handle())->all();
        $result['unknown'] = array_values(array_diff($wanted, $known));

        foreach ($center->rows as $row) {
            $shouldBeOn = in_array($row->handle(), $wanted, true);

            if ($row->suppressed) {
                // Blocked rows are left entirely alone, in both directions. A
                // bounced or complained subscription is already not receiving
                // anything, and rewriting it to "unsubscribed" because a form
                // came back without its checkbox would erase the reason it
                // stopped — the one piece of state the sending path relies on.
                if ($shouldBeOn) {
                    $result['refused'][] = $row->handle();
                }

                continue;
            }

            if ($shouldBeOn && ! $row->active) {
                $this->activate($row, $center);
                $result['subscribed'][] = $row->handle();

                continue;
            }

            if (! $shouldBeOn && $row->active) {
                $this->subscriptions->unsubscribe($row->subscription, ['reason' => 'preference_center']);
                $result['unsubscribed'][] = $row->handle();
            }
        }

        return $result;
    }

    /**
     * End every subscription this person holds **with this brand**.
     *
     * Not the CRM-wide opt-out. Someone who no longer wants this brand's
     * mailings has said nothing about another brand's, and nothing at all
     * about the transactional mail that does not rest on consent in the first
     * place. Where an install has configured `unsubscribe.global_opt_out`,
     * that setting still applies — through the ordinary unsubscribe path, as
     * it does everywhere else, rather than through a second rule here.
     *
     * @return list<string> the handles that were ended
     */
    public function unsubscribeFromEverything(PreferenceCenter $center): array
    {
        $ended = [];

        foreach ($center->rows as $row) {
            if ($row->suppressed || ! $row->subscription) {
                continue;
            }

            if ($row->subscription->status === Subscription::STATUS_UNSUBSCRIBED) {
                continue;
            }

            $this->subscriptions->unsubscribe($row->subscription, ['reason' => 'preference_center_all']);
            $ended[] = $row->handle();
        }

        return $ended;
    }

    /**
     * Switch a list on: confirm a subscription that exists, create one that
     * does not. Either way without a confirmation mail — see the class note.
     */
    protected function activate(ListPreference $row, PreferenceCenter $center): void
    {
        $metadata = ['reason' => 'preference_center', 'consent_proof' => 'unsubscribe_token'];

        if ($row->subscription) {
            $this->subscriptions->markSubscribed($row->subscription, $metadata);

            return;
        }

        // A list this person has never been on. The address comes from the
        // token's own subscription, so a new row can only ever be created for
        // the mailbox the token was delivered to.
        $this->subscriptions->subscribe(
            $row->list,
            $center->email(),
            array_filter([
                'first_name' => $center->subscription->first_name,
                'last_name' => $center->subscription->last_name,
            ]),
            ['source' => 'preference_center', 'skip_confirmation' => true, 'meta' => $metadata],
        );
    }

    /**
     * The person's other subscriptions in this brand, keyed by list handle.
     *
     * `contact_uuid` is the identity, as it is everywhere else in this addon.
     * The normalized address is matched as well, because a subscription only
     * gets a contact_uuid once it has been confirmed and synced — a pending
     * sign-up has none, and leaving it out would show a half-finished sign-up
     * as a list the person is not on and quietly offer to start it again.
     *
     * @return Collection<string, Subscription>
     */
    protected function subscriptionsOfThePerson(Subscription $subscription): Collection
    {
        return Subscription::query()
            ->where(function ($query) use ($subscription) {
                $query->where('email_normalized', $subscription->email_normalized);

                if ($subscription->contact_uuid) {
                    $query->orWhere('contact_uuid', $subscription->contact_uuid);
                }
            })
            ->get()
            ->keyBy('list_handle');
    }

    /**
     * The suppression gate's answer for every address this page can act on.
     *
     * **One question per row, not one for the page.** The rows of a preference
     * centre do not all carry the same mailbox: a person who holds a second
     * list under a second address is found through the same `contact_uuid`, and
     * that row would be mailed at *its* address. Asking once for the token's
     * address would answer for the wrong mailbox on every row but one. It is
     * still a single query — `suppressedAmong()` takes the batch.
     *
     * **And one brand, deliberately.** No brand is passed, so the gate resolves
     * the current one, which the route middleware derived from this very token
     * — the same brand whose lists the page is showing. That is what makes
     * decision D1 land correctly on each row: a hard bounce is stored globally
     * and blocks here because the mailbox is gone for everyone, while a
     * complaint stays in the brand that received it and must not shut a page
     * belonging to a brand the person never objected to. Asking "blocked
     * anywhere" would leak the second brand's relationship into this one;
     * asking only this addon's own state would keep offering a dead mailbox.
     *
     * **A gate that cannot answer blocks everything.** "The query failed" and
     * "nobody is suppressed" are not the same statement — the rule the gate
     * contract is built on. Catching here is not carrying on: it *is* the
     * closed answer, rendered as a page whose rows are all un-switchable. It
     * costs an unsubscribe during the outage, which is the cheap side of the
     * trade, because every send path fails closed on the same fault and nothing
     * is going out to unsubscribe from.
     *
     * @param  Collection<string, Subscription>  $mine
     * @return array{blocked: array<string, true>, unavailable: bool}
     */
    protected function gateVerdict(Subscription $subscription, Collection $mine): array
    {
        $addresses = $mine
            ->map(fn (Subscription $row) => (string) $row->email)
            ->push((string) $subscription->email)
            ->all();

        try {
            return ['blocked' => $this->gate->suppressedAmong($addresses), 'unavailable' => false];
        } catch (SuppressionCheckFailed) {
            return ['blocked' => [], 'unavailable' => true];
        }
    }

    /**
     * Is the contact behind this token blocked from all sending?
     *
     * The second of the two sources, and the one that is not the gate.
     * `do_not_contact` on the LeadHub contact is what an unsubscribe sets where
     * `marketing.unsubscribe.global_opt_out` is on, and what an editor sets by
     * hand in the CRM. It is read *as well as* the suppression table rather
     * than instead of it: since 1.8.0 a provider bounce or complaint is written
     * to `suppressions` and may never touch this flag, so a page that asked
     * only here would hand the token back its blocked lists.
     */
    protected function contactIsSuppressed(Subscription $subscription): bool
    {
        $contact = $subscription->contact_uuid
            ? $this->contacts->findByUuid($subscription->contact_uuid)
            : null;

        $contact ??= $this->contacts->findByEmailNormalized(
            (string) $subscription->email_normalized
        );

        return (bool) $contact?->do_not_contact;
    }

    /** A subscription status that is a block rather than a choice. */
    protected function statusIsSuppression(?string $status): bool
    {
        return in_array($status, [
            Subscription::STATUS_BOUNCED,
            Subscription::STATUS_COMPLAINED,
        ], true);
    }

}
