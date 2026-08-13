<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\Marketing\Events\MarketingUnsubscribed;
use Goldnead\Marketing\Events\SubscriptionPending;
use Goldnead\Marketing\Exceptions\ConfirmationLinkExpired;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Marketing\Sending\ConfirmationThrottle;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Database\UniqueConstraintViolationException;
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

        /*
         * Reviving a row that had ENDED disarms whatever confirmation link it
         * was still carrying.
         *
         * `status` is what `confirmByToken()` leans on to refuse a link issued
         * before an unsubscribe — and `status` is writable by anybody, from a
         * public form, by typing somebody else's address. Without this, the
         * attack is: find an address whose row is `unsubscribed` (or `bounced`)
         * while holding a mailed but never-used token, post it here, watch the
         * status flip back to `pending`, and the old link — which the gates
         * below may well decline to replace, because a withheld mail returns
         * before the token is rotated — is redeemable again. That is the very
         * hole this release is about, walked in through the front door.
         *
         * Only for rows that were not already pending: a genuine subscriber
         * waiting on a live link must keep it, or a stranger could break their
         * sign-up by submitting their address (see the throttle's test).
         */
        if ($subscription->exists && $subscription->status !== Subscription::STATUS_PENDING) {
            $subscription->forceFill([
                'confirmation_sent_at' => null,
                'confirmation_used_at' => null,
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

        try {
            $subscription->save();
        } catch (UniqueConstraintViolationException $e) {
            /*
             * Another request created the same consent row between the lookup
             * above and this insert.
             *
             * The window is small and a public sign-up form walks into it
             * routinely: an impatient double-click, a mail client prefetching
             * a form POST, two tabs. Without this the loser of the race got an
             * unhandled exception — a 500, with a stack trace, on an anonymous
             * endpoint — for having done nothing wrong. The winner's row is
             * the correct answer for both of them, and the winner is already
             * sending the confirmation.
             *
             * Rethrown if the row is somehow not there after all, because then
             * the violation was something this does not understand and
             * swallowing it would hide it.
             */
            $subscription = Subscription::query()
                ->where('uniqueness_key', Subscription::uniquenessKeyFor($list->handle, $email))
                ->first();

            if (! $subscription) {
                throw $e;
            }

            return $subscription;
        }

        if ($list->usesDoubleOptIn() && ! ($options['skip_confirmation'] ?? false)) {
            $this->sendConfirmationMail($list, $subscription);
            event(new SubscriptionPending($subscription));

            return $subscription;
        }

        return $this->markSubscribed($subscription, (array) ($options['meta'] ?? []));
    }

    /**
     * The row a confirmation link points at, with nothing decided about it.
     *
     * For the page that renders the button. Deliberately does not apply any of
     * the rules in `confirmByToken()` — a lookup that silently filtered would
     * invite a caller to treat a non-null answer as permission.
     */
    public function findByConfirmationToken(string $token): ?Subscription
    {
        return Subscription::query()->where('confirmation_token', $token)->first();
    }

    /**
     * Redeem a double-opt-in link.
     *
     * Reads `confirmation_token`, never `token`. The long-lived token is in
     * the footer of every campaign this address has ever received; if it also
     * granted consent, then everyone who has ever been forwarded one of those
     * mails is holding a key to the list.
     *
     * @throws ConfirmationLinkExpired when the link is genuine but too old
     */
    public function confirmByToken(string $token): ?Subscription
    {
        $subscription = Subscription::query()->where('confirmation_token', $token)->first();

        if (! $subscription) {
            return null;
        }

        /*
         * Already spent, and inert in both directions. While the subscription
         * still stands this renders the ordinary "you are confirmed" page, so
         * that the second click of a double-click — or the human arriving
         * after their mail client's link scanner — is met with the truth
         * rather than a 404. Once the subscription has ended it refuses, so
         * that a spent link can never be the thing that brings someone back.
         */
        if ($subscription->confirmation_used_at !== null) {
            return $subscription->isSubscribed() ? $subscription : null;
        }

        /*
         * Every row carries a confirmation token from the moment it is
         * created, because the column is NOT NULL and its unique has to mean
         * something. A token that was never MAILED is not a link, though — it
         * is a string that has never left this database — and redeeming one
         * would mean a subscription confirmed by a link nobody was ever sent.
         * `confirmation_sent_at` is the difference, and this is where it is
         * enforced.
         */
        if ($subscription->confirmation_sent_at === null) {
            return null;
        }

        /*
         * Only a pending row may be confirmed.
         *
         * This is the hole the ticket describes: the old check asked whether
         * the row was already subscribed and stopped there, so `unsubscribed`
         * fell straight through to `markSubscribed()`. Every one of these
         * states is a decision — by the person, or by their mail server on
         * their behalf — and a link issued before it may not overturn it.
         */
        if ($subscription->status !== Subscription::STATUS_PENDING) {
            return null;
        }

        if ($subscription->confirmationHasExpired()) {
            throw new ConfirmationLinkExpired;
        }

        /*
         * Spend the link with a conditional UPDATE, and let the database
         * decide who got there first.
         *
         * Reading `confirmation_used_at` and then writing it is two statements
         * with a gap, and two clicks landing in that gap both read null and
         * both confirm — two consent records, two LeadHub timeline entries,
         * for one decision. `WHERE confirmation_used_at IS NULL` makes the
         * read and the write one atomic act, and the row count says whether
         * this request was the one that won.
         */
        $claimed = Subscription::query()
            ->whereKey($subscription->getKey())
            ->whereNull('confirmation_used_at')
            ->update(['confirmation_used_at' => now()]);

        if ($claimed === 0) {
            /*
             * Someone else spent it in the meantime — the other half of a
             * double-click, or the human arriving just behind their mail
             * client's link scanner.
             *
             * The test is "was it spent", not "is it already subscribed". The
             * winner is still inside `markSubscribed()` (a save, a LeadHub
             * ingest, an event), so a status read here usually still says
             * `pending`, and requiring `isSubscribed()` would answer a
             * legitimate second click with 404 — the one thing this branch
             * exists to prevent. Anything but an ended subscription gets the
             * ordinary page.
             */
            $fresh = $subscription->fresh();

            if (! $fresh || $fresh->confirmation_used_at === null) {
                return null;
            }

            return $fresh->status === Subscription::STATUS_UNSUBSCRIBED ? null : $fresh;
        }

        return $this->markSubscribed($subscription->refresh(), [
            'reason' => 'double_opt_in',
            'consent_proof' => 'confirmation_link',
        ]);
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

        /*
         * How much of this one mailbox has already been asked to confirm.
         *
         * Everything in front of this counts senders: the websites throttle
         * per client IP, the hub per brand at 120 a minute. None of them can
         * see that all 120 are aimed at the same person. This is the only
         * place in the stack that knows the recipient, which is why the limit
         * lives here rather than at any of the three endpoints that lead to
         * it.
         *
         * Withheld silently, and that is deliberate rather than lazy. The
         * caller gets the identical status and the identical body whether the
         * mail went out or not, so nothing an attacker READS distinguishes the
         * two — the same reason the suppression gate above says nothing
         * either. The pending row survives, so a genuine subscriber whose hour
         * has not passed is delayed rather than lost.
         *
         * What this does NOT hide is timing: the mailable is built and sent
         * inline, so a request that actually sent one pays an SMTP round trip
         * and a withheld one does not. Anybody willing to measure tens of
         * milliseconds can still ask "was this mailbox asked recently", and
         * through the suppression gate above, "is this address blocked". That
         * is worth knowing rather than papering over; closing it means queueing
         * the send, which is a change to how every install delivers this mail.
         */
        try {
            $withheld = app(ConfirmationThrottle::class)->charge((string) $subscription->email, $list->handle);
        } catch (\Throwable $e) {
            /*
             * A cache that is unreachable THROWS; it does not answer zero. An
             * uncaught Redis timeout here would be a 500 on an anonymous
             * endpoint, after the pending row was already written — the exact
             * failure the comment further down refuses for the mail transport,
             * reintroduced one line above it. Not knowing is still not
             * permission, so the mail is withheld rather than sent.
             */
            report($e);

            return;
        }

        if ($withheld) {
            Log::warning(
                'Marketing withheld a confirmation mail on list ['.$list->handle.']: '.$withheld
                .'. The pending subscription was kept.',
                ['reason' => $withheld, 'list' => $list->handle]
            );

            return;
        }

        /*
         * A fresh link for this mail, which retires the previous one.
         *
         * After the gates, never before: an attempt that was throttled away
         * must leave the link the person is actually holding alone, or typing
         * a stranger's address into the public form becomes a way to break
         * their pending sign-up.
         */
        $subscription->issueConfirmationToken();

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
