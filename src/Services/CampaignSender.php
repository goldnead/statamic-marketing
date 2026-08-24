<?php

namespace Goldnead\Marketing\Services;

use Carbon\CarbonImmutable;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\BrandMailer;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CampaignSender
{
    public function __construct(
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
        protected CampaignRenderer $renderer,
        protected BrandMailer $mailer,
        protected ContactRepository $contacts,
    ) {}

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
     * **Not gated like the real thing, and it should not be.** The docblock
     * here used to claim it was, which is the more dangerous half of the
     * problem: whoever reads that sentence stops checking.
     *
     * What runs, and why:
     *
     * - **Suppression list** — yes. A bounce or a complaint means never again,
     *   whoever is asking and for whatever reason.
     * - **`do_not_contact` on the contact** — yes, since 2026-08-24. It is what
     *   an unsubscribe sets, and what an editor sets by hand in the CRM. Both
     *   mean "never", not "not subscribed". Sending a test "just to have a
     *   quick look" to a customer who unsubscribed is the case this exists to
     *   prevent, and it is the naming-obvious one.
     * - **Subscription status and consent** — no. A test send goes to an
     *   address the editor types in, usually their own, which is by definition
     *   not on the list. Requiring a subscription would break the button for
     *   its ordinary use.
     * - **Frequency cap** — no. A test is not a campaign and must not eat into
     *   a recipient's budget, nor be refused because a campaign already did.
     *
     * Blocks throw rather than return false: a campaign send drops a blocked
     * recipient silently because there are thousands and nobody is watching; a
     * test send has an audience of one, standing at the screen. Failing
     * silently there teaches the editor that the button is broken.
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

        // The second "never" flag. The suppression table holds what a provider
        // reported; this holds what a person decided — an unsubscribe with
        // global opt-out on, or an editor setting it in the CRM. Neither
        // implies the other, so both are asked.
        if ($this->contactSaysNever($email)) {
            throw new InvalidArgumentException(
                "[{$email}] is marked do-not-contact in the CRM and cannot be mailed, not even as a ".
                'test. Someone unsubscribed this address or set the flag by hand; clear it there if '.
                'that was a mistake.'
            );
        }

        /*
         * An unsaved subscription gives the renderer realistic variables and a
         * syntactically valid unsubscribe URL that leads nowhere (404).
         *
         * Inert on purpose, and it stays that way. The link proves the layout
         * puts it where it belongs, which is what a test is for. A *working*
         * one would be the more dangerous thing: the person clicking around in
         * their own test mail would unsubscribe a real address, and the address
         * in a test send is often a colleague's.
         */
        $subscription = new Subscription([
            'list_handle' => $list->handle,
            'email' => $email,
            'first_name' => 'Test',
        ]);
        $subscription->token = 'test-preview';

        $rendered = $this->renderer->render($campaign, $list, $subscription);
        $rendered->subject = '[Test] '.$rendered->subject;

        // The brand in context, which for a test send from the CP is the brand
        // whose campaign the editor has open. A test mail that arrives from
        // another brand's address tests the wrong thing.
        //
        // Thrown rather than logged, unlike everywhere else this returns false:
        // there is a person waiting for an answer in the Control Panel, and a
        // test send that reports success while nothing left is worse than no
        // test send. The sentence names the setting, because the person who can
        // fix it is the one who just clicked the button.
        if (! $this->mailer->send(null, $email, null, new CampaignMail($campaign, $rendered))) {
            throw new InvalidArgumentException(
                'This brand has no usable sender identity, so nothing was sent. Check '
                .'settings.mail.from_address and settings.mail.mailer on the brand — the log has the '
                .'exact reason.'
            );
        }
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

    /**
     * Has anyone said "never contact this address"?
     *
     * Read by normalised address rather than by subscription, because a test
     * send has no subscription — the whole point is that it goes to an address
     * that need not be on any list.
     */
    protected function contactSaysNever(string $email): bool
    {
        $contact = $this->contacts->findByEmailNormalized(Str::lower(trim($email)));

        return (bool) $contact?->do_not_contact;
    }
}
