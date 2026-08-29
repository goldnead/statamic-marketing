<?php

/**
 * The guard over the copies.
 *
 * `tests/Fakes/insights-contracts.php` claims to be the analytics addon's
 * contract copied byte for byte, and `tests/Fakes/insights-table-metric.php`
 * claims the same of the base class every metric here extends. Until this file
 * existed, nothing checked either claim. The sibling is neither a `require` nor
 * a `require-dev`, so the `interface_exists` locks in those files never engage:
 * the copies are what the whole suite runs against **and** what PHPStan
 * analyses. A method added to `Metric` upstream, or a `where` changed in
 * `TableMetric`, would therefore leave every test here green and be wrong on
 * the first install that has both addons — and a green suite over a contract
 * that no longer exists is worse than no suite, because it is believed.
 *
 * So the copies are held against the originals wherever the originals can be
 * found: installed in `vendor/`, or checked out beside this package, which is
 * how the family is developed. Where they cannot be found the tests skip and
 * say why — a machine without the sibling cannot answer the question, and
 * pretending otherwise is the failure this file exists to prevent.
 *
 * **The two are checked differently, because they are different kinds of
 * thing.** An interface has no behaviour, so comparing its shape by reflection
 * compares all of it; that runs in a second PHP process
 * (`tests/Support/insights-contract-probe.php`), because both sides declare the
 * same fully qualified names and one process can hold only one of them.
 * `TableMetric` is an implementation, and reflection would happily agree that
 * two versions of `inPeriod()` match while one of them had grown a condition
 * the other lacks — which is precisely the sort of change that has already been
 * made to it once. So that one is compared as text.
 */

/** The three interfaces a metric here implements or is read through. */
const INSIGHTS_VERTRAEGE = ['Contracts\Metric', 'Contracts\HasBreakdowns', 'Contracts\HasFilterOptions'];

/** The value objects the queries read. */
const INSIGHTS_WERTOBJEKTE = ['Support\MetricQuery', 'Support\Period', 'Support\Unit'];

/**
 * Constants whose **value** this package depends on, not just their name.
 *
 * `unit()` returns these strings straight to a screen that formats by them, and
 * the stock series compares the bucket against `BUCKET_MONTH` to decide whether
 * it is walking days or months. A renamed value upstream would silently turn
 * every monthly chart daily, or every money figure into a plain count.
 */
const INSIGHTS_TRAGENDE_KONSTANTEN = [
    'Support\MetricQuery' => ['BUCKET_DAY', 'BUCKET_MONTH'],
    'Support\Unit' => ['COUNT', 'CURRENCY', 'PERCENT'],
];

/** Where the real package is, if it is anywhere. */
function insightsQuelle(): ?string
{
    $wurzel = dirname(__DIR__, 2);

    foreach ([
        $wurzel.'/vendor/goldnead/statamic-insights/src',
        dirname($wurzel).'/statamic-insights/src',
    ] as $kandidat) {
        if (is_dir($kandidat.'/Contracts')) {
            return $kandidat;
        }
    }

    return null;
}

function insightsQuelleOderSkip(): string
{
    $quelle = insightsQuelle();

    if ($quelle === null) {
        test()->markTestSkipped(
            'goldnead/statamic-insights was not found — neither in vendor/ nor checked out beside this package. '
            .'It is a `suggest` and deliberately not installed, so this machine cannot say whether the stand-ins '
            .'in tests/Fakes still match the real thing. Run this where the sibling exists.'
        );
    }

    return $quelle;
}

/**
 * The shape of a contract, read in a process of its own.
 *
 * @param  array<int, string>  $dateien
 * @return array<string, ?array<string, mixed>>
 */
function insightsForm(array $dateien): array
{
    $sonde = dirname(__DIR__).'/Support/insights-contract-probe.php';

    $befehl = implode(' ', array_map(
        'escapeshellarg',
        array_merge([PHP_BINARY, $sonde], $dateien),
    ));

    $ausgabe = shell_exec($befehl.' 2>&1');
    $gelesen = json_decode((string) $ausgabe, true);

    expect($gelesen)->toBeArray('The contract probe returned nothing usable: '.$ausgabe);

    return $gelesen;
}

/** @return array{0: array<string, ?array<string, mixed>>, 1: array<string, ?array<string, mixed>>} */
function insightsBeideSeiten(): array
{
    $quelle = insightsQuelleOderSkip();

    $dateien = glob($quelle.'/Contracts/*.php') ?: [];

    foreach (['MetricQuery', 'Period', 'Unit'] as $wertobjekt) {
        $dateien[] = $quelle.'/Support/'.$wertobjekt.'.php';
    }

    return [
        insightsForm($dateien),
        insightsForm([dirname(__DIR__).'/Fakes/insights-contracts.php']),
    ];
}

/**
 * Code with the whitespace taken out of it.
 *
 * The copy sits four spaces further in than the original, because it lives
 * inside the `class_exists` guard that makes a real installation win. That is
 * the only difference the copy is allowed to have, so it is the only one
 * normalised away — a changed condition, a changed column, a changed default
 * all survive this and fail the comparison, which is the entire point.
 */
function insightsOhneEinrueckung(string $quelltext): string
{
    $zeilen = array_map('rtrim', explode("\n", $quelltext));
    $zeilen = array_map(fn (string $zeile) => ltrim($zeile), $zeilen);

    return implode("\n", array_values(array_filter($zeilen, fn (string $zeile) => $zeile !== '')));
}

// -- The contract ------------------------------------------------------------

