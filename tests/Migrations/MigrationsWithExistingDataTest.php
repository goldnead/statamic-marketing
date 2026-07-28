<?php

use Goldnead\Marketing\Tests\Fixtures\MarketingDataFixture;
use Goldnead\Marketing\Tests\MigrationPathTestCase;
use Illuminate\Database\QueryException;

/**
 * The migrations, run against a database that already holds data.
 *
 * Every migration check this addon had ran against empty tables, because every
 * bed it had was a fresh install. That is not a thin spot in the coverage, it
 * is the coverage pointing away from the only case a migration can be wrong
 * about: a table with rows in it, created by an older release.
 *
 * What it let through: 1.6.1 added `uniqueness_key` to the *already published*
 * create-table migration and, in the same commit, rewrote the *already
 * published* brand-scoping migration to build the consent unique over that new
 * column — with no `hasColumn` guard. On a fresh install the column is there,
 * because the create-table migration now makes it, and the suite stayed green
 * through three releases. On an install created before 1.3.0 the column does
 * not exist when the brand migration runs, and the migration dies at the
 * statement *after* the one that drops `(list_handle, email_normalized)`.
 * Neither engine rolls that back. What is left is a subscriptions table with
 * no consent unique at all, and a migration not recorded as run.
 *
 * The cases below cover the three states an install can be in — never ran it,
 * ran it successfully, stopped in the middle of it. The last one is produced by
 * actually running the published 1.6.3 migrations out of
 * `tests/Fixtures/released-migrations/` and watching them die, rather than by
 * writing down what we think they leave behind.
 *
 * Every assertion about the consent guarantee is behavioural. "The migration
 * ran" and "the constraint is there" are not the same statement, and mistaking
 * one for the other is the entire defect — so nothing here checks an exit code
 * or an index name. It writes the row the constraint is supposed to refuse and
 * requires the database to refuse it.
 */
it('runs every migration against tables that already hold rows', function (): void {
    $fixture = new MarketingDataFixture($this->isolated());
    $batch = 0;

    // Seed before each migration, not just at the start: a migration that only
    // ever meets rows written by *earlier* migrations' schema is still only
    // being tested against a fresh install with a bit of data in it. Every
    // migration in the directory is covered, including ones added after this
    // test was written — that is the point of walking the directory rather
    // than naming the two files that were broken.
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    $probe = MarketingDataFixture::consentProbe($batch - 1);

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))
        ->toBeFalse('the consent unique does not bite after a stepwise migration over populated tables');

    expect($this->isolatedSchema()->hasColumn('marketing_subscriptions', 'uniqueness_key'))->toBeTrue();
});

it('upgrades a populated install from every released schema', function (string $version): void {
    // The install as it stood on that release, with its data.
    $this->migratePath($this->releasedMigrations($version));

    $fixture = new MarketingDataFixture($this->isolated());
    $seeded = $fixture->seed(0);

    expect($seeded)->toBe(12);

    $before = $fixture->counts();

    // Then the upgrade, with the tables filling up further as it goes.
    $batch = 1;
    $this->migrateStepwise($this->currentMigrations(), function () use ($fixture, &$batch): void {
        $fixture->seed($batch++);
    });

    $probe = MarketingDataFixture::consentProbe(0);

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))
        ->toBeFalse("the consent unique does not bite after upgrading a populated {$version} install");

    // Nothing that was there before may have gone missing.
    foreach ($before as $table => $count) {
        expect($this->isolated()->table($table)->count())
            ->toBeGreaterThanOrEqual($count, "rows disappeared from {$table}");
    }

    expect($this->isolated()->table('marketing_subscriptions')->whereNull('brand_id')->count())->toBe(0);
})->with(['v1.2.1', 'v1.6.0', 'v1.6.3']);

