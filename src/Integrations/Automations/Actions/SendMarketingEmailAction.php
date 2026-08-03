<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Sending\SingleSendResult;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;
use Illuminate\Support\Facades\Log;

/**
 * Send one marketing mail to the person this run is about.
 *
 * This is the node a sequence is built out of, and it lives in `marketing`
 * rather than in `automations` on purpose. `automations` orchestrates; it must
 * never learn what a newsletter is, what consent means, or that a frequency
 * cap exists. Everything this node knows that its neighbours do not is
 * marketing's own domain, so marketing contributes the node.
 *
 * **Not `automations`' own `send_email`.** That one is domain-neutral and
 * stays that way: an address, a subject, a body, `Mail::raw()`. It asks nobody
 * whether the recipient wants marketing mail, because it is also how a site
 * sends a password reset. This node goes through
 * {@see SingleSend} and therefore through consent,
 * suppression, opt-out and the cap — in that order.
 *
 * **Not `ThrottleNode`, either.** That node drosselt one flow: "at most one run
 * per key per window". The frequency cap counts a *person's* marketing mail
 * across every flow, every campaign and every broadcast in the same brand.
 * Two sequences that each throttle themselves correctly still add up to six
 * mails a week for somebody who is in both. Only a node on the marketing send
 * path can see that, which is the whole argument for this file existing.
 *
 * **What happens when a gate says no.**
 *
 *  - *Blocked* (not subscribed, suppressed, opted out) stops the run. Not
 *    "skip this mail and carry on to the next one": every later step of a
 *    marketing sequence is more marketing mail, and continuing past a person
 *    who may not be mailed is exactly the thing consent forbids. The flow ends
 *    here for them, visibly, with the reason in the run log.
 *  - *Deferred* (frequency cap) pauses the run and re-runs this node later,
 *    which is what {@see SendMessageJob} does for a
 *    campaign. A mail held back is not an unwanted mail; there has just been
 *    too much of it this week.
 *  - *Out of deferrals* sends nothing, continues the flow, and writes a
 *    warning naming the recipient and the campaign. Silent discarding is the
 *    version where somebody asks in three months why the third mail never
 *    arrived and nobody can answer.
 */
class SendMarketingEmailAction implements AutomationAction
{
    /**
     * Where in the run context this node keeps its per-recipient deferral
     * count. Keyed by campaign + address rather than by node, because
     * `execute()` is not told which node it is — and because the pair is what
     * the count is actually about.
     */
    public const DEFERRAL_KEY = '_marketing_send_deferrals';

    public function __construct(
        protected SingleSend $sender,
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
    ) {}

    public static function handle(): string
    {
        return 'marketing.send_email';
    }

    public static function label(): string
    {
        return 'Send Marketing Email';
    }

    public static function description(): ?string
    {
        return 'Sends one campaign to the contact in this run through the marketing send path — list consent, suppression, opt-out and the frequency cap, in that order.';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    /**
     * Re-evaluate this node when a paused run resumes.
     *
     * The runner skips past a node it resumes *after*; this node must be asked
     * again, because the only reason it ever pauses is a frequency cap, and the
     * whole point of waiting is to ask the cap a second time.
     */
    public static function reexecuteOnResume(): bool
    {
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            [
                'handle' => 'campaign',
                'label' => 'Campaign',
                'type' => 'select',
                'options' => static::campaignOptions(),
                'options_source' => 'marketing.campaigns',
                'required' => true,
                'help' => 'The campaign whose subject, content and template make up this mail. Author it in Marketing → Campaigns and leave it in draft — a sequence never queues it to the whole list.',
            ],
            [
                'handle' => 'list',
                'label' => 'Mailing list',
                'type' => 'select',
                'options' => static::listOptions(),
                'options_source' => 'marketing.lists',
                'required' => false,
                'help' => "Where consent for this mail comes from. Leave empty to use the campaign's own list. A recipient without a subscribed subscription on this list is not mailed.",
            ],
            [
                'handle' => 'to',
                'label' => 'Recipient',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Leave empty to use the address the run is already about ({{ subscriber.email }}, {{ contact.email }} or {{ email }}).',
            ],
        ];
    }

