<?php

namespace Goldnead\Marketing\Jobs;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $messageId)
    {
    }

    public function handle(
        CampaignRepository $campaigns,
        MailingListRepository $lists,
        CampaignRenderer $renderer,
    ): void {
        $message = Message::query()->with('subscription')->find($this->messageId);

        if (! $message || $message->status !== Message::STATUS_PENDING) {
            return;
        }

        $campaign = $campaigns->find($message->campaign_handle);
        $subscription = $message->subscription;
        $list = $campaign?->listHandle ? $lists->find($campaign->listHandle) : null;

        if (! $campaign || ! $list || ! $subscription) {
            $message->update(['status' => Message::STATUS_FAILED, 'error' => 'Campaign, list or subscription no longer exists.']);
            $this->maybeFinalize($campaigns, $message->campaign_handle);

            return;
        }

        // The subscriber may have unsubscribed (or bounced) between snapshot
        // and delivery — never send to them.
        if (! $subscription->isSubscribed()) {
            $message->update(['status' => Message::STATUS_SKIPPED]);
            $this->maybeFinalize($campaigns, $campaign->handle);

            return;
        }

        try {
            $rendered = $renderer->render($campaign, $list, $subscription, $message);

            Mail::mailer(config('marketing.sending.mailer'))
                ->to($subscription->email)
                ->send(new CampaignMail($campaign, $rendered));

            $message->update(['status' => Message::STATUS_SENT, 'sent_at' => now()]);

            event(new MessageSent($message->fresh()));
        } catch (Throwable $e) {
            $message->update(['status' => Message::STATUS_FAILED, 'error' => $e->getMessage()]);

            report($e);
        }

        $this->maybeFinalize($campaigns, $campaign->handle);
    }

    /** The job that resolves the last pending message marks the campaign sent. */
    protected function maybeFinalize(CampaignRepository $campaigns, string $campaignHandle): void
    {
        if (Message::forCampaign($campaignHandle)->pending()->exists()) {
            return;
        }

        $campaign = $campaigns->find($campaignHandle);

        if (! $campaign || $campaign->status !== Campaign::STATUS_SENDING) {
            return;
        }

        $campaign->status = Campaign::STATUS_SENT;
        $campaign->sentAt = CarbonImmutable::now();
        $campaigns->save($campaign);

        event(new CampaignSent($campaign));
    }
}
