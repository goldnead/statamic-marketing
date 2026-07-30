<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * Every scheduled command is registered exactly once.
 *
 * A Statamic application fires its `app->booted()` callbacks twice. This
 * package knew that for its sibling bridges — they are written to survive being
 * invoked repeatedly — but the schedule was registered through the same
 * mechanism and was not idempotent, so `schedule:list` carried every command
 * here twice.
 *
 * Nothing broke, and only by luck: `onOneServer()` with a fixed name means the
 * second copy loses the mutex and is skipped. This test exists because that
 * luck is not transferable. The next command added without `onOneServer()`
 * would simply run twice, and nobody would notice until something arrived
 * twice at a subscriber.
 *
 * It deliberately counts *whatever* is registered rather than asserting against
 * a list of the commands that exist today, so a command added tomorrow is
 * covered without anyone remembering to come back here.
 */
function scheduledCommandCounts(): array
{
    $counts = [];

    foreach (app(Schedule::class)->events() as $event) {
        if (! $command = $event->command) {
            continue;
        }

        // "…/php 'artisan' marketing:send-scheduled" → "marketing:send-scheduled"
        $name = trim((string) preg_replace("/^.*artisan'?\s*/", '', $command));

        $counts[$name] = ($counts[$name] ?? 0) + 1;
    }

    return $counts;
}

/**
 * Replays the application's booted callbacks, which is what a Statamic
 * application does and what Testbench does not.
 *
 * Without this the test is worthless and was: written against the unfixed
 * provider it passed, because the condition it exists to catch never occurred
 * in the test environment. A check that cannot go red is not coverage, it is
 * decoration — so the replay is the load-bearing part of this file.
 */
function replayBootedCallbacks(): void
{
    $app = app();

    $property = new ReflectionProperty($app, 'bootedCallbacks');
    $property->setAccessible(true);

    foreach ($property->getValue($app) as $callback) {
        $callback($app);
    }
}

it('registers every scheduled command exactly once', function (): void {
    replayBootedCallbacks();

    // Only this package's own commands. A sibling addon carrying the same
    // defect is a finding to report there, not a reason to fail here.
    $counts = array_filter(
        scheduledCommandCounts(),
        fn (string $name): bool => str_starts_with($name, 'marketing:'),
        ARRAY_FILTER_USE_KEY
    );

    $duplicates = array_filter($counts, fn (int $n): bool => $n > 1);

    expect($duplicates)->toBe(
        [],
        'Registered more than once: '.json_encode($duplicates)
            .'. A schedule registration must not hang off app->booted() — those callbacks '
            .'fire twice in a Statamic application. Use callAfterResolving(Schedule::class).'
    );
});

it('registers the scheduled send at all', function (): void {
    // Guards the test above against passing for the wrong reason: an empty
    // schedule has no duplicates either.
    expect(scheduledCommandCounts())->toHaveKey('marketing:send-scheduled');
});
