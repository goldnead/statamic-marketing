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
use Goldnead\Marketing\Sending\ConfirmationResult;
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

        /*
         * Already on the list. No row to write, no mail to send — and not a
         * word about either, because "this address is already subscribed" is
         * the one thing a public form may never answer.
         *
         * The throttle is charged anyway, and that is the whole point of this
         * branch rather than an oversight. `$subscription->confirmation` is
         * about to be read by a website and shown to a stranger; if this path
         * answered SENT forever while an ordinary address flipped to THROTTLED
         * on the second attempt, then two submissions would separate "already
         * subscribed" from "not subscribed" — the oracle, rebuilt out of the
         * very field added to stop lying to people. Costing the same is what
         * makes the two indistinguishable.
         */
        if ($subscription?->isSubscribed()) {
            $subscription->confirmation = $this->coverForSilentPath($list, $subscription);

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

            // The winner of the race is inside `sendConfirmationMail()` right
            // now. Saying "sent" is the truth about this address, and it is
            // the same answer the winner will give.
            $subscription->confirmation = ConfirmationResult::sent();

            return $subscription;
        }

        if ($list->usesDoubleOptIn() && ! ($options['skip_confirmation'] ?? false)) {
            $subscription->confirmation = $this->sendConfirmationMail($list, $subscription);
            event(new SubscriptionPending($subscription));

            return $subscription;
        }

        // No confirmation mail exists on this path — a single-opt-in list, or
        // an editor adding somebody they vouch for. Nothing was withheld, so
        // there is nothing to report.
        $subscription = $this->markSubscribed($subscription, (array) ($options['meta'] ?? []));
        $subscription->confirmation = ConfirmationResult::sent();

        return $subscription;
    }

    /**
     * Charge the recipient budget for a path that will send nothing and may not
     * say why.
     *
     * Reached when the address is already subscribed. It has to be
     * indistinguishable from an ordinary sign-up across repeated attempts, so
     * it pays the same price and reports the same outcomes; see the comment at
     * the call site and `ConfirmationResult`.
     */
    protected function coverForSilentPath(MailingList $list, Subscription $subscription): ConfirmationResult
    {
        // A list without double opt-in has no confirmation mail to withhold in
        // the first place, so there is nothing here to hide and no budget that
        // would mean anything.
        if (! $list->usesDoubleOptIn()) {
            return ConfirmationResult::sent();
        }

        return $this->chargeRecipientBudget($list, $subscription) ?? ConfirmationResult::sent();
    }

    /**
     * How much double-opt-in mail this mailbox has already been made to
     * receive. Null when the send may proceed.
     */
    protected function chargeRecipientBudget(MailingList $list, Subscription $subscription): ?ConfirmationResult
    {
        $throttle = app(ConfirmationThrottle::class);

        try {
            $withheld = $throttle->charge((string) $subscription->email, $list->handle);
        } catch (\Throwable $e) {
            /*
             * A cache that is unreachable THROWS; it does not answer zero. An
             * uncaught Redis timeout here would be a 500 on an anonymous
             * endpoint, after the pending row was already written. Not knowing
             * is not permission, so the mail is withheld rather than sent — and
             * the caller is told the installation could not send, which is true
             * of every address alike and therefore discloses nothing.
             */
            report($e);

            return ConfirmationResult::unavailable();
        }

        if ($withheld === null) {
            return null;
        }

        if ($withheld === ConfirmationThrottle::WITHHELD_NO_COUNTER) {
            Log::warning(
                'Marketing withheld a confirmation mail on list ['.$list->handle.']: no usable counter. '
                .'The pending subscription was kept.',
                ['reason' => $withheld, 'list' => $list->handle]
            );

            return ConfirmationResult::unavailable();
        }

        Log::warning(
            'Marketing withheld a confirmation mail on list ['.$list->handle.']: '.$withheld
            .'. The pending subscription was kept.',
            ['reason' => $withheld, 'list' => $list->handle]
        );

        return ConfirmationResult::throttled($throttle->windowMinutes($withheld));
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
     *
     * The return value is what the sign-up may be told; `ConfirmationResult`
     * carries the whole argument about which of the three answers a case gets
     * and why. Two rules govern this method:
     *
     *   1. Nothing that is specific to the PERSON leaves here. A suppressed
     *      address gets the same SENT as a mail that really went out.
     *   2. Everything that is true of the INSTALLATION does leave here. A hub
     *      that cannot count, cannot resolve its brand's sender or cannot reach
     *      its relay is not a secret, and hiding it is how sign-ups were lost
     *      in silence until 2.5.0.
     */
    public function sendConfirmationMail(MailingList $list, Subscription $subscription): ConfirmationResult
    {
        /*
         * The recipient budget is charged FIRST, ahead of the suppression gate,
         * and the order is load-bearing.
         *
         * Suppression is silent by design: a blocked address gets the ordinary
         * SENT so that the form cannot be used to ask "did this mailbox bounce
         * or complain". Charged second, the gate would return before the
         * counter ever moved, so a suppressed address would answer SENT to
         * every attempt while an ordinary one flipped to THROTTLED on the
         * second — and the silence would be undone by exactly two probes.
         * Charging first makes the two sequences identical.
         *
         * It also closes a smaller hole on its own merits: until 2.5.0 a
         * suppressed address cost nothing at all, so it could be pushed through
         * this endpoint without limit.
         *
         * What none of this hides is TIMING. The mailable is built and handed
         * to SMTP inline, so a request that really sent one pays a round trip
         * and a withheld one does not. Anybody willing to measure tens of
         * milliseconds can still ask "was a mail actually sent here", and
         * through the gate below, "is this address blocked". That is the
         * residual, stated rather than papered over; closing it means queueing
         * the send, which changes how every install delivers this mail.
         */
        if ($withheld = $this->chargeRecipientBudget($list, $subscription)) {
            return $withheld;
        }

        try {
            if (app(SuppressionGate::class)->isSuppressed((string) $subscription->email)) {
                Log::info(
                    'Marketing withheld the confirmation mail for a suppressed address on list ['
                    .$list->handle.']; the pending subscription was kept.'
                );

                // SENT, and this is the one deliberate untruth in the method.
                // "This address is blocked" is a fact about a person's mailbox
                // and their past behaviour, and a public form may not answer it.
                return ConfirmationResult::sent();
            }
        } catch (SuppressionCheckFailed $e) {
            // Not knowing is not permission — the mail is withheld, not sent.
            // A failing gate is a property of the installation, not of the
            // address, so unlike the branch above it may be reported outwards.
            report($e);

            return ConfirmationResult::unavailable();
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
        //
        // The try/catch closes the half of that promise the return value never
        // covered. `BrandMailer` answers a broken brand identity with `false`,
        // but the transport itself THROWS: a relay that refuses the recipient
        // at RCPT time — `501 5.1.3 … not a valid RFC-5322 address` for an
        // address that passed every validator on the way in — comes back as an
        // exception from deep inside Symfony's SMTP client, straight through
        // this method and out as a 500 on an anonymous endpoint. Measured
        // against the live relay, not imagined.
        //
        // Swallowed rather than raised, for exactly the reason stated above:
        // the person is already written down as pending, and answering their
        // sign-up with a stack trace helps nobody. `report()` puts it in front
        // of whoever watches the logs, which is where a delivery problem
        // belongs — not in the face of somebody who typed their address into a
        // form.
        try {
            $sent = app(BrandMailer::class)->send(
                $subscription->brand_id === null ? null : (int) $subscription->brand_id,
                (string) $subscription->email,
                null,
                new ConfirmSubscriptionMail($list, $subscription),
            );
        } catch (\Throwable $e) {
            report($e);

            $sent = false;
        }

        // Both failures above are properties of this installation — a brand row
        // with half a sender identity, a relay that would not take the message.
        // Neither says anything about the person, and both used to end here in
        // silence while the sign-up was told to go and check an empty inbox.
        return $sent ? ConfirmationResult::sent() : ConfirmationResult::unavailable();
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
