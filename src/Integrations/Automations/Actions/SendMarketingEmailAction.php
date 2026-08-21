<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\SendMessageJob;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\SendMode;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Sending\SingleSendResult;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Services\SequenceOptOut;
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
 * **Two modes, because sites write their mails in two ways.**
 *
 *  - **Campaign mode** names a campaign, which carries the subject, the
 *    content, the layout, the list and the classification. This is what the
 *    node was built for.
 *  - **Template mode** names a managed email template (`et_templates`), a
 *    recipient, a subject and the list the consent comes from. This is how a
 *    site that writes its welcome mails as templates already sends — see the
 *    domain-neutral `send_email` in `automations` — and until 1.12.0 those
 *    mails had no way through this node at all. They went out through the
 *    neutral one instead, which means they went out without consent,
 *    suppression, opt-out or the cap ever being asked. That was the whole
 *    defect: the node was correct and unusable.
 *
 * Exactly one of `campaign` and `template`. Both is two answers to "what is
 * this mail"; neither is none. See {@see SendMode}, which is also where the
 * rule is tested — deliberately on marketing's side of the boundary, so it does
 * not need the orchestrator installed to hold.
 *
 * **The gates are the same in both modes, in the same order.** Consent,
 * suppression, opt-out, cap. Template mode has no campaign to take a list from,
 * so `list` is required there rather than optional: a marketing mail sent
 * without a list is a marketing mail with nothing behind it to show the
 * recipient ever agreed, and this node will not send one.
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
     * count. Keyed by mail + address rather than by node, because `execute()`
     * is not told which node it is — and because the pair is what the count is
     * actually about.
     */
    public const DEFERRAL_KEY = '_marketing_send_deferrals';

    public function __construct(
        protected SingleSend $sender,
        protected CampaignRepository $campaigns,
        protected MailingListRepository $lists,
        protected SequenceOptOut $optOuts,
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
        return 'Sends one campaign — or one managed email template — to the contact in this run through the marketing send path: list consent, suppression, opt-out and the frequency cap, in that order.';
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
                // Not required, and this is the one field whose `required` flag
                // would be a lie. Either this or `template` has to be set, and
                // a form that demands both cannot express the node at all.
                // The either/or is checked in execute(), where it can say which
                // of the two mistakes was made.
                'required' => false,
                'help' => 'Campaign mode. The campaign whose subject, content and template make up this mail. Author it in Marketing → Campaigns and leave it in draft — a sequence never queues it to the whole list. Leave empty to send a managed email template instead.',
            ],
            [
                'handle' => 'template',
                'label' => 'Email template',
                'type' => 'select',
                'options' => static::templateOptions(),
                'options_source' => 'email_templates.templates',
                'required' => false,
                // Declarative UI affordance hint, the same one the neutral
                // send_email node sets: the config panel renders a preview and
                // a picker beside a field that carries it.
                'preview' => 'email',
                'help' => 'Template mode. Send a managed email template (et_templates) instead of a campaign. Needs a subject and a mailing list — a template carries no list of its own, and the list is where consent comes from.',
            ],
            [
                'handle' => 'subject',
                'label' => 'Subject',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Template mode only, and required there — a campaign brings its own. Tokens resolve against the run, e.g. {{ subscriber.first_name }}, schön, dass du dabei bist.',
            ],
            [
                'handle' => 'list',
                'label' => 'Mailing list',
                'type' => 'select',
                'options' => static::listOptions(),
                'options_source' => 'marketing.lists',
                'required' => false,
                'help' => "Where consent for this mail comes from. In campaign mode, leave empty to use the campaign's own list. In template mode it is required. A recipient without a subscribed subscription on this list is not mailed.",
            ],
            [
                'handle' => 'to',
                'label' => 'Recipient',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Leave empty to use the address the run is already about ({{ subscriber.email }}, {{ contact.email }} or {{ email }}).',
            ],
            [
                'handle' => 'mail_class',
                'label' => 'Classification',
                'type' => 'select',
                'options' => static::mailClassOptions(),
                'required' => false,
                'default' => MailClass::Marketing->value,
                'help' => "Template mode only: what the frequency cap makes of this mail. `marketing` is capped, `transactional`, `digest` and `reminder` are exempt. Campaign mode takes the campaign's own classification and ignores this field.",
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
        $mode = SendMode::fromConfig($config);

        // Template mode names its own subject, which is what an editor
        // recognises the mail by, with the template slug as the reference.
        if ($mode->template !== '') {
            return ['label' => $mode->subject, 'reference' => $mode->template];
        }

        if ($mode->campaign === '') {
            return ['label' => '', 'reference' => null];
        }

        // The subject, because that is what an editor recognises a mail by. A
        // handle that no longer resolves still names itself rather than
        // producing a blank row.
        try {
            $campaign = app(CampaignRepository::class)->find($mode->campaign);
        } catch (\Throwable) {
            $campaign = null;
        }

        return [
            'label' => (string) ($campaign?->subject ?: $campaign?->name ?: $mode->campaign),
            'reference' => $mode->campaign,
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
            'template' => 'string',
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
        // Static configuration, validated before the test-mode short-circuit:
        // a node that cannot say which mail it sends is a broken node and a
        // test run exists to say so. See ActionResult::missingDataReference()
        // for the rule that keeps *data* references on the other side of it.
        $mode = SendMode::fromConfig($config);

        if (! $mode->isValid()) {
            return ActionResult::failed((string) $mode->error);
        }

        /*
         * Ausgestiegen? Dann endet der Lauf hier.
         *
         * Eine Serie wartet tagelang zwischen den Schritten. Wer an Tag 3
         * aussteigt, darf Mail 4 nicht mehr bekommen — und zwischen den
         * Wartezeiten laeuft nichts ausser diesem Knoten. Die Pruefung im
         * EnrollmentGate greift nur fuer Laeufe, die noch gar nicht begonnen
         * haben.
         *
         * `stopped` und nicht `skipped`: der Rest der Serie ist gegenstandslos,
         * nicht nur dieser eine Schritt. Ein uebersprungener Schritt liesse den
         * Lauf weiterwandern und die naechste Mail trotzdem rausgehen.
         */
        if (($blocked = $this->stopIfOptedOut($context, $config)) !== null) {
            return $blocked;
        }

        return $mode->isTemplate()
            ? $this->sendTemplate($context, $config, $mode)
            : $this->sendCampaign($context, $config, $mode);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function sendCampaign(AutomationContext $context, array $config, SendMode $mode): ActionResult
    {
        $campaign = $this->campaigns->find($mode->campaign);

        if ($campaign === null) {
            return ActionResult::failed("Campaign [{$mode->campaign}] does not exist.");
        }

        $listHandle = $mode->list !== '' ? $mode->list : (string) $campaign->listHandle;
        $list = $listHandle !== '' ? $this->lists->find($listHandle) : null;

        if ($list === null) {
            return ActionResult::failed(
                "Campaign [{$mode->campaign}] has no mailing list to take consent from."
            );
        }

        $email = $this->recipient($context, $config);

        // The orchestrator's own switch, not a second one of ours. A test run
        // that may send real mail is a decision about test runs, and it is
        // already made once in `automations.test_mode.send_real_emails`.
        if ($this->isDryRun($context)) {
            return $this->preview([
                'campaign' => $campaign->handle,
                'subject' => $campaign->subject,
                'list' => $list->handle,
                'to' => $email,
                'mail_class' => $campaign->mailClass()->value,
            ]);
        }

        $subscription = $this->consentOf($email, $list);

        if (! $subscription instanceof Subscription) {
            return $subscription;
        }

        return $this->interpret(
            $this->sender->send($campaign, $list, $subscription, sequenceUuid: $this->sequenceUuid($context)),
            $context,
            $email,
            $list,
            ['campaign' => $campaign->handle],
            $campaign->handle,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function sendTemplate(AutomationContext $context, array $config, SendMode $mode): ActionResult
    {
        $list = $this->lists->find($mode->list);

        if ($list === null) {
            return ActionResult::failed(
                "Mailing list [{$mode->list}] does not exist, so there is no consent to send this template under."
            );
        }

        // Also static configuration: whether the named template resolves has
        // nothing to do with who is being mailed, so a test run has to find a
        // broken one rather than report it as a preview.
        if (! $this->sender->canResolveTemplate($mode->template)) {
            return ActionResult::failed(SingleSend::unresolvedTemplateMessage($mode->template));
        }

        $email = $this->recipient($context, $config);

        if ($this->isDryRun($context)) {
            return $this->preview([
                'template' => $mode->template,
                'subject' => $mode->subject,
                'list' => $list->handle,
                'to' => $email,
                'mail_class' => $mode->mailClass->value,
            ]);
        }

        $subscription = $this->consentOf($email, $list);

        if (! $subscription instanceof Subscription) {
            return $subscription;
        }

        return $this->interpret(
            $this->sender->sendTemplate(
                $mode->template,
                $mode->subject,
                $list,
                $subscription,
                $mode->mailClass,
                sequenceUuid: $this->sequenceUuid($context),
            ),
            $context,
            $email,
            $list,
            ['template' => $mode->template],
            $mode->template,
        );
    }

    protected function isDryRun(AutomationContext $context): bool
    {
        return $context->isTestMode() && ! config('automations.test_mode.send_real_emails', false);
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    protected function preview(array $preview): ActionResult
    {
        return ActionResult::success([
            'preview' => $preview,
            'note' => 'Test mode — nothing was sent and no gate was consulted.',
        ]);
    }

    /**
     * The consent record this send stands on, or the result that says there
     * is none.
     *
     * Both failures here are about the run's *data* rather than its
     * configuration, which is why they are asked after the test-mode branch:
     * a test run starts from an empty context by design, and every address
     * token resolves to nothing there.
     */
    protected function consentOf(string $email, MailingList $list): Subscription|ActionResult
    {
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

        return $subscription;
    }

    /**
     * One send outcome, turned into the run's next step. Identical in both
     * modes — the gates do not care what the mail was made of, and neither
     * does what the run does about them.
     *
     * @param  array<string, string>  $identity  what names the mail in the run
     *                                           output: the campaign handle, or
     *                                           the template slug.
     * @param  string  $reference  the same, as a single string, for the deferral
     *                             key and the warning that names the discarded
     *                             mail.
     */
    protected function interpret(
        SingleSendResult $result,
        AutomationContext $context,
        string $email,
        MailingList $list,
        array $identity,
        string $reference,
    ): ActionResult {
        return match (true) {
            $result->wasSent() => ActionResult::success($identity + [
                'sent' => true,
                'list' => $list->handle,
                'email' => $email,
                'message_uuid' => (string) $result->message?->uuid,
            ]),
            $result->isDeferred() => $this->holdOrGiveUp($context, $identity, $reference, $email, $result),
            $result->isFailed() => ActionResult::failed(
                (string) $result->error,
                $identity + ['email' => $email, 'reason' => $result->reason],
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
     *
     * @param  array<string, string>  $identity
     */
    protected function holdOrGiveUp(
        AutomationContext $context,
        array $identity,
        string $reference,
        string $email,
        SingleSendResult $result,
    ): ActionResult {
        $max = max(0, (int) config('marketing.frequency_cap.defer.max_deferrals', 3));
        $key = static::DEFERRAL_KEY.'.'.sha1($reference.'|'.$this->normalize($email));
        $spent = (int) $context->get($key, 0);

        if ($spent >= $max) {
            Log::warning(
                "Marketing did not send [{$reference}] to [{$email}] from an automation: "
                ."the frequency cap held it back {$max} times and the mail was then discarded. "
                .'The flow continued without it; the recipient never received this mail.'
            );

            return ActionResult::success($identity + [
                'sent' => false,
                'email' => $email,
                'reason' => 'frequency_cap_discarded',
            ]);
        }

        $context->set($key, $spent + 1);

        $minutes = max(1, (int) ($result->retryAfterMinutes ?? 1440));

        return ActionResult::wait(
            ['seconds' => $minutes * 60],
            $identity + [
                'sent' => false,
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
    /**
     * Die Serie, aus der diese Mail kommt — fuer den Ausstiegs-Link im Fuss.
     *
     * Leer ausserhalb eines Laufs. Dann ist es keine Serie, sondern ein
     * direkter Aufruf, und ein Link mit dem Versprechen "diese Serie
     * abbestellen" haette nichts, was er abbestellen koennte.
     */
    protected function sequenceUuid(AutomationContext $context): ?string
    {
        $uuid = (string) ($context->get('_automation')['uuid'] ?? '');

        return $uuid === '' ? null : $uuid;
    }

    /**
     * Der Serien-Ausstieg, gefragt kurz vor dem Senden.
     *
     * Fehlt die Automations-UUID im Kontext, wird nicht geprueft und nicht
     * blockiert: das ist ein Aufruf ausserhalb eines Laufs (Test, direkter
     * Aufruf), und dort gibt es keine Serie, aus der man aussteigen koennte.
     * Im Zweifel senden ist hier richtig — die Einwilligungs-, Sperrlisten-
     * und Opt-out-Pruefungen dahinter bleiben ja bestehen.
     */
    protected function stopIfOptedOut(AutomationContext $context, array $config): ?ActionResult
    {
        $automationUuid = (string) ($context->get('_automation')['uuid'] ?? '');

        if ($automationUuid === '') {
            return null;
        }

        $email = $this->recipient($context, $config);

        if ($email === '' || ! $this->optOuts->has($automationUuid, $email)) {
            return null;
        }

        return ActionResult::stopped('sequence_opt_out');
    }

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

    /**
     * Marketing's own templates, as the list this field falls back to.
     *
     * `options_source` points the config panel at the live `et_templates`
     * catalog where `goldnead/statamic-email-templates` is installed, which is
     * the usual case for this mode. These are what a site that has never
     * installed it can still pick from — the resolver reads both.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function templateOptions(): array
    {
        try {
            return app(EmailTemplateRepository::class)->all()
                ->map(fn ($template) => ['value' => (string) $template->handle, 'label' => (string) $template->name])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function mailClassOptions(): array
    {
        return array_map(
            fn (MailClass $class) => ['value' => $class->value, 'label' => $class->label()],
            MailClass::cases(),
        );
    }
}
