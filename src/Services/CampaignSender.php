<?php

namespace Goldnead\Marketing\Services;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Subscription;
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

    /** Send a rendered test to one address without touching messages/stats. */
    public function sendTest(Campaign $campaign, string $email): void
    {
        $list = $campaign->listHandle ? $this->lists->find($campaign->listHandle) : null;

        if (! $list) {
            throw new InvalidArgumentException("Campaign [{$campaign->handle}] has no valid mailing list.");
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