    /**
     * This node is a mail, so it appears as a row in the automations mail list.
     *
     * The opt-in is what keeps the orchestrator domain-neutral: it renders "a
     * node that says it sends a mail", and never learns that a campaign exists.
     * Both methods are found with `method_exists` on the other side, so an
     * older automations release simply does not ask.
     */
    public static function mailStep(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{label: string, reference: string|null}
     */
    public static function mailSummary(array $config): array
    {
        $handle = is_string($config['campaign'] ?? null) ? trim((string) $config['campaign']) : '';

        if ($handle === '') {
            return ['label' => '', 'reference' => null];
        }

        // The subject, because that is what an editor recognises a mail by. A
        // handle that no longer resolves still names itself rather than
        // producing a blank row.
        try {
            $campaign = app(CampaignRepository::class)->find($handle);
        } catch (\Throwable) {
            $campaign = null;
        }

        return [
            'label' => (string) ($campaign?->subject ?: $campaign?->name ?: $handle),
            'reference' => $handle,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'sent' => 'boolean',
            'campaign' => 'string',
            'list' => 'string',
            'email' => 'string',
            'message_uuid' => 'string',
            'reason' => 'string',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $campaignHandle = trim((string) ($config['campaign'] ?? ''));

        // Static configuration, validated before the test-mode short-circuit:
        // a node with no campaign is a broken node and a test run exists to
        // say so. See ActionResult::missingDataReference() for the rule.
        if ($campaignHandle === '') {
            return ActionResult::failed('marketing.send_email requires a "campaign".');
        }

        $campaign = $this->campaigns->find($campaignHandle);

        if ($campaign === null) {
            return ActionResult::failed("Campaign [{$campaignHandle}] does not exist.");
        }

        $listHandle = trim((string) ($config['list'] ?? '')) ?: (string) $campaign->listHandle;
        $list = $listHandle !== '' ? $this->lists->find($listHandle) : null;

        if ($list === null) {
            return ActionResult::failed(
                "Campaign [{$campaignHandle}] has no mailing list to take consent from."
            );
        }

        $email = $this->recipient($context, $config);

        // The orchestrator's own switch, not a second one of ours. A test run
        // that may send real mail is a decision about test runs, and it is
        // already made once in `automations.test_mode.send_real_emails`.
        if ($context->isTestMode() && ! config('automations.test_mode.send_real_emails', false)) {
            return ActionResult::success([
                'preview' => [
                    'campaign' => $campaign->handle,
                    'subject' => $campaign->subject,
                    'list' => $list->handle,
                    'to' => $email,
                    'mail_class' => $campaign->mailClass()->value,
                ],
                'note' => 'Test mode — nothing was sent and no gate was consulted.',
            ]);
        }

        // A data reference, so it is validated after the test-mode branch: a
        // test run starts from an empty context by design and every address
        // token resolves to nothing there.
        if ($email === '') {
            return ActionResult::missingDataReference('to', 'Recipient', '{{ subscriber.email }}');
        }

        $subscription = Subscription::query()
            ->forList($list->handle)
            ->where('email_normalized', $this->normalize($email))
            ->first();

        if ($subscription === null) {
            return ActionResult::stopped(
                "[{$email}] is not on list [{$list->handle}], so there is no consent to send under."
            );
        }

        $result = $this->sender->send($campaign, $list, $subscription);

        return match (true) {
            $result->wasSent() => ActionResult::success([
                'sent' => true,
                'campaign' => $campaign->handle,
                'list' => $list->handle,
                'email' => $email,
                'message_uuid' => (string) $result->message?->uuid,
            ]),
            $result->isDeferred() => $this->holdOrGiveUp($context, $campaign->handle, $email, $result),
            $result->isFailed() => ActionResult::failed(
                (string) $result->error,
                ['campaign' => $campaign->handle, 'email' => $email, 'reason' => $result->reason],
            ),
            default => ActionResult::stopped($this->blockedReason($result->reason, $email)),
        };
    }

    /**
     * The cap said later. Pause the run — up to a point.
     *
     * The budget is `marketing.frequency_cap.defer.max_deferrals`, the same one
     * the campaign path spends, so a reader is held back for the same length of
     * time whether the mail came from a broadcast or from a sequence.
     */
    protected function holdOrGiveUp(
        AutomationContext $context,
        string $campaignHandle,
        string $email,
        SingleSendResult $result,
    ): ActionResult {
        $max = max(0, (int) config('marketing.frequency_cap.defer.max_deferrals', 3));
        $key = static::DEFERRAL_KEY.'.'.sha1($campaignHandle.'|'.$this->normalize($email));
        $spent = (int) $context->get($key, 0);

        if ($spent >= $max) {
            Log::warning(
                "Marketing did not send campaign [{$campaignHandle}] to [{$email}] from an automation: "
                ."the frequency cap held it back {$max} times and the mail was then discarded. "
                .'The flow continued without it; the recipient never received this mail.'
            );

            return ActionResult::success([
                'sent' => false,
                'campaign' => $campaignHandle,
                'email' => $email,
                'reason' => 'frequency_cap_discarded',
            ]);
        }

        $context->set($key, $spent + 1);

        $minutes = max(1, (int) ($result->retryAfterMinutes ?? 1440));

        return ActionResult::wait(
            ['seconds' => $minutes * 60],
            [
                'sent' => false,
                'campaign' => $campaignHandle,
                'email' => $email,
                'reason' => 'frequency_cap',
                'retry_after_minutes' => $minutes,
                'deferral' => $spent + 1,
            ],
        );
    }

    protected function blockedReason(string $reason, string $email): string
    {
        return match ($reason) {
            'not_subscribed' => "[{$email}] is not subscribed to this list.",
            'suppressed' => "[{$email}] is on the suppression list and is blocked from every send path.",
            'suppression_unavailable' => 'The suppression list could not be checked, so nothing was sent.',
            'opted_out' => "[{$email}] has opted out of contact.",
            default => "Not sent to [{$email}]: {$reason}.",
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function recipient(AutomationContext $context, array $config): string
    {
        $configured = trim((string) ($config['to'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        foreach (['subscriber.email', 'contact.email', 'lead.email', 'email'] as $path) {
            $candidate = $context->get($path);

            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    protected function normalize(string $email): string
    {
        return (string) EmailNormalizer::normalize($email);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function campaignOptions(): array
    {
        try {
            return app(CampaignRepository::class)->all()
                ->map(fn ($campaign) => ['value' => (string) $campaign->handle, 'label' => (string) $campaign->name])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function listOptions(): array
    {
        try {
            return app(MailingListRepository::class)->all()
                ->map(fn ($list) => ['value' => (string) $list->handle, 'label' => (string) $list->name])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
