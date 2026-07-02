<?php

namespace Goldnead\Marketing\Jobs;

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
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

        Subscription::query()
            ->forList((string) $campaign->listHandle)
            ->subscribed()
            ->chunkById((int) config('marketing.sending.chunk', 200), function ($subscriptions) use ($campaign, $contacts, $queue, $perMinute, &$index) {
                foreach ($subscriptions as $subscription) {
                    if ($this->contactOptedOut($contacts, $subscription)) {
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

    protected function contactOptedOut(ContactRepository $contacts, Subscription $subscription): bool
    {
        if (! $subscription->contact_uuid) {
            return false;
        }

        $contact = $contacts->find($subscription->contact_uuid);

        return (bool) ($contact?->do_not_contact ?? false);
    }
}
