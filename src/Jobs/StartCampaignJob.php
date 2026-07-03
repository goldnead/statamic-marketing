<?php

namespace Goldnead\Marketing\Jobs;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\CampaignSending;
use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
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

    public function __construct(public string $campaignHandle)
    {
    }

    public function handle(CampaignRepository $campaigns, ContactRepository $contacts): void
    {
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

        Subscription::query()
            ->forList((string) $campaign->listHandle)
            ->subscribed()
            ->chunkById((int) config('marketing.sending.chunk', 200), function ($subscriptions) use ($campaign, $contacts, $queue, $perMinute, $segmentMemberIds, &$index) {
                foreach ($subscriptions as $subscription) {
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
                    $message = Message::query()->firstOrCreate(
                        [
                            'campaign_handle' => $campaign->handle,
                            'subscription_id' => $subscription->id,
                        ],
                        [
                            'email' => $subscription->email,
                            'status' => Message::STATUS_PENDING,
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

        // Empty audience: nothing will ever finalize the campaign, so do it here.
        if ($index === 0 && Message::forCampaign($campaign->handle)->pending()->count() === 0) {
            $campaign->status = Campaign::STATUS_SENT;
            $campaign->sentAt = \Carbon\CarbonImmutable::now();
            $campaigns->save($campaign);

            event(new CampaignSent($campaign));
        }
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

    protected function contactOptedOut(ContactRepository $contacts, Subscription $subscription): bool
    {
        if (! $subscription->contact_uuid) {
            return false;
        }

        $contact = $contacts->find($subscription->contact_uuid);

        return (bool) ($contact?->do_not_contact ?? false);
    }
}
