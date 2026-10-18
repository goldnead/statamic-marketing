<?php

namespace Goldnead\Marketing\Integrations\Automations\Actions;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
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
 */
class NewsletterDayGateAction implements AutomationAction
{
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

    public static function schema(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function outputSchema(): array
    {
        return [
            'blocked' => 'bool',
            'campaign' => 'string',
        ];
    }

    public function execute(AutomationContext $context, array $config): ActionResult
    {
        if ($context->isTestMode()) {
            return ActionResult::success(['blocked' => false, 'note' => 'Test mode — newsletter day gate skipped.']);
        }

        $now = CarbonImmutable::now($this->timezone());
        $dayStart = $now->startOfDay()->utc();
        $dayEnd = $now->endOfDay()->utc();

        $campaign = ($this->campaigns ??= app(CampaignRepository::class))
            ->all()
            ->first(fn (Campaign $c): bool => $this->liesImTag($c, $dayStart, $dayEnd));

        if ($campaign === null) {
            return ActionResult::success(['blocked' => false]);
        }

        // Same wall-clock time tomorrow in the gate's timezone (DST-safe),
        // handed over as UTC.
        $dueAt = $now->addDay()->utc();

        return ActionResult::wait(
            ['due_at' => $dueAt->toIso8601String()],
            ['blocked' => true, 'campaign' => $campaign->handle],
        );
    }

    private function timezone(): string
    {
        $timezone = config('marketing.day_gate.timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Europe/Berlin';
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
