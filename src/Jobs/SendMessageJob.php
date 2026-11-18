<?php

namespace Goldnead\Marketing\Jobs;

use Carbon\CarbonImmutable;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Marketing\Services\AbTest;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Support\SendWindow;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  int  $transportAttempt  Which handover to the transport this is.
     *                                 Counted here and not on the row: only a
     *                                 failed handover is retried, and the job
     *                                 that retries it is the one that knows.
     */
    public function __construct(public int $messageId, public int $transportAttempt = 1) {}

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

        // The opt-out of record, asked again for the same reason as the
        // suppression gate above: StartCampaignJob checked `do_not_contact`
        // when it built the audience, and somebody who opts out while a large
        // campaign is still running must not get the rest of it. SingleSend
        // asks the same question in the same way. Fail closed: no contact to
        // ask is not consent.
        if ($this->contactOptedOut($subscription)) {
            $message->update([
                'status' => Message::STATUS_SKIPPED,
                'error' => 'Opted out: the LeadHub contact says do_not_contact, or no contact could be found to ask.',
            ]);
            $this->maybeFinalize($campaigns, $campaign->handle);

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

        // After the three gates and before the send: a window says "later", and
        // there is no point deferring a mail to somebody who may never receive
        // it at all — the same reasoning that puts the frequency cap last.
        //
        // Deferred to the exact moment the window opens rather than retried
        // hourly. A retry loop would spend the deferral budget the cap owns and
        // discard the message before the recipient's morning ever arrived.
        $oeffnet = app(SendWindow::class)->nextOpening($subscription);

        // The `sync` check belongs HERE, not inside waitForWindow(), and the
        // first attempt had it there — where the early return meant the message
        // was neither deferred nor sent. It simply vanished, on exactly the
        // installation with no worker to notice. Caught by the test written for
        // the opposite case.
        if ($oeffnet !== null && config('queue.default') !== 'sync') {
            $this->waitForWindow($message, $oeffnet, $campaigns, $campaign->handle);

            return;
        }

        try {
            $rendered = $renderer->render($campaign, $list, $subscription, $message);
        } catch (Throwable $e) {
            // Rendering is deterministic: the same campaign fails the same way
            // on every attempt, so this one ends here.
            $message->update(['status' => Message::STATUS_FAILED, 'sent_at' => null, 'error' => $e->getMessage()]);

            report($e);

            $this->maybeFinalize($campaigns, $campaign->handle);

            return;
        }

        try {
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
        } catch (TransportExceptionInterface $e) {
            // The transport did not take it. That is usually passing (a
            // timeout, a refused connection, a rate limit), so the message
            // goes back and is tried again later instead of ending as failed
            // on the first hiccup.
            $this->transportFailed($message, $e, $campaigns, $campaign->handle);

            return;
        } catch (Throwable $e) {
            // Anything else is not a transport hiccup but a fault (a broken
            // mailable, a bug). Retrying repeats it, and may repeat a mail
            // that was already accepted, so it ends here.
            $message->update(['status' => Message::STATUS_FAILED, 'sent_at' => null, 'error' => $e->getMessage()]);

            report($e);

            $this->maybeFinalize($campaigns, $campaign->handle);

            return;
        }

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

        // From here on the mail is out. Nothing below may turn that into
        // `failed`, and nothing may throw back into handle(), whose catch
        // would release the row and let the retry send a second copy. Each
        // step is reported on its own instead.
        //
        // If even the status write fails, the row stays `sending` with
        // `sent_at` stamped, which is exactly what the sweeper reads as
        // "handed over, do not send again".
        $this->afterSend('status write', $message, fn () => $message->update(['status' => Message::STATUS_SENT, 'sent_at' => now()]));

        // After delivery, never before it. A mail that threw did not reach
        // anybody and may not consume their budget.
        $this->afterSend('frequency cap record', $message, fn () => $cap->record(
            $subscription->email,
            $mailClass,
            $message->brand_id === null ? null : (int) $message->brand_id,
            $campaign->handle,
        ));

        $this->afterSend('MessageSent event', $message, fn () => event(new MessageSent($message->fresh())));

        $this->afterSend('campaign finalisation', $message, fn () => $this->maybeFinalize($campaigns, $campaign->handle));
    }

    /** One bookkeeping step after a mail has already left. */
    protected function afterSend(string $step, Message $message, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);

            Log::error("Marketing sent message [{$message->uuid}], but the step [{$step}] after the send failed. The message stays sent.", [
                'step' => $step,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The transport threw: try again later, or give up out loud.
     *
     * Back to `pending` with the claim released, so the campaign keeps
     * counting the message as outstanding and the redelivery can claim it
     * again. After `sending.transport_attempts` handovers the message ends as
     * `failed`, and the campaign reports how many did when it finishes.
     *
     * On the `sync` connection there is no later (see hold()), so the message
     * fails at once rather than retrying inside the same request.
     */
    protected function transportFailed(Message $message, TransportExceptionInterface $e, CampaignRepository $campaigns, string $campaignHandle): void
    {
        report($e);

        $maxAttempts = max(1, (int) config('marketing.sending.transport_attempts', 3));
        $canRetry = config('queue.default') !== 'sync' && $this->transportAttempt < $maxAttempts;

        if (! $canRetry) {
            // sent_at back to null: the stamp is a claim that the transport
            // took it, and a throw says it did not.
            $message->update([
                'status' => Message::STATUS_FAILED,
                'sent_at' => null,
                'error' => "Transport failed on attempt {$this->transportAttempt} of {$maxAttempts}: ".$e->getMessage(),
            ]);

            $this->maybeFinalize($campaigns, $campaignHandle);

            return;
        }

        $backoff = (array) config('marketing.sending.transport_backoff_minutes', [1, 5, 15]);
        $minutes = max(1, (int) ($backoff[$this->transportAttempt - 1] ?? end($backoff) ?: 15));

        $message->update([
            'status' => Message::STATUS_PENDING,
            'claimed_at' => null,
            'sent_at' => null,
            'error' => "Transport failed on attempt {$this->transportAttempt} of {$maxAttempts}, retrying in {$minutes} min: ".$e->getMessage(),
        ]);

        Log::warning("Marketing could not hand message [{$message->uuid}] of campaign [{$campaignHandle}] to the transport "
            ."(attempt {$this->transportAttempt} of {$maxAttempts}); retrying in {$minutes} minutes.", [
                'exception' => $e->getMessage(),
            ]);

        static::dispatch($message->id, $this->transportAttempt + 1)
            ->onQueue(config('marketing.sending.queue', 'default'))
            ->delay(now()->addMinutes($minutes));
    }

    /**
     * Has the person opted out in LeadHub since the audience was built?
     *
     * The same lookup as StartCampaignJob and SingleSend: by contact uuid
     * first, then by normalised address, and true when neither resolves a
     * contact, because an opt-out that cannot be read may not be read as
     * consent.
     */
    protected function contactOptedOut(Subscription $subscription): bool
    {
        $contacts = app(ContactRepository::class);

        $contact = $subscription->contact_uuid
            ? $contacts->findByUuid((string) $subscription->contact_uuid)
            : null;

        if (! $contact) {
            $normalized = (string) ($subscription->email_normalized
                ?: EmailNormalizer::normalize((string) $subscription->email));

            $contact = $normalized !== ''
                ? $contacts->findByEmailNormalized($normalized)
                : null;
        }

        if (! $contact) {
            Log::warning(
                'Marketing skipped a campaign message to subscription ['.$subscription->uuid.'] on list ['
                .$subscription->list_handle.']: no LeadHub contact resolvable by uuid or email, '
                .'so the opt-out state cannot be verified (fail-closed).'
            );

            return true;
        }

        return (bool) ($contact->do_not_contact ?? false);
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

    /**
     * Put the message back until the recipient's window opens.
     *
     * Back to `pending` and the claim released, exactly like the cap's
     * deferral: `maybeFinalize()` marks a campaign sent once nothing is
     * pending, and a message waiting for tomorrow morning that had stopped
     * counting would let the campaign report itself finished while somebody was
     * still waiting for it.
     *
     * It does NOT spend a cap deferral. The two are different questions — "too
     * much mail this week" and "the wrong hour of the day" — and charging the
     * second against the first would discard a message after three nights
     * simply for having been queued in the evening.
     *
     * The caller decides whether to come here at all: on the `sync` connection
     * there is no queue, a delay is ignored, and the window is skipped so the
     * message goes out. A single-process install has no worker to come back
     * later, and a message that silently never sends is worse than one that
     * arrives at an odd hour.
     */
    protected function waitForWindow(
        Message $message,
        CarbonImmutable $oeffnet,
        CampaignRepository $campaigns,
        string $campaignHandle,
    ): void {
        $message->update([
            'status' => Message::STATUS_PENDING,
            'claimed_at' => null,
        ]);

        static::dispatch($message->id)
            ->onQueue(config('marketing.sending.queue', 'default'))
            ->delay($oeffnet);

        $this->maybeFinalize($campaigns, $campaignHandle);
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

        // The test pass of an A/B test is not the campaign: the winner still
        // has to go to the rest. Note that the test is resolved and stop.
        $ab = app(AbTest::class);

        if ($ab->isTesting($campaign)) {
            $ab->testFinished($campaigns, $campaign);

            return;
        }

        // Nor is the campaign done while the winner pass is still writing its
        // audience: the first messages can all be resolved before the later
        // chunks exist. The pass closes the campaign itself when it ends.
        if ($ab->winnerPassRunning($campaign)) {
            return;
        }

        // "Sent" means nothing is outstanding, not that everything arrived.
        // The failures are counted and said out loud, so a run where the
        // transport refused thousands of mails cannot pass for a clean one.
        // Over the threshold the campaign carries `meta.delivery_degraded`,
        // so the state is on the campaign itself and not only in a log.
        $failed = Message::forCampaign($campaignHandle)->where('status', Message::STATUS_FAILED)->count();
        $total = Message::forCampaign($campaignHandle)->count();
        $threshold = max(0.0, (float) config('marketing.sending.degraded_failure_percent', 5));
        $degraded = $failed > 0 && $total > 0 && ($failed / $total * 100) >= $threshold;

        $campaign->status = Campaign::STATUS_SENT;
        $campaign->sentAt = CarbonImmutable::now();

        if ($degraded) {
            $campaign->meta['delivery_degraded'] = [
                'failed' => $failed,
                'messages' => $total,
                'threshold_percent' => $threshold,
                'at' => CarbonImmutable::now()->toIso8601String(),
            ];
        }

        $campaigns->save($campaign);

        $line = "Marketing finished campaign [{$campaignHandle}] with {$failed} of {$total} messages failed. "
            .'The error column of each failed message says why.';

        if ($degraded) {
            Log::error($line." Delivery is degraded: at or over the threshold of {$threshold} %.");
        } elseif ($failed > 0) {
            Log::warning($line);
        }

        event(new CampaignSent($campaign, ['failed' => $failed, 'messages' => $total, 'delivery_degraded' => $degraded]));
    }
}
