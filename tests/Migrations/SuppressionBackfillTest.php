<?php

use Goldnead\Marketing\Tests\Fixtures\MarketingDataFixture;
use Illuminate\Support\Str;

/**
 * The backfill, against tables that already hold rows.
 *
 * There is nothing to migrate today — the recorded volume is zero — which is the
 * entire argument for writing it now rather than after the second brand's mail
 * starts flowing through the same system. A backfill nobody has tested is a
 * backfill that will be tested on the day it matters.
 *
 * The fixture already carries one `bounced`, one `complained` and one
 * `unsubscribed` row, so the three cases are read off real seed data rather than
 * off rows written for the assertion.
 */
it('moves the deliverability facts across and leaves consent alone', function (): void {
    $this->migratePath($this->currentMigrations());

    $this->isolated()->table('suppressions')->delete();

    (new MarketingDataFixture($this->isolated()))->seed(0);

    // Re-run the backfill against the now-populated tables. This is the real
    // case: an install that migrated first and filled up afterwards is not the
    // one the migration meets on a live site.
    $this->isolated()->table('migrations')
        ->where('migration', '2026_07_30_000001_backfill_suppressions_from_marketing_state')
        ->delete();

    $this->migratePath($this->currentMigrations());

    $rows = $this->isolated()->table('suppressions')->get()->keyBy('email_normalized');

    // A bounce is a fact about the mailbox, so it lands globally.
    expect($rows)->toHaveKey('frank.lorenz@example.com')
        ->and($rows['frank.lorenz@example.com']->reason)->toBe('hard_bounce')
        ->and((int) $rows['frank.lorenz@example.com']->brand_id)->toBe(0)
        ->and($rows['frank.lorenz@example.com']->source)->toBe('backfill');

    // A complaint is a fact about a relationship, so it stays in its brand.
    expect($rows)->toHaveKey('ingo.pfeiffer@example.com')
        ->and($rows['ingo.pfeiffer@example.com']->reason)->toBe('complaint')
        ->and((int) $rows['ingo.pfeiffer@example.com']->brand_id)->toBeGreaterThan(0);

    // And the one that must not move. A per-list unsubscribe is a scoped
    // withdrawal of consent, and `marketing.unsubscribe.global_opt_out` already
    // defaults to false — somebody decided a list unsubscribe is not a global
    // opt-out. Promoting these would reverse that decision inside a migration
    // and destroy legitimate subscriptions on the brand's other lists.
    expect($rows)->not->toHaveKey('dieter.marx@example.com');

    // Every write leaves a line in the log saying where it came from.
    $events = $this->isolated()->table('suppression_events')
        ->where('event_type', 'imported')
        ->pluck('email_normalized')
        ->all();

    expect($events)->toContain('frank.lorenz@example.com')
        ->and($events)->toContain('ingo.pfeiffer@example.com');
});

it('is idempotent, so a second run changes nothing', function (): void {
    $this->migratePath($this->currentMigrations());
    $this->isolated()->table('suppressions')->delete();

    (new MarketingDataFixture($this->isolated()))->seed(0);

    $run = function (): void {
        $this->isolated()->table('migrations')
            ->where('migration', '2026_07_30_000001_backfill_suppressions_from_marketing_state')
            ->delete();
        $this->migratePath($this->currentMigrations());
    };

    $run();
    $after = $this->isolated()->table('suppressions')->count();

    $run();

    expect($this->isolated()->table('suppressions')->count())->toBe($after);
});

it('does not overrule a decision somebody already made', function (): void {
    $this->migratePath($this->currentMigrations());
    $this->isolated()->table('suppressions')->delete();

    (new MarketingDataFixture($this->isolated()))->seed(0);

    // An editor released this address deliberately, with a reason and a name on
    // it. A backfill that re-blocks it would silently overturn a signed
    // decision, which is exactly what the audit trail exists to prevent.
    $this->isolated()->table('suppressions')->insert([
        'uuid' => (string) Str::uuid(),
        'brand_id' => 0,
        'email_normalized' => 'frank.lorenz@example.com',
        'reason' => 'hard_bounce',
        'source' => 'cp',
        'suppressed_at' => now()->subDay(),
        'released_at' => now(),
        'released_by' => 'user:1',
        'release_reason' => 'Provider confirmed the mailbox was restored on 2026-07-29.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->isolated()->table('migrations')
        ->where('migration', '2026_07_30_000001_backfill_suppressions_from_marketing_state')
        ->delete();

    $this->migratePath($this->currentMigrations());

    $row = $this->isolated()->table('suppressions')
        ->where('email_normalized', 'frank.lorenz@example.com')
        ->first();

    expect($row->released_at)->not->toBeNull()
        ->and($row->released_by)->toBe('user:1');
});

it('installs without the suppression package present, rather than dying', function (): void {
    // A host can have this addon and not that one — the dependency is declared,
    // but a partially migrated install is a real state, and so is one where the
    // suppression migrations simply have not run yet.
    //
    // The rows matter: with an empty subscriptions table nothing would be
    // written and the guard would never be reached, so the test would pass on
    // an unguarded migration and prove nothing. This one seeds the bounces and
    // complaints first, then takes the destination away.
    $this->migratePath($this->currentMigrations());

    (new MarketingDataFixture($this->isolated()))->seed(0);

    $this->isolatedSchema()->drop('suppression_events');
    $this->isolatedSchema()->drop('suppressions');

    $this->isolated()->table('migrations')
        ->where('migration', '2026_07_30_000001_backfill_suppressions_from_marketing_state')
        ->delete();

    // A migration that crashes here leaves the whole addon half-installed,
    // which is a worse outcome than a backfill that does nothing.
    $this->migratePath($this->currentMigrations());

    expect($this->ranMigrations())
        ->toContain('2026_07_30_000001_backfill_suppressions_from_marketing_state')
        ->and($this->isolatedSchema()->hasTable('suppressions'))->toBeFalse();
});
