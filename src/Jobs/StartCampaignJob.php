<?php

namespace Goldnead\Marketing\Jobs;

use Carbon\CarbonImmutable;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\CampaignSending;
use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\VariantAssigner;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Snapshots the audience of a campaign into message rows and fans out one
 * SendMessageJob per recipient, honouring the configured throttle.
 */
class StartCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $campaignHandle) {}

    public function handle(
        CampaignRepository $campaigns,
        ContactRepository $contacts,
        SuppressionGate $gate,
        VariantAssigner $variants,
    ): void {
        $campaign = $campaigns->find($this->campaignHandle);

        if (! $campaign || $campaign->status !== Campaign::STATUS_SENDING) {
            return;
        }

        event(new CampaignSending($campaign));

        $queue = (string) config('marketing.sending.queue', 'default');
        $perMinute = (int) config('marketing.sending.messages_per_minute', 0);
        $index = 0;

        // Optional segment narrowing. The audience is ALWAYS the subscribed
        // members of the list (consent comes solely from the list); a segment
        // only intersects that set down. Resolved LIVE at send time as a set of
        // LeadHub contact UUIDs. `null` = no segment filter (whole list).
        $segmentMemberIds = $this->resolveSegmentMemberIds($campaign);

        try {
            Subscription::query()
                ->forList((string) $campaign->listHandle)
                ->subscribed()
                ->chunkById((int) config('marketing.sending.chunk', 200), function ($subscriptions) use ($campaign, $contacts, $gate, $variants, $queue, $perMinute, $segmentMemberIds, &$index) {
                    // One question per chunk rather than one per subscriber.
                    // The addresses this returns never become Message rows at
                    // all — a blocked address must not enter a send, not merely
                    // fail to leave one.
                    $suppressed = $gate->suppressedAmong($subscriptions->pluck('email'));

                    foreach ($subscriptions as $subscription) {
                        if (isset($suppressed[(string) $subscription->email_normalized])) {
                            continue;
                        }

                        if ($this->contactOptedOut($contacts, $subscription)) {
                            continue;
                        }

                        // Segment narrows the list. A subscribed member with no
                        // linked contact, or a contact outside the segment, is
                        // excluded — but consent is never granted by the segment.
                        if ($segmentMemberIds !== null
                            && ! isset($segmentMemberIds[(string) $subscription->contact_uuid])) {
                            continue;
                        }

                        // Idempotent: a retried job never double-creates messages.
                        //
                        // The variant is decided HERE, at the snapshot, and
                        // written onto the row — not at render time and not at
                        // send time. Two things keep it from ever moving:
                        // firstOrCreate leaves an existing row's attributes
                        // alone, so a second pass reads the stored variant
                        // rather than writing one; and VariantAssigner is a
                        // pure function of (brand, campaign, subscription
                        // uuid), so even a row that was deleted and rebuilt
                        // comes back in the same bucket.
                        $message = Message::query()->firstOrCreate(
                            [
                                'campaign_handle' => $campaign->handle,
                                'subscription_id' => $subscription->id,
                            ],
                            [
                                'email' => $subscription->email,
                                'status' => Message::STATUS_PENDING,
                                'variant' => $this->variantFor($campaign, $subscription, $variants),
                            ],
                        );

                        if ($message->status !== Message::STATUS_PENDING) {
                            continue;
                        }

                        $job = SendMessageJob::dispatch($message->id)->onQueue($queue);

                        if ($perMinute > 0) {
                            $job->delay(now()->addMinutes(intdiv($index, $perMinute)));
                        }

                        $index++;
                    }
                });
        } catch (SuppressionCheckFailed $e) {
            // The gate could not answer, so the campaign stops.
            //
            // Note the deliberate contrast with resolveSegmentMemberIds() a few
            // lines up, which fails OPEN: a segment it cannot resolve is ignored
            // and the campaign goes to the whole list. That is correct there and
            // wrong here, and the difference is not a style inconsistency. A
            // segment *narrows* an audience — it never grants consent, so losing
            // it can only send to more people who already said yes. Suppression
            // is the opposite kind of check: it is the only thing standing
            // between the send and an address that said no, so losing it must
            // stop everything.
            //
            // Anyone tempted to make these two behave alike should read that
            // paragraph before touching either.
            $campaign->status = Campaign::STATUS_DRAFT;
            $campaigns->save($campaign);

            Log::error(
                "Marketing aborted campaign [{$campaign->handle}] before sending: the suppression gate "
                .'could not be queried, so it is not known which recipients are blocked. The campaign was '
                .'returned to draft rather than sent to everyone. Reason: '.$e->getMessage()
            );

            throw $e;
        }

        // Empty audience: nothing will ever finalize the campaign, so do it here.
        if ($index === 0 && Message::forCampaign($campaign->handle)->pending()->count() === 0) {
            $campaign->status = Campaign::STATUS_SENT;
            $campaign->sentAt = CarbonImmutable::now();
            $campaigns->save($campaign);

            event(new CampaignSent($campaign));
        }
    }

    /**
     * The A/B variant this recipient belongs to, or null when the campaign is
     * not a split test.
     *
     * Keyed on `Subscription.uuid` rather than the email address or the row id.
     * The address is not the recipient's identity here — it can be corrected in
     * place, and rebucketing somebody mid-test because they fixed a typo would
     * corrupt the very thing the test measures. The auto-increment id is worse:
     * it is not preserved by a restore, and it is not brand-scoped.
     *
     * The brand comes from the subscription, which is the same brand the
     * message row is about to be stamped with, so the seed and the row can
     * never disagree about which tenant this is.
     */
    protected function variantFor(Campaign $campaign, Subscription $subscription, VariantAssigner $variants): ?string
    {
        if (! $campaign->hasVariants()) {
            return null;
        }

        return $variants->assign(
            $campaign->handle,
            (string) $subscription->uuid,
            $subscription->brand_id === null ? null : (int) $subscription->brand_id,
        );
    }

    /**
     * Resolve a campaign's segment to a lookup set of contact UUIDs, or null
     * when no segment is configured (send to the whole list).
     *
     * Fail-safe + backward-compatible: if the installed LeadHub predates
     * segments (no `segmentMemberIds` on the facade root), the segment filter
     * is silently ignored — the campaign sends to the whole list, and a single
     * warning is logged. An empty segment yields an empty set (nobody), which
     * is the correct, explicit outcome of a segment that matches no one.
     *
     * @return array<string,true>|null
     */
    protected function resolveSegmentMemberIds(Campaign $campaign): ?array
    {
        $handle = $campaign->segmentHandle;

        if (! $handle) {
            return null;
        }

        // Facades proxy via __callStatic, so method_exists must target the
        // resolved root object, not the facade class.
        $root = LeadHub::getFacadeRoot();

        if (! $root || ! method_exists($root, 'segmentMemberIds')) {
            Log::warning(
                "Marketing campaign [{$campaign->handle}] references segment [{$handle}] but the "
                .'installed LeadHub does not support segments; sending to the whole list instead.'
            );

            return null;
        }

        $ids = (array) LeadHub::segmentMemberIds($handle);

        // Flip to a hash set keyed by uuid for O(1) membership checks.
        return array_fill_keys(array_map('strval', $ids), true);
    }

    /**
     * Is this recipient barred from receiving the campaign?
     *
     * LeadHub's `do_not_contact` is the opt-out source of record, so this check
     * fails CLOSED: it returns true whenever the answer cannot be established.
     *
     * The contact link (`Subscription.contact_uuid`) is only a shortcut, never
     * the identity itself — for email sending the address is the identity. So we
     * resolve by uuid first and fall back to the normalized email (the same
     * fallback SubscriptionService::syncContactOnUnsubscribe() already uses). A
     * missing or stale link therefore no longer exempts anyone from the check.
     *
     * If neither lookup resolves a contact, we do not send. Every path that
     * confirms a subscription (SubscriptionService::markSubscribed() →
     * syncContactOnSubscribe() → LeadHub::ingest()) creates or resolves a
     * contact for the address, so "no contact at all" means the CRM sync failed
     * or the contact was deleted — neither of which may be read as consent.
     */
    protected function contactOptedOut(ContactRepository $contacts, Subscription $subscription): bool
    {
        $contact = $subscription->contact_uuid
            ? $contacts->findByUuid((string) $subscription->contact_uuid)
            : null;

        if (! $contact) {
            $emailNormalized = (string) ($subscription->email_normalized
                ?: EmailNormalizer::normalize((string) $subscription->email));

            $contact = $emailNormalized !== ''
                ? $contacts->findByEmailNormalized($emailNormalized)
                : null;
        }

        if (! $contact) {
            Log::warning(
                'Marketing skipped subscription ['.$subscription->uuid.'] on list ['
                .$subscription->list_handle.']: no LeadHub contact resolvable by uuid or email, '
                .'so the opt-out state cannot be verified (fail-closed).'
            );

            return true;
        }

        return (bool) ($contact->do_not_contact ?? false);
    }
}
