<?php

namespace Goldnead\Marketing\Jobs;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $messageId) {}

    public function handle(
        CampaignRepository $campaigns,
        MailingListRepository $lists,
        CampaignRenderer $renderer,
        SuppressionGate $gate,
        FrequencyCap $cap,
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

        // Defence in depth, and not redundant: StartCampaignJob asked this
        // question when it built the audience, and a campaign snapshotting
        // 20,000 recipients can still be running hours later. Every complaint
        // that arrives in between would otherwise be answered by a queue that
        // stopped listening.
        try {
            if ($gate->isSuppressed($subscription->email)) {
                $message->update([
                    'status' => Message::STATUS_SKIPPED,
                    'error' => 'Suppressed: this address is blocked from every send path.',
                ]);
                $this->maybeFinalize($campaigns, $campaign->handle);

                return;
            }
        } catch (SuppressionCheckFailed $e) {
            // Not knowing is not permission. One message fails; the campaign
            // carries on, because a single unreachable read must not abort a
            // run that is already half delivered.
            $message->update(['status' => Message::STATUS_FAILED, 'error' => $e->getMessage()]);
            $this->maybeFinalize($campaigns, $campaign->handle);

            report($e);

            return;
        }

        // Last of the three gates, and the only one that says "later" rather
        // than "no". In this method the order on the page is: what the reader
        // said they want (`isSubscribed`), then suppression, then this. The
        // specification names suppression first; the two are both hard noes
        // that end the message identically, so which of them answers first
        // changes only the note left in `error`. What is not interchangeable is
        // that the cap comes after both — there is no point deferring a mail to
        // an address that may never receive it at all.
        //
        // Asked HERE, not when the campaign was queued. This job may have sat
        // behind a throttle, a retry or a stopped worker for days; the question
        // is about the window that ends now, not the one that ended when the
        // audience was snapshotted.
        $mailClass = $campaign->mailClass();

        if (! $cap->allows($subscription->email, $mailClass, $message->brand_id === null ? null : (int) $message->brand_id)) {
            $this->hold($message, $cap, $campaigns, $campaign->handle);

            return;
        }

        try {
            $rendered = $renderer->render($campaign, $list, $subscription, $message);

            Mail::mailer(config('marketing.sending.mailer'))
                ->to($subscription->email)
                ->send(new CampaignMail($campaign, $rendered));

            $message->update(['status' => Message::STATUS_SENT, 'sent_at' => now()]);

            // After delivery, never before it. A mail that threw did not reach
            // anybody and may not consume their budget.
            $cap->record(
                $subscription->email,
                $mailClass,
                $message->brand_id === null ? null : (int) $message->brand_id,
                $campaign->handle,
            );

            event(new MessageSent($message->fresh()));
        } catch (Throwable $e) {
            $message->update(['status' => Message::STATUS_FAILED, 'error' => $e->getMessage()]);

            report($e);
        }

        $this->maybeFinalize($campaigns, $campaign->handle);
    }

    /**
     * The recipient is over their cap: push the message back, or give up on it
     * out loud.
     *
     * Deferring rather than discarding is the point of a cap. The mail is not
     * unwanted, there has just been too much of it this week — and a reader who
     * gets the March issue a day late is better served than one who never gets
     * it and cannot be told why.
     *
     * The message stays `pending`, which is load-bearing rather than
     * incidental: `maybeFinalize()` marks a campaign sent once nothing is
     * pending, and a deferred message that had moved to some other status would
     * let a campaign report itself finished while people were still waiting for
     * it.
     *
     * There is an end to it. After `defer.max_deferrals` attempts the message
     * is discarded — and written down. Silent discarding is the version where
     * somebody asks in three months why they never got the March issue and
     * nobody can answer; `status = capped` on the row plus a warning in the log
     * is the version where somebody can.
     *
     * And there is one install where there is no beginning to it either: see
     * the `sync` note below.
     */
    protected function hold(Message $message, FrequencyCap $cap, CampaignRepository $campaigns, string $campaignHandle): void
    {
        $maxDeferrals = max(0, (int) config('marketing.frequency_cap.defer.max_deferrals', 3));
        $retryMinutes = max(1, (int) config('marketing.frequency_cap.defer.retry_after_minutes', 1440));

        // "Later" needs somewhere to happen. On the `sync` connection there is
        // no queue: a dispatch runs inline and a delay is ignored, so pushing
        // the message back would re-enter this method immediately and spend the
        // whole deferral budget inside one request — a day's wait collapsed
        // into microseconds, ending in a discard that reads as if three
        // attempts were made. Better to discard once and say why. The
        // alternative, leaving it pending with nothing to pick it up, stalls
        // the campaign in `sending` for good.
        $canDefer = config('queue.default') !== 'sync';

        if (! $canDefer || (int) $message->cap_deferrals >= $maxDeferrals) {
            $reason = $canDefer
                ? "held back {$maxDeferrals} times and then discarded"
                : 'discarded without being deferred, because this application runs on the `sync` '
                    .'queue connection and there is no later to move it to';

            $message->update([
                'status' => Message::STATUS_CAPPED,
                'cap_deferred_until' => null,
                'error' => "Frequency cap: {$reason}. This address had already received "
                    ."{$cap->limit()} marketing mails in the last {$cap->windowHours()} hours.",
            ]);

            Log::warning(
                "Marketing discarded message [{$message->uuid}] of campaign [{$campaignHandle}] to "
                ."[{$message->email}]: the frequency cap {$reason} "
                .'(limit '.$cap->limit().' marketing mails per '.$cap->windowHours().' hours). '
                .'The recipient never received this campaign.'
            );

            $this->maybeFinalize($campaigns, $campaignHandle);

            return;
        }

        $message->update([
            'cap_deferrals' => (int) $message->cap_deferrals + 1,
            'cap_deferred_until' => now()->addMinutes($retryMinutes),
        ]);

        static::dispatch($message->id)
            ->onQueue(config('marketing.sending.queue', 'default'))
            ->delay(now()->addMinutes($retryMinutes));
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
