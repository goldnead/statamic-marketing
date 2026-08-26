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
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $messageId) {}

    /**
     * Hand the claim back when this job gives up for good.
     *
     * Found by review: between the claim and the first status write sit four
     * lookups that can throw outside any try — the campaign, the list, the
     * suppression gate, the frequency cap. An exception there used to leave the
     * row in `sending` with nobody able to take it: the retry cannot re-claim
     * it, a fresh campaign run skips it, and the only way back was the sweeper
     * fifteen minutes later.
     *
     * Before the claim existed the row simply stayed `pending` and the retry
     * picked it up. This restores that, which matters more than it looks:
     * losing a mail is the worse half of this bug, and a claim without a way
     * back is how you lose one.
     */
    public function failed(Throwable $e): void
    {
        Message::release($this->messageId);
    }

    public function handle(
        CampaignRepository $campaigns,
        MailingListRepository $lists,
        CampaignRenderer $renderer,
        SuppressionGate $gate,
        FrequencyCap $cap,
    ): void {
        // Claimed, not asked. The old form read the status, then sent, then
        // wrote it — and two workers reading before either wrote both sent the
        // same mail to the same address. Reproduced, not suspected: one row,
        // two mails, and the row afterwards says `sent` once, so the data does
        // not record that it happened.
        //
        // It needs no second worker either. A worker killed between the send
        // and the write leaves the row `pending`, and the retry sends again.
        //
        // Everything below assumes it is the only one here, which is now true.
        if (! Message::claim($this->messageId)) {
            return;
        }

        $message = Message::query()->with('subscription')->find($this->messageId);

        if (! $message) {
            return;
        }

        // Everything from here to the first status write runs inside a guard.
        // The lookups below can throw — a repository that cannot reach its
        // store, a suppression backend that is down — and a throw between the
        // claim and any write would strand the row. Releasing before rethrowing
        // means the retry finds it exactly as it was.
        try {
            $this->deliver($message, $campaigns, $lists, $renderer, $gate, $cap);
        } catch (Throwable $e) {
            Message::release($this->messageId);

            throw $e;
        }
    }

    protected function deliver(
        Message $message,
        CampaignRepository $campaigns,
        MailingListRepository $lists,
        CampaignRenderer $renderer,
        SuppressionGate $gate,
        FrequencyCap $cap,
    ): void {
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

            // As the brand the message belongs to — its transport, its From.
            // `$message->brand_id` and not the brand in context: a queue worker
            // has no request behind it, and the row is where the answer was
            // written when the audience was snapshotted.
            //
            // Resolved from the container here rather than injected into
            // `handle()`, so the signature every host and test already calls
            // stays as it is.
            // Stamped BEFORE the handover, not after. If the worker is killed
            // inside the transport call, this is the only trace that the mail
            // may already be on its way — and the sweeper reads it instead of
            // sending a second copy. See ReleaseStaleSendsCommand.
            $message->update(['sent_at' => now()]);

            $sent = app(BrandMailer::class)->send(
                $message->brand_id === null ? null : (int) $message->brand_id,
                (string) $subscription->email,
                null,
                new CampaignMail($campaign, $rendered),
            );

            // A brand that declared a mail identity and then broke it half —
            // a transport with no address, a mailer name `config/mail.php` does
            // not define — sends nothing at all rather than under the host's
            // own name. `false` is that answer, and it is not an exception
            // because a fan-out has to keep going for the brands that are fine.
            //
            // Recorded as failed for the same reason a throw is: this recipient
            // did not get the mail, so the row must not claim they did and the
            // frequency cap below must not spend their budget. The reason is
            // already in the log, once per brand per window; repeating it on
            // every row of a fan-out would put it in the database too.
            if (! $sent) {
                $message->update([
                    'status' => Message::STATUS_FAILED,
                    'sent_at' => null,
                    'error' => 'No sender identity for this brand — see the log for the reason.',
                ]);

                $this->maybeFinalize($campaigns, $campaign->handle);

                return;
            }

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
            // sent_at back to null: the stamp is a claim that the transport
            // took it, and a throw says it did not.
            $message->update(['status' => Message::STATUS_FAILED, 'sent_at' => null, 'error' => $e->getMessage()]);

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

        // Back to `pending`, and the claim let go with it. The docblock above
        // says the pending status is load-bearing for finalisation; it is just
        // as load-bearing that the redelivery in a day's time can claim the row
        // again, which a row still marked `sending` could not.
        $message->update([
            'status' => Message::STATUS_PENDING,
            'claimed_at' => null,
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
