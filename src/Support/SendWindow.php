<?php

namespace Goldnead\Marketing\Support;

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Models\Subscription;

/**
 * When a message may be delivered, in the recipient's own time.
 *
 * A newsletter that lands at 03:40 reads as a machine, and one that lands at
 * 03:40 *local* time reads as a machine that does not know where you are. The
 * second is the one worth fixing here: sending "at nine" from a German server
 * is nine in the morning for most readers and the middle of the night for the
 * one in Vancouver.
 *
 * DELIBERATELY OFF BY DEFAULT
 * ---------------------------
 * A window nobody configured must not start holding mail back. `window.from`
 * and `window.to` unset means every hour is allowed, which is what every
 * installation does today.
 *
 * WHERE THE TIMEZONE COMES FROM
 * -----------------------------
 * The subscription's own `timezone`, then the list's, then the application's.
 * Never guessed from an IP or a language: a wrong timezone does not fail, it
 * delivers at the wrong hour, and nothing in the data says which of the three
 * answers was used.
 */
class SendWindow
{
    /**
     * May this recipient be mailed right now?
     */
    public function allows(Subscription $subscription, ?CarbonImmutable $now = null): bool
    {
        return $this->nextOpening($subscription, $now) === null;
    }

    /**
     * When the window next opens for this recipient, or null if it is open.
     *
     * Returning the moment rather than a boolean is what lets the caller defer
     * precisely instead of retrying every few minutes and hoping.
     */
    public function nextOpening(Subscription $subscription, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $from = $this->hour('window.from');
        $to = $this->hour('window.to');

        if ($from === null || $to === null) {
            return null;
        }

        $zone = $this->timezoneFor($subscription);
        $lokal = ($now ?? CarbonImmutable::now())->setTimezone($zone);
        $stunde = (int) $lokal->format('G');

        // A window that wraps midnight (22 to 6) is a real thing to configure
        // and the naive `>= from && < to` reads it as "never".
        $offen = $from <= $to
            ? ($stunde >= $from && $stunde < $to)
            : ($stunde >= $from || $stunde < $to);

        if ($offen) {
            return null;
        }

        $oeffnet = $lokal->startOfHour()->setTime($from, 0);

        if ($oeffnet->lessThanOrEqualTo($lokal)) {
            $oeffnet = $oeffnet->addDay();
        }

        // Back to UTC: the queue delays in the server's terms, and handing it a
        // local wall-clock time is how a delay ends up hours off.
        return $oeffnet->setTimezone('UTC');
    }

    /**
     * The recipient's timezone: their own, their list's, the application's.
     */
    public function timezoneFor(Subscription $subscription): string
    {
        $kandidaten = [
            $subscription->timezone ?? null,
            is_array($subscription->meta ?? null) ? ($subscription->meta['timezone'] ?? null) : null,
            config('marketing.sending.window.timezone'),
            config('app.timezone', 'UTC'),
        ];

        foreach ($kandidaten as $zone) {
            if (is_string($zone) && $zone !== '' && $this->valid($zone)) {
                return $zone;
            }
        }

        return 'UTC';
    }

    protected function valid(string $zone): bool
    {
        try {
            new \DateTimeZone($zone);

            return true;
        } catch (\Throwable) {
            // An unusable timezone falls through to the next candidate rather
            // than throwing. A typo in one contact's row must not stop a send
            // to twenty thousand others.
            return false;
        }
    }

    protected function hour(string $key): ?int
    {
        $wert = config('marketing.sending.'.$key);

        if ($wert === null || $wert === '') {
            return null;
        }

        $stunde = (int) $wert;

        return $stunde >= 0 && $stunde <= 23 ? $stunde : null;
    }
}
