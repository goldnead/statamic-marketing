<?php

namespace Goldnead\Marketing\Services;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class CampaignSender
{
    public function __construct(
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
        protected CampaignRenderer $renderer,
    ) {
    }

    /** Queue a campaign for immediate delivery. */
    public function queue(Campaign $campaign): Campaign
    {
        if (! $campaign->isSendable()) {
            throw new InvalidArgumentException("Campaign [{$campaign->handle}] is not in a sendable state ({$campaign->status}).");
        }

        $this->assertComplete($campaign);

        $campaign->status = Campaign::STATUS_SENDING;
        $campaign->scheduledAt = null;
        $this->campaigns->save($campaign);

        StartCampaignJob::dispatch($campaign->handle)
            ->onQueue(config('marketing.sending.queue', 'default'));

        return $campaign;
    }

    /** Schedule a campaign; marketing:send-scheduled picks it up when due. */
    public function schedule(Campaign $campaign, CarbonImmutable $at): Campaign
    {
        if (! $campaign->isSendable()) {
            throw new InvalidArgumentException("Campaign [{$campaign->handle}] is not in a schedulable state ({$campaign->status}).");
        }

        $this->assertComplete($campaign);

        $campaign->status = Campaign::STATUS_SCHEDULED;
        $campaign->scheduledAt = $at;
        $this->campaigns->save($campaign);

        return $campaign;
    }

    /** Revert a scheduled campaign back to draft. */
    public function unschedule(Campaign $campaign): Campaign
    {
        if ($campaign->isScheduled()) {
            $campaign->status = Campaign::STATUS_DRAFT;
            $campaign->scheduledAt = null;
            $this->campaigns->save($campaign);
        }

        return $campaign;
    }

    /**
     * Send a rendered test to one address without touching messages/stats.
     *
     * Gated like the real thing, but it says so out loud instead of skipping
     * quietly. A campaign send drops a blocked recipient silently because there
     * are thousands of them and the editor is not watching; a test send has an
     * audience of one, standing at the screen, waiting for a mail that will
     * never arrive. Failing silently there teaches the editor that the test
     * button is broken.
     */
    public function sendTest(Campaign $campaign, string $email): void
    {
        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;

        if (! $list) {
            throw new InvalidArgumentException("Campaign [{$campaign->handle}] has no valid mailing list.");
        }

        try {
            if (app(SuppressionGate::class)->isSuppressed($email)) {
                throw new InvalidArgumentException(
                    "[{$email}] is on the suppression list and cannot be mailed, not even as a test. ".
                    'Release the suppression first if this address should receive mail again.'
                );
            }
        } catch (SuppressionCheckFailed $e) {
            throw new InvalidArgumentException(
                'The suppression list could not be checked, so no test was sent. '.$e->getMessage(),
                0,
                $e
            );
        }

        // An unsaved subscription gives the renderer realistic variables and
        // a syntactically valid (but inert) unsubscribe URL.
        $subscription = new Subscription([
            'list_handle' => $list->handle,
            'email' => $email,
            'first_name' => 'Test',
        ]);
        $subscription->token = 'test-preview';

        $rendered = $this->renderer->render($campaign, $list, $subscription);
        $rendered->subject = '[Test] '.$rendered->subject;

        Mail::mailer(config('marketing.sending.mailer'))
            ->to($email)
            ->send(new CampaignMail($campaign, $rendered));
    }

    protected function assertComplete(Campaign $campaign): void
    {
        if (! $campaign->subject) {
            throw new InvalidArgumentException("Campaign [{$campaign->handle}] has no subject.");
        }

        if (! $campaign->listHandle || ! $this->lists->find($campaign->listHandle)) {
            throw new InvalidArgumentException("Campaign [{$campaign->handle}] has no valid mailing list.");
        }
    }
}
