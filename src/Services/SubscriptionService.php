<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\Marketing\Events\MarketingUnsubscribed;
use Goldnead\Marketing\Events\SubscriptionPending;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    /**
     * Subscribe an email address to a list. Idempotent: an already-subscribed
     * address is a no-op, a previously unsubscribed or pending one restarts
     * the (double) opt-in flow.
     *
     * `skip_confirmation` bypasses double opt-in for additions where consent is
     * already established elsewhere — an editor adding someone by hand vouches
     * for it. Without it such an addition was confirmed AND asked to confirm at
     * the same time: the record went straight to subscribed while the person
     * received "please confirm your subscription" for something already done.
     *
     * @param  array{first_name?:string,last_name?:string}  $attributes
     * @param  array{source?:string,meta?:array,skip_confirmation?:bool}  $options
     */
    public function subscribe(MailingList $list, string $email, array $attributes = [], array $options = []): Subscription
    {
        // Looked up by the same key the unique index is built on, so the check
        // and the constraint cannot disagree about what "already subscribed"
        // means. The brand comes from HasBrand's global scope, and stays a
        // column of the index rather than part of the key.
        $subscription = Subscription::query()
            ->where('uniqueness_key', Subscription::uniquenessKeyFor($list->handle, $email))
            ->first();

        if ($subscription?->isSubscribed()) {
            return $subscription;
        }

        if (! $subscription) {
            $subscription = new Subscription([
                'list_handle' => $list->handle,
                'email' => $email,
            ]);
        }

        $subscription->fill([
            'first_name' => $attributes['first_name'] ?? $subscription->first_name,
            'last_name' => $attributes['last_name'] ?? $subscription->last_name,
            'source' => $options['source'] ?? $subscription->source,
            'meta' => array_merge((array) $subscription->meta, (array) ($options['meta'] ?? [])),
            'status' => Subscription::STATUS_PENDING,
            'subscribed_at' => now(),
            'unsubscribed_at' => null,
        ]);
        $subscription->save();

        if ($list->usesDoubleOptIn() && ! ($options['skip_confirmation'] ?? false)) {
            $this->sendConfirmationMail($list, $subscription);
            event(new SubscriptionPending($subscription));

            return $subscription;
        }

        return $this->markSubscribed($subscription, (array) ($options['meta'] ?? []));
    }

    public function confirmByToken(string $token): ?Subscription
    {
        $subscription = Subscription::query()->where('token', $token)->first();

        if (! $subscription) {
            return null;
        }

        if ($subscription->isSubscribed()) {
            return $subscription;
        }

        return $this->markSubscribed($subscription);
    }

    /**
     * @param  array{reason?:string,consent_proof?:string}  $metadata  how this
     *                                                                 consent was established, written to the contact timeline. A
     *                                                                 consent record that says only *that* it exists cannot be
     *                                                                 defended later; one that says how it was given can.
     */
    public function markSubscribed(Subscription $subscription, array $metadata = []): Subscription
    {
        $subscription->fill([
            'status' => Subscription::STATUS_SUBSCRIBED,
            'confirmed_at' => now(),
            'unsubscribed_at' => null,
        ]);
        $subscription->save();

        $this->syncContactOnSubscribe($subscription, $metadata);

        event(new MarketingSubscribed($subscription));

        return $subscription;
    }

    public function unsubscribeByToken(string $token, array $metadata = []): ?Subscription
    {
        $subscription = Subscription::query()->where('token', $token)->first();

        return $subscription ? $this->unsubscribe($subscription, $metadata) : null;
    }

    /**
     * @param  array{campaign?:string,message_id?:int,reason?:string}  $metadata
     */
    public function unsubscribe(Subscription $subscription, array $metadata = []): Subscription
    {
        if ($subscription->status === Subscription::STATUS_UNSUBSCRIBED) {
            return $subscription;
        }

        $subscription->fill([
            'status' => Subscription::STATUS_UNSUBSCRIBED,
            'unsubscribed_at' => now(),
        ]);
        $subscription->save();

        if (! empty($metadata['message_id'])) {
            MessageEvent::create([
                'message_id' => $metadata['message_id'],
                'type' => MessageEvent::TYPE_UNSUBSCRIBE,
                'meta' => $metadata,
            ]);
        }

        $this->syncContactOnUnsubscribe($subscription, $metadata);

        event(new MarketingUnsubscribed($subscription, $metadata));

        return $subscription;
    }

    /**
     * The double opt-in confirmation.
     *
     * Gated like every other send path, and the reason is the trivial bypass it
     * would otherwise be: a hard-bounced or complaint-blocked address that fills
     * in the sign-up form again would receive mail from us, having gone through
     * a public form that anybody can type any address into. A confirmation mail
     * is still a mail to a mailbox that said no.
     *
     * The subscription row is still written. Someone re-subscribing has said
     * something, and recording it is right; sending to them is not. If the
     * suppression is later released, the pending row is there and the ordinary
     * flow resumes.
     */
    public function sendConfirmationMail(MailingList $list, Subscription $subscription): void
    {
        try {
            if (app(SuppressionGate::class)->isSuppressed((string) $subscription->email)) {
                Log::info(
                    'Marketing withheld the confirmation mail for a suppressed address on list ['
                    .$list->handle.']; the pending subscription was kept.'
                );

                return;
            }
        } catch (SuppressionCheckFailed $e) {
            // Not knowing is not permission — the mail is withheld, not sent.
            report($e);

            return;
        }

        // Through the brand's own transport and sender identity, never the
        // global one. This mail names a list that belongs to exactly one brand;
        // arriving from a different brand's address is both wrong to read and,
        // on a relay that verifies domains per account, undeliverable as sent.
        // `app()` rather than a constructor argument because this class has no
        // constructor and takes its collaborators the same way one line above.
        // `false` — a brand that declared a mail identity and broke it half —
        // leaves the subscription `pending` and writes the reason to the log
        // once per brand per window. Deliberately not an exception: this runs
        // behind a public form, and a 500 after the pending row was written
        // leaves somebody registered as unconfirmed who will never get a link
        // and cannot ask for one either. The pending row is the thing that can
        // still be rescued by a second attempt once the brand row is fixed.
        app(BrandMailer::class)->send(
            $subscription->brand_id === null ? null : (int) $subscription->brand_id,
            (string) $subscription->email,
            null,
            new ConfirmSubscriptionMail($list, $subscription),
        );
    }

    /**
     * Upsert the LeadHub contact, leave a timeline entry, and optionally tag
     * the contact with the list handle.
     */
    protected function syncContactOnSubscribe(Subscription $subscription, array $metadata = []): void
    {
        LeadHub::ingest([
            'email' => $subscription->email,
            'type' => 'marketing.subscribed',
            'summary' => __('Subscribed to mailing list :list', ['list' => $subscription->list_handle]),
            'source_type' => 'marketing.subscription',
            'source_id' => $subscription->uuid,
            'dedupe_key' => 'marketing:subscribed:'.$subscription->uuid.':'.$subscription->confirmed_at?->timestamp,
            'contact' => array_filter([
                'first_name' => $subscription->first_name,
                'last_name' => $subscription->last_name,
            ]),
            'source' => $subscription->source ?? 'marketing',
            'payload' => array_merge(['list' => $subscription->list_handle], $metadata),
        ]);

        $contact = LeadHub::findByEmail($subscription->email);

        if ($contact) {
            $subscription->forceFill(['contact_uuid' => $contact['uuid']])->save();

            if (config('marketing.leadhub.tag_subscribers', true)) {
                LeadHub::addTag($contact['uuid'], config('marketing.leadhub.tag_prefix', 'list:').$subscription->list_handle);
            }
        }
    }

    protected function syncContactOnUnsubscribe(Subscription $subscription, array $metadata = []): void
    {
        $contact = $subscription->contact_uuid
            ? LeadHub::find($subscription->contact_uuid)
            : LeadHub::findByEmail($subscription->email);

        if (! $contact) {
            return;
        }

        LeadHub::ingest([
            'email' => $subscription->email,
            'type' => 'marketing.unsubscribed',
            'summary' => __('Unsubscribed from mailing list :list', ['list' => $subscription->list_handle]),
            'source_type' => 'marketing.subscription',
            'source_id' => $subscription->uuid,
            'dedupe_key' => 'marketing:unsubscribed:'.$subscription->uuid.':'.$subscription->unsubscribed_at?->timestamp,
            'payload' => array_merge(['list' => $subscription->list_handle], $metadata),
        ]);

        if (config('marketing.leadhub.tag_subscribers', true)) {
            LeadHub::removeTag($contact['uuid'], config('marketing.leadhub.tag_prefix', 'list:').$subscription->list_handle);
        }

        if (config('marketing.unsubscribe.global_opt_out', false)) {
            LeadHub::optOut($contact['uuid']);
        }
    }
}
