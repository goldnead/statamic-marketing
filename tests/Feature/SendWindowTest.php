<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\SendWindow;

/*
 * A newsletter that lands at 03:40 local time reads as a machine that does not
 * know where you are. Sending "at nine" from a German server is nine in the
 * morning for most readers and the middle of the night for the one in
 * Vancouver.
 */
function abo(array $werte = []): Subscription
{
    return Subscription::create(array_merge([
        'email' => 'wer'.uniqid().'@example.test',
        'list_handle' => 'newsletter',
        'status' => 'subscribed',
    ], $werte));
}

function fenster(?int $von, ?int $bis, ?string $zone = null): void
{
    config()->set('marketing.sending.window', ['from' => $von, 'to' => $bis, 'timezone' => $zone]);
}

it('allows every hour when nobody configured a window', function (): void {
    fenster(null, null);

    // The default has to stay what every installation does today. A window
    // nobody configured must not start holding mail back.
    expect(app(SendWindow::class)->nextOpening(abo(), CarbonImmutable::parse('2026-08-26 03:40', 'UTC')))
        ->toBeNull();
});

it('holds a message back outside the window and says exactly when it opens', function (): void {
    fenster(8, 20);

    $oeffnet = app(SendWindow::class)->nextOpening(
        abo(['timezone' => 'Europe/Berlin']),
        CarbonImmutable::parse('2026-08-26 01:40', 'UTC'),   // 03:40 in Berlin
    );

    expect($oeffnet)->not->toBeNull()
        // Returned as a moment, not a boolean: that is what lets the caller
        // defer precisely instead of retrying hourly and hoping.
        ->and($oeffnet->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2026-08-26 08:00');
});

it('reads the window in the recipients time, not the servers', function (): void {
    fenster(8, 20);

    // One moment, two recipients. 17:00 UTC is 19:00 in Berlin (inside) and
    // 10:00 in Vancouver (also inside) — so pick one where they differ:
    // 05:00 UTC is 07:00 Berlin (outside) and 22:00 the previous day in
    // Vancouver (outside too). 14:00 UTC: 16:00 Berlin, 07:00 Vancouver.
    $jetzt = CarbonImmutable::parse('2026-08-26 14:00', 'UTC');
    $laden = app(SendWindow::class);

    expect($laden->nextOpening(abo(['timezone' => 'Europe/Berlin']), $jetzt))->toBeNull()
        ->and($laden->nextOpening(abo(['timezone' => 'America/Vancouver']), $jetzt))->not->toBeNull();
});

it('understands a window that wraps midnight', function (): void {
    fenster(22, 6);

    $laden = app(SendWindow::class);
    $abo = abo(['timezone' => 'UTC']);

    // The naive `>= from && < to` reads 22-to-6 as "never", which is a real
    // thing somebody would configure and a silent way to stop all sending.
    expect($laden->nextOpening($abo, CarbonImmutable::parse('2026-08-26 23:30', 'UTC')))->toBeNull()
        ->and($laden->nextOpening($abo, CarbonImmutable::parse('2026-08-26 03:00', 'UTC')))->toBeNull()
        ->and($laden->nextOpening($abo, CarbonImmutable::parse('2026-08-26 12:00', 'UTC')))->not->toBeNull();
});

it('falls through to the configured zone and then the applications', function (): void {
    fenster(8, 20, 'Europe/Berlin');

    expect(app(SendWindow::class)->timezoneFor(abo()))->toBe('Europe/Berlin');

    fenster(8, 20);
    config()->set('app.timezone', 'Europe/Vienna');

    expect(app(SendWindow::class)->timezoneFor(abo()))->toBe('Europe/Vienna');
});

it('survives a timezone somebody mistyped', function (): void {
    fenster(8, 20, 'Europe/Berlin');

    // A typo in one contact's row must not stop a send to twenty thousand
    // others — it falls through to the next candidate rather than throwing.
    expect(app(SendWindow::class)->timezoneFor(abo(['timezone' => 'Erde/Hamburg'])))
        ->toBe('Europe/Berlin');
});