/**
 * An addition upstream breaks the copy just as a removal does.
 *
 * A metric written against a stand-in that is missing a method does not
 * implement the real interface at all, so equality is the right test here and a
 * subset would not be: both directions are fatal.
 */
it('still matches the real contract', function (): void {
    [$echt, $kopie] = insightsBeideSeiten();

    foreach (INSIGHTS_VERTRAEGE as $name) {
        expect($echt[$name])->not->toBeNull("The sibling no longer declares {$name}.");
        expect($kopie[$name])->not->toBeNull("The stand-in does not declare {$name}.");

        expect($kopie[$name]['methods'])->toBe(
            $echt[$name]['methods'],
            "The stand-in for {$name} has drifted from the real contract. Copy it across again — every metric in "
            .'src/Integrations/Insights is written against this shape, and the whole suite runs on the copy.',
        );
    }
});

/**
 * The value objects, as a subset rather than an equality.
 *
 * Deliberately looser than the interfaces above, and for a reason that does not
 * apply to them: a field added to `MetricQuery` upstream breaks nothing here —
 * the queries read what they read. Demanding equality would paint this test red
 * on a purely additive release and teach whoever sees it to ignore the file.
 * What must hold is that everything the copy promises is really there and
 * really has that shape.
 */
it('still carries what the metrics read out of the value objects', function (): void {
    [$echt, $kopie] = insightsBeideSeiten();

    foreach (INSIGHTS_WERTOBJEKTE as $name) {
        expect($echt[$name])->not->toBeNull("The sibling no longer declares {$name}.");
        expect($kopie[$name])->not->toBeNull("The stand-in does not declare {$name}.");

        foreach ($kopie[$name]['methods'] as $methode => $form) {
            expect(array_key_exists($methode, $echt[$name]['methods']))
                ->toBeTrue("{$name}::{$methode}() exists only in the stand-in.");
            expect($form)->toBe($echt[$name]['methods'][$methode], "{$name}::{$methode}() has a different signature upstream.");
        }

        foreach ($kopie[$name]['properties'] as $eigenschaft => $form) {
            expect(array_key_exists($eigenschaft, $echt[$name]['properties']))
                ->toBeTrue("{$name}::\${$eigenschaft} exists only in the stand-in.");
            expect($form)->toBe($echt[$name]['properties'][$eigenschaft], "{$name}::\${$eigenschaft} is declared differently upstream.");
        }
    }

    foreach (INSIGHTS_TRAGENDE_KONSTANTEN as $name => $konstanten) {
        foreach ($konstanten as $konstante) {
            expect(array_key_exists($konstante, $echt[$name]['constants']))
                ->toBeTrue("{$name}::{$konstante} is gone upstream.");
            expect($kopie[$name]['constants'][$konstante] ?? null)->toBe(
                $echt[$name]['constants'][$konstante],
                "{$name}::{$konstante} means something else upstream, and this package reads its value.",
            );
        }
    }
});

// -- The base class ----------------------------------------------------------

/**
 * The copied base class is still the real one, word for word.
 *
 * Not by reflection, and the difference is the whole reason this test is here
 * rather than one entry longer in the one above. `TableMetric` is where the
 * windowing, the bucketing and the null-keeping split actually happen: its
 * signatures have been stable while its body has already had a defect fixed in
 * it — a period with both bounds null added no condition at all, so a metric
 * over a nullable column counted every row ever written the moment somebody
 * picked "all time". Reflection would have agreed that both versions matched.
 *
 * When this fails, the fix is to copy the upstream file between the markers
 * again and re-run the metric tests. It is not to adjust the comparison.
 */
it('still carries the real base class, body and all', function (): void {
    $quelle = insightsQuelleOderSkip();

    $echt = (string) file_get_contents($quelle.'/Support/TableMetric.php');
    $kopie = (string) file_get_contents(dirname(__DIR__).'/Fakes/insights-table-metric.php');

    $anfang = strpos($kopie, '// >>> BEGIN VERBATIM COPY');
    $ende = strpos($kopie, '// <<< END VERBATIM COPY');

    expect($anfang)->not->toBeFalse('The verbatim markers are gone from the stand-in.');
    expect($ende)->not->toBeFalse('The verbatim markers are gone from the stand-in.');

    $abschnitt = substr($kopie, $anfang, $ende - $anfang);
    $abschnitt = substr($abschnitt, (int) strpos($abschnitt, "\n") + 1);

    // From the class docblock to the end of the file: everything upstream that
    // is not the `<?php`, the namespace and the imports.
    $echterKoerper = substr($echt, (int) strpos($echt, '/**'.PHP_EOL.' * A metric over one database table'));

    expect(insightsOhneEinrueckung($abschnitt))->toBe(
        insightsOhneEinrueckung($echterKoerper),
        'tests/Fakes/insights-table-metric.php has drifted from statamic-insights/src/Support/TableMetric.php. '
        .'The suite runs on the copy, so a drifted copy tests numbers this addon will not produce in production. '
        .'Copy the upstream file between the markers again.',
    );

    // The imports too: the body names `Builder`, `DB` and `Schema` by their
    // short names, and a body that matched with a different import would be a
    // different class.
    preg_match_all('/^use .+;$/m', substr($echt, 0, (int) strpos($echt, '/**'.PHP_EOL.' * A metric over')), $echteImporte);
    preg_match_all('/^use .+;$/m', $kopie, $kopierteImporte);

    expect($kopierteImporte[0])->toBe($echteImporte[0], 'The stand-in imports something else than the original.');
});
