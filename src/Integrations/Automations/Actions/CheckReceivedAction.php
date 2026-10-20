<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Integrations\Automations\ReadsRunAddress;
use Goldnead\Marketing\Support\DayBoundary;
use Goldnead\Marketing\Support\MailReceipts;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Asks whether this address has already been sent a marketing mail, and can
 * end the run on the answer.
 *
 * Asked when the node runs, not when the run started: a flow that waits ten
 * days between two mails needs the state of day ten. The question is answered
 * by `MailReceipts`, the same one the day gate uses.
 *
 * ## Modes
 *
 *  - `report` (default): never stops; outputs `received` for a branch to split on.
 *  - `require`: continues only if a matching mail was received; otherwise the run
 *    is **stopped** (an ordinary outcome, not a failure).
 *  - `forbid`: continues only if no matching mail was received; otherwise stopped.
 *
 * ## Filters
 *
 * Without `template` and `campaign`, every marketing mail counts. With them, a
 * message counts when its template or its campaign is one of the listed handles.
 * `within_days` keeps the last N days, `today` keeps the current calendar day in
 * `marketing.day_gate.timezone`. Both together: the stricter lower bound wins.
 *
 * ## It never answers "no" by accident
 *
 * An unreadable address or a failed lookup is `failed`, and a `forbid` that
 * passed because the lookup broke would mail somebody who must not get it.
 */
class CheckReceivedAction implements AutomationAction
{
    use ReadsRunAddress;

    public const MODE_REPORT = 'report';

    public const MODE_REQUIRE = 'require';

    public const MODE_FORBID = 'forbid';

    public static function handle(): string
    {
        return 'marketing.check_received';
    }

    public static function label(): string
    {
        return 'Check Mail Received';
    }

    public static function description(): ?string
    {
        return 'Checks whether this address already received a marketing mail (optionally one template or campaign, or within a number of days or today) and can end the run on the answer.';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'mode',
                'label' => 'Continue only if',
                'type' => 'select',
                'required' => true,
                'default' => self::MODE_REPORT,
                'options' => [
                    ['value' => self::MODE_REPORT, 'label' => 'any (just report, never stop)'],
                    ['value' => self::MODE_REQUIRE, 'label' => 'the address has received such a mail'],
                    ['value' => self::MODE_FORBID, 'label' => 'the address has not received such a mail'],
                ],
                'help' => 'When the condition does not hold, the run ends here. That is a stop, not a failure. "Just report" never stops: the node outputs received and a branch node splits on it.',
            ],
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'The address to look at. Leave empty to use the address the run is about ({{ subscriber.email }}, {{ contact.email }} or {{ email }}).',
            ],
            [
                'handle' => 'template',
                'label' => 'Template',
                'type' => 'text',
                'required' => false,
                'help' => 'Only mails from this managed email template count. Several handles separated by commas. Empty: any template.',
            ],
            [
                'handle' => 'campaign',
                'label' => 'Campaign',
                'type' => 'text',
                'required' => false,
                'help' => 'Only mails of this campaign count. Several handles separated by commas. With a template too, a mail that matches either counts. Empty: any campaign.',
            ],
            [
                'handle' => 'within_days',
                'label' => 'Within the last (days)',
                'type' => 'integer',
                'required' => false,
                'help' => 'Only mails sent in the last this many days count. Empty: no time limit.',
            ],
            [
                'handle' => 'today',
                'label' => 'Only today',
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'help' => 'Only mails sent since the start of today (in marketing.day_gate.timezone, Europe/Berlin by default) count.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'received' => 'bool',
            'count' => 'int',
            'last_at' => 'string',
            'mode' => 'string',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(AutomationContext $context, array $config): ActionResult
    {
        $mode = trim((string) ($config['mode'] ?? self::MODE_REPORT)) ?: self::MODE_REPORT;

        if (! in_array($mode, [self::MODE_REPORT, self::MODE_REQUIRE, self::MODE_FORBID], true)) {
            return ActionResult::failed(sprintf('Unknown mode "%s": use "report", "require" or "forbid".', $mode));
        }

        $days = $config['within_days'] ?? null;

        // Whole days only: 1.5 would otherwise be cut to 1 without a word.
        if ($days !== null && $days !== '' && (filter_var($days, FILTER_VALIDATE_INT) === false || (int) $days < 1)) {
            return ActionResult::failed('"within_days" must be a whole number of days, 1 or more.');
        }

        $email = $this->addressOf($context, $config);

        // A test run starts from an empty context and the address does not
        // resolve there. That is not a broken node: say so and pass.
        if ($email === '' && $context->isTestMode()) {
            return ActionResult::success([
                'received' => false,
                'count' => 0,
                'last_at' => null,
                'mode' => $mode,
                'note' => 'Test mode — no address to look at.',
            ]);
        }

        if ($email === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ subscriber.email }}');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            return ActionResult::failed('A valid "email" is required.', ['mode' => $mode]);
        }

        $since = $this->since(
            $days !== null && $days !== '' ? (int) $days : null,
            filter_var($config['today'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        try {
            $query = MailReceipts::query(
                $email,
                templates: MailReceipts::handles($config['template'] ?? null),
                campaigns: MailReceipts::handles($config['campaign'] ?? null),
                since: $since,
            );

            $count = (clone $query)->count();
            $lastSent = (clone $query)->max('sent_at');
        } catch (\Throwable $e) {
            // The class only: the exception message can carry the bound values, the address among them.
            return ActionResult::failed('Reading the sent mails failed ('.get_class($e).').', ['mode' => $mode]);
        }

        $received = $count > 0;
        $output = [
            'received' => $received,
            'count' => $count,
            'last_at' => $lastSent !== null ? CarbonImmutable::parse((string) $lastSent, 'UTC')->toIso8601String() : null,
            'mode' => $mode,
        ];

        if ($mode === self::MODE_REQUIRE && ! $received) {
            return ActionResult::stopped('The address has not received a matching marketing mail, so the run ends here.');
        }

        if ($mode === self::MODE_FORBID && $received) {
            return ActionResult::stopped(sprintf('The address has already received a matching marketing mail (%d), so the run ends here.', $count));
        }

        return ActionResult::success($output);
    }

    /**
     * The later of the two lower bounds, when both are asked for.
     */
    private function since(?int $days, bool $today): ?CarbonImmutable
    {
        $bounds = array_filter([
            $days !== null ? now()->subDays($days)->toImmutable() : null,
            $today ? DayBoundary::startOfToday() : null,
        ]);

        if ($bounds === []) {
            return null;
        }

        return collect($bounds)->sortBy(fn (CarbonImmutable $bound): int => $bound->getTimestamp())->last();
    }
}
