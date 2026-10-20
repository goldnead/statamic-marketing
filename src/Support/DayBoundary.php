<?php

namespace Goldnead\Marketing\Support;

use Carbon\CarbonImmutable;

/**
 * The calendar day the marketing automations count in.
 *
 * `marketing.day_gate.timezone`, default Europe/Berlin. `app.timezone` is UTC
 * and must stay UTC (changing it shifts every stored timestamp), so the day
 * boundary is drawn here, in the configured zone, and handed to the database
 * as a UTC range.
 */
final class DayBoundary
{
    public static function timezone(): string
    {
        $timezone = config('marketing.day_gate.timezone');

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Europe/Berlin';
    }

    /** The start of the current calendar day, in UTC. */
    public static function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay()->utc();
    }

    /** The end of the current calendar day, in UTC. */
    public static function endOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->endOfDay()->utc();
    }
}
