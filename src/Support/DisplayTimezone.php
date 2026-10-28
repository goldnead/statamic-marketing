<?php

namespace Goldnead\Marketing\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * The zone a person reads and writes times in, in the Control Panel.
 *
 * `app.timezone` has to stay UTC for the stored timestamps, and the people
 * using the panel do not live in UTC: a "Versandzeit" of 07:00 typed into a
 * zone-less datetime-local field went out at 08:00 or 09:00 their time. This
 * is the zone that field is read in and the listings show times in. What is
 * stored does not change, it stays UTC.
 *
 * `marketing.display_timezone` (default Europe/Berlin), then `app.timezone`,
 * then UTC. An unusable value falls through to the next candidate instead of
 * failing a request.
 */
class DisplayTimezone
{
    public function name(): string
    {
        foreach ([config('marketing.display_timezone'), config('app.timezone'), 'UTC'] as $zone) {
            if (is_string($zone) && $zone !== '' && $this->valid($zone)) {
                return $zone;
            }
        }

        return 'UTC';
    }

    /**
     * A wall-clock time typed in the display zone, as the instant it means.
     * Throws \Carbon\Exceptions\InvalidFormatException on garbage.
     */
    public function parse(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, $this->name());
    }

    private function valid(string $zone): bool
    {
        try {
            new DateTimeZone($zone);

            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
