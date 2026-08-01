<?php

use Goldnead\Marketing\Services\VariantAssigner;
use Illuminate\Support\Str;

/**
 * The two properties an A/B assignment has to have before any report built on
 * it means anything: it never changes, and it splits evenly.
 *
 * Both are properties of the function rather than of the send, so they are
 * measured here, on numbers large enough to see a bias. The wiring — that the
 * job actually calls this, once, at the snapshot — is proven end to end in
 * CampaignVariantTest.
 */
it('returns the same variant for the same recipient no matter how often it is asked', function (): void {
    $assigner = new VariantAssigner;

    $uuid = (string) Str::uuid();
    $first = $assigner->assign('juli-ausgabe', $uuid, 1);

    for ($i = 0; $i < 1000; $i++) {
        expect($assigner->assign('juli-ausgabe', $uuid, 1))->toBe($first);
    }

    // A fresh instance is not a fresh roll. Nothing is memoised, seeded or
    // held on the object — if it were, a queue worker that constructed its own
    // assigner would disagree with the one that snapshotted the audience.
    expect((new VariantAssigner)->assign('juli-ausgabe', $uuid, 1))->toBe($first);
});

/**
 * The retry case, stated as bluntly as it can be: a completely separate PHP
 * process, with its own interpreter, its own memory and its own random seed,
 * has to reach the same answer.
 *
 * That is what a retried job, a second queue worker or a report generated three
 * weeks later actually is. An assignment derived from anything process-local —
 * `rand()`, `spl_object_id()`, a static counter, the clock — passes every
 * in-process assertion above and fails this one.
 */
it('reaches the same verdict in a separate PHP process', function (): void {
    $assigner = new VariantAssigner;

    $uuids = collect(range(1, 25))->map(fn () => (string) Str::uuid())->all();
    $inProcess = collect($uuids)->map(fn ($uuid) => $assigner->assign('juli-ausgabe', $uuid, 1))->all();

    $autoload = realpath(__DIR__.'/../../vendor/autoload.php');
    $payload = base64_encode(json_encode($uuids));

    $script = <<<PHP
        require '{$autoload}';
        \$uuids = json_decode(base64_decode('{$payload}'), true);
        \$assigner = new Goldnead\\Marketing\\Services\\VariantAssigner;
        echo json_encode(array_map(fn (\$uuid) => \$assigner->assign('juli-ausgabe', \$uuid, 1), \$uuids));
    PHP;

    // Both quoted: the interpreter path contains a space on a stock Herd/macOS
    // install, and an unquoted PHP_BINARY silently runs `sh` instead.
    $output = shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' 2>&1');

    expect(json_decode((string) $output, true))->toBe($inProcess, "Subprocess said: {$output}");
});

it('splits a realistic audience close to evenly', function (): void {
    $assigner = new VariantAssigner;

    // 10,000 recipients — larger than any list this addon sends to today, and
    // large enough that a real bias would show. A fair coin over 10,000 draws
    // sits within roughly ±2% of half at four standard deviations, so a 3%
    // tolerance fails on a broken split and not on ordinary variance.
    $recipients = collect(range(1, 10000))->map(fn () => (string) Str::uuid());

    $counts = $recipients
        ->map(fn (string $uuid) => $assigner->assign('juli-ausgabe', $uuid, 1))
        ->countBy();

    expect($counts->keys()->sort()->values()->all())->toBe(['a', 'b']);

    $share = $counts['a'] / $recipients->count();

    expect($counts['a'] + $counts['b'])->toBe(10000)
        ->and($share)->toBeGreaterThan(0.47, "Variant A took {$share} of the audience.")
        ->toBeLessThan(0.53, "Variant A took {$share} of the audience.");
});

/**
 * A split that is even on average but always tips the same way for a given
 * campaign is still broken — it just hides it behind one lucky handle.
 */
it('stays even across many different campaigns rather than only on average', function (): void {
    $assigner = new VariantAssigner;

    $recipients = collect(range(1, 2000))->map(fn () => (string) Str::uuid())->all();

    foreach (['juli-ausgabe', 'august-ausgabe', 'warteliste', 'q3_reaktivierung', 'x'] as $handle) {
        $counts = collect($recipients)
            ->map(fn (string $uuid) => $assigner->assign($handle, $uuid, 1))
            ->countBy();

        $share = $counts['a'] / count($recipients);

        expect($share)->toBeGreaterThan(0.45, "Campaign [{$handle}] tipped to {$share} for variant A.")
            ->toBeLessThan(0.55, "Campaign [{$handle}] tipped to {$share} for variant A.");
    }
});

/**
 * The reason the campaign handle is in the seed at all.
 *
 * Without it every campaign would test the same fixed half of the list against
 * the other fixed half forever, and each result would measure that cohort as
 * much as the subject line. Recipients have to be reshuffled between campaigns
 * while staying nailed down within one.
 */
it('reshuffles recipients between campaigns instead of freezing one cohort into B', function (): void {
    $assigner = new VariantAssigner;

    $recipients = collect(range(1, 2000))->map(fn () => (string) Str::uuid());

    $july = $recipients->map(fn ($uuid) => $assigner->assign('juli-ausgabe', $uuid, 1));
    $august = $recipients->map(fn ($uuid) => $assigner->assign('august-ausgabe', $uuid, 1));

    $agreements = $july->zip($august)->filter(fn ($pair) => $pair[0] === $pair[1])->count();
    $agreementRate = $agreements / $recipients->count();

    // Two independent fair splits agree about half the time. A rate near 1.0
    // would mean the campaign is not part of the seed; near 0.0 would mean it
    // is inverting deterministically, which is just as much a fixed cohort.
    expect($agreementRate)->toBeGreaterThan(0.45)->toBeLessThan(0.55);
});

it('keeps assignments inside a brand', function (): void {
    $assigner = new VariantAssigner;

    // Same campaign handle, same recipient key, two tenants. Recipient keys are
    // already unique per brand in practice, so this is belt and braces — but a
    // derived value must not be able to cross a tenant boundary even in
    // principle, and the only way to state that is to measure it.
    $recipients = collect(range(1, 2000))->map(fn () => (string) Str::uuid());

    $brandA = $recipients->map(fn ($uuid) => $assigner->assign('juli-ausgabe', $uuid, 1));
    $brandB = $recipients->map(fn ($uuid) => $assigner->assign('juli-ausgabe', $uuid, 2));

    $agreements = $brandA->zip($brandB)->filter(fn ($pair) => $pair[0] === $pair[1])->count();

    expect($agreements / $recipients->count())->toBeGreaterThan(0.45)->toBeLessThan(0.55);
});

it('only ever answers with a known variant', function (): void {
    $assigner = new VariantAssigner;

    expect(VariantAssigner::variants())->toBe(['a', 'b']);

    $seen = collect(range(1, 500))
        ->map(fn () => $assigner->assign('juli-ausgabe', (string) Str::uuid(), null))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($seen)->toBe(['a', 'b']);
});
