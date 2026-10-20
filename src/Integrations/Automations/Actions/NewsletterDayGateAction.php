<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Integrations\Automations\ReadsRunAddress;
use Goldnead\Marketing\Support\DayBoundary;
use Goldnead\Marketing\Support\MailReceipts;
use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationAction;
use Goldnead\StatamicAutomations\Support\ActionResult;

/**
 * Keeps a drip mail off the days a newsletter campaign goes out.
 *
 * Sits in front of the mails of a welcome strip. When a campaign is scheduled
 * for, or has already been sent on, the current calendar day, the run waits and
 * asks again at the same time tomorrow (`reexecuteOnResume()`, so the node is
 * evaluated again instead of being walked past). Otherwise it passes.
 *
 * The handle is `newsletter_day_gate` and stays that: sites that built this node
 * themselves registered it under that name, and their stored graphs refer to it.
 *
 * The day boundary is `marketing.day_gate.timezone` (default Europe/Berlin)
 * even though `app.timezone` is usually UTC and must stay UTC (changing it
 * shifts every stored timestamp). The local day is converted to a UTC range and
 * compared with the campaign timestamps.
 *
 * "Campaign on this day" means:
 *  - status `scheduled` with `scheduledAt` inside the day, or
 *  - status `sending` or `sent` with `sentAt` inside the day.
 *
 * With `one_per_day` the gate also holds when this address already received a
 * marketing mail today (`MailReceipts`, the same count `marketing.check_received`
 * uses). `reason` tells the two apart: `campaign` or `mailed_today`.
 */
class NewsletterDayGateAction implements AutomationAction
{
    use ReadsRunAddress;

    public function __construct(private ?CampaignRepository $campaigns = null) {}

    public static function handle(): string
    {
        return 'newsletter_day_gate';
    }

    public static function label(): string
    {
        return 'Newsletter Day Gate';
    }

    public static function description(): ?string
    {
        return 'Waits a day (and checks again) when a newsletter campaign is scheduled for or was sent on the current calendar day.';
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
     * Without this the engine walks past the node after the wait instead of
     * evaluating it again.
     */
    public static function reexecuteOnResume(): bool
    {
        return true;
    }

    /**
     * Both fields are optional, so a graph saved before they existed still
     * means what it meant: the node holds back for campaigns only.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(): array
    {
        return [
            [
                'handle' => 'one_per_day',
                'label' => 'At most one marketing mail per day',
                'type' => 'boolean',
                'required' => false,
                'default' => false,
                'help' => 'Also hold back when this address already received a marketing mail today (in the gate timezone). The run waits and asks again tomorrow at the same time.',
            ],
            [
                'handle' => 'email',
                'label' => 'Email',
                'type' => 'text',
                'required' => false,
                'tokenable' => true,
                'help' => 'Only read with "At most one marketing mail per day". Leave empty to use the address the run is about.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'blocked' => 'bool',
            'campaign' => 'string',
            'reason' => 'string',
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function execute(AutomationContext $context, array $config): ActionResult
    {
        if ($context->isTestMode()) {
            return ActionResult::success(['blocked' => false, 'note' => 'Test mode — newsletter day gate skipped.']);
        }

        $now = CarbonImmutable::now(DayBoundary::timezone());
        $dayStart = DayBoundary::startOfToday();
        $dayEnd = DayBoundary::endOfToday();

        $campaign = ($this->campaigns ??= app(CampaignRepository::class))
            ->all()
            ->first(fn (Campaign $c): bool => $this->liesImTag($c, $dayStart, $dayEnd));

        if ($campaign !== null) {
            return $this->hold($now, ['blocked' => true, 'campaign' => $campaign->handle, 'reason' => 'campaign']);
        }

        if (! filter_var($config['one_per_day'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return ActionResult::success(['blocked' => false]);
        }

        $email = $this->addressOf($context, $config);

        if ($email === '') {
            return ActionResult::missingDataReference('email', 'Email', '{{ subscriber.email }}');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ActionResult::failed('A valid "email" is required for "one mail per day".');
        }

        try {
            $mailedToday = MailReceipts::query($email, since: $dayStart, until: $dayEnd)->exists();
        } catch (\Throwable $e) {
            return ActionResult::failed('Reading the sent mails failed: '.$e->getMessage());
        }

        if (! $mailedToday) {
            return ActionResult::success(['blocked' => false]);
        }

        return $this->hold($now, ['blocked' => true, 'campaign' => null, 'reason' => 'mailed_today']);
    }

    /**
     * Same wall-clock time tomorrow in the gate's timezone (DST-safe), handed
     * over as UTC.
     *
     * @param  array<string, mixed>  $output
     */
    private function hold(CarbonImmutable $now, array $output): ActionResult
    {
        $dueAt = $now->addDay()->utc();

        return ActionResult::wait(
            ['due_at' => $dueAt->toIso8601String()],
            $output,
        );
    }

    private function liesImTag(Campaign $campaign, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $at = match ($campaign->status) {
            Campaign::STATUS_SCHEDULED => $campaign->scheduledAt,
            Campaign::STATUS_SENDING, Campaign::STATUS_SENT => $campaign->sentAt,
            default => null,
        };

        return $at !== null && $at->greaterThanOrEqualTo($from) && $at->lessThanOrEqualTo($to);
    }
}