it('recovers an install that the 1.6.1 migration stopped halfway through', function (): void {
    // 1. An install from before the brand migration existed, with real data.
    $this->migratePath($this->releasedMigrations('v1.2.1'));

    $fixture = new MarketingDataFixture($this->isolated());
    $fixture->seed(0);

    $probe = MarketingDataFixture::consentProbe(0);

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))
        ->toBeFalse('the fixture install should start with a working consent unique');

    // 2. Update to 1.6.3, exactly as published. It dies.
    expect(fn () => $this->migratePath($this->releasedMigrations('v1.6.3')))
        ->toThrow(QueryException::class);

    // 3. The damage, which is the part nobody sees: the migration is not
    //    recorded, and the consent unique is gone and was not replaced.
    expect($this->ranMigrations())->not->toContain('2026_07_24_100001_add_brand_id_to_marketing_tables');

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))
        ->toBeTrue('1.6.3 was expected to leave the subscriptions table with no consent unique');

    // 4. The fix has to pick that state up and finish it.
    $this->migratePath($this->currentMigrations());

    expect($this->ranMigrations())->toContain('2026_07_24_100001_add_brand_id_to_marketing_tables');

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))
        ->toBeFalse('the consent unique was not restored on a half-migrated install');

    expect($this->isolated()->table('marketing_subscriptions')->count())->toBe(12);
    expect($this->isolated()->table('marketing_subscriptions')->whereNull('brand_id')->count())->toBe(0);
    expect($this->isolated()->table('marketing_subscriptions')->whereNull('uniqueness_key')->count())->toBe(0);
});

it('names the duplicates instead of deleting them when the gap was used', function (): void {
    $this->migratePath($this->releasedMigrations('v1.2.1'));

    $fixture = new MarketingDataFixture($this->isolated());
    $fixture->seed(0);

    expect(fn () => $this->migratePath($this->releasedMigrations('v1.6.3')))->toThrow(QueryException::class);

    // With the unique gone, the same person signs up for the same list again —
    // which is what actually happens on a live site between the failed update
    // and somebody noticing.
    $probe = MarketingDataFixture::consentProbe(0);
    $this->writeDuplicate($probe['list'], $probe['email']);

    expect($this->isolated()->table('marketing_subscriptions')->count())->toBe(13);

    // The migration must not pick a winner, and must not fail with a driver
    // error either. It has to say what it found.
    expect(fn () => $this->migratePath($this->currentMigrations()))
        ->toThrow(RuntimeException::class, $probe['email']);

    // And it must not have deleted anything on the way out.
    expect($this->isolated()->table('marketing_subscriptions')->count())->toBe(13);
});

it('reports a half-migrated install through the consent integrity command', function (): void {
    $this->migratePath($this->releasedMigrations('v1.2.1'));

    (new MarketingDataFixture($this->isolated()))->seed(0);

    expect(fn () => $this->migratePath($this->releasedMigrations('v1.6.3')))->toThrow(QueryException::class);

    $probe = MarketingDataFixture::consentProbe(0);
    $this->writeDuplicate($probe['list'], $probe['email']);

    $this->artisan('marketing:consent-integrity', ['--database' => MigrationPathTestCase::CONNECTION])
        ->expectsOutputToContain('no unique index')
        ->expectsOutputToContain($probe['email'])
        ->assertExitCode(1);

    // Reporting is all it does. Nothing was repaired and nothing was removed.
    expect($this->isolated()->table('marketing_subscriptions')->count())->toBe(13);
});

it('rebuilds the consent unique once the duplicates are gone', function (): void {
    $this->migratePath($this->releasedMigrations('v1.2.1'));

    (new MarketingDataFixture($this->isolated()))->seed(0);

    expect(fn () => $this->migratePath($this->releasedMigrations('v1.6.3')))->toThrow(QueryException::class);

    $probe = MarketingDataFixture::consentProbe(0);
    $duplicateId = $this->writeDuplicate($probe['list'], $probe['email']);

    $this->artisan('marketing:consent-integrity', [
        '--database' => MigrationPathTestCase::CONNECTION,
        '--repair' => true,
    ])->assertExitCode(1);

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))
        ->toBeTrue('--repair must refuse while duplicates exist rather than build a unique that cannot hold');

    // The operator resolves it by hand, which is the only party that can.
    $this->isolated()->table('marketing_subscriptions')->where('id', $duplicateId)->delete();

    $this->artisan('marketing:consent-integrity', [
        '--database' => MigrationPathTestCase::CONNECTION,
        '--repair' => true,
    ])->assertExitCode(0);

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))->toBeFalse();

    // And the outstanding migrations run afterwards without complaint.
    $this->migratePath($this->currentMigrations());

    expect($this->duplicateConsentIsAccepted($probe['list'], $probe['email']))->toBeFalse();
});

it('confirms the guarantee on a healthy install', function (): void {
    $this->migratePath($this->currentMigrations());

    (new MarketingDataFixture($this->isolated()))->seed(0);

    $this->artisan('marketing:consent-integrity', ['--database' => MigrationPathTestCase::CONNECTION])
        ->expectsOutputToContain('one consent record')
        ->assertExitCode(0);
});
