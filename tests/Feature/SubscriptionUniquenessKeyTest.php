<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The consent unique after it moved onto a fixed-width key.
 *
 * `IndexKeyLengthTest` measures the schema; this file exercises it. Two things
 * have to remain true: the constraint still bites exactly where it did, and the
 * upgrade path reaches an install that already ran the original migrations —
 * which is everyone, since a published migration never runs a second time.
 */
beforeEach(function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(fn () => Subscription::query()->delete());
});

it('still refuses a second subscription for the same address on the same list', function (): void {
    BrandContext::setCurrent($this->brandA);

    Subscription::create(['list_handle' => 'newsletter', 'email' => 'jane@example.com']);

    expect(fn () => Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'JANE@example.com',
    ]))->toThrow(QueryException::class);
});

it('still lets two brands hold the same address on the same list', function (): void {
    $a = BrandContext::runFor($this->brandA, fn () => Subscription::create([
        'list_handle' => 'newsletter', 'email' => 'shared@example.com',
    ]));

    $b = BrandContext::runFor($this->brandB, fn () => Subscription::create([
        'list_handle' => 'newsletter', 'email' => 'shared@example.com',
    ]));

    // Same key, different brand: the tenant boundary is the index column, not
    // an ingredient of the hash, which is what keeps it visible in the schema.
    expect($a->uniqueness_key)->toBe($b->uniqueness_key)
        ->and($a->brand_id)->not->toBe($b->brand_id);
});

it('keys on the normalised address, so two spellings of it are one consent', function (): void {
    expect(Subscription::uniquenessKeyFor('newsletter', 'Jane@Example.com'))
        ->toBe(Subscription::uniquenessKeyFor('newsletter', 'jane@example.com'));

    // And two different lists are never one, which a prefix index of the same
    // width could not have promised.
    expect(Subscription::uniquenessKeyFor('newsletter', 'jane@example.com'))
        ->not->toBe(Subscription::uniquenessKeyFor('newsletter_weekly', 'jane@example.com'));
});

it('re-keys a subscription whose address is corrected', function (): void {
    BrandContext::setCurrent($this->brandA);

    $subscription = Subscription::create(['list_handle' => 'newsletter', 'email' => 'typo@example.com']);
    $before = $subscription->uniqueness_key;

    $subscription->update(['email' => 'right@example.com']);

    expect($subscription->fresh()->uniqueness_key)
        ->not->toBe($before)
        ->toBe(Subscription::uniquenessKeyFor('newsletter', 'right@example.com'));
});

it('upgrades an install that still carries the wide index', function (): void {
    // Put the table back into its 1.6.0 shape — no key column, the unique over
    // (brand_id, list_handle, email_normalized) — and fill it the way a real
    // install would be filled.
    Schema::table('marketing_subscriptions', function (Blueprint $table) {
        $table->dropUnique('ms_brand_list_email_unique');
        $table->dropColumn('uniqueness_key');
    });

    Schema::table('marketing_subscriptions', function (Blueprint $table) {
        $table->unique(['brand_id', 'list_handle', 'email_normalized'], 'ms_brand_list_email_unique');
    });

    DB::table('marketing_subscriptions')->insert([
        ['uuid' => 'u1', 'brand_id' => $this->brandA->id, 'list_handle' => 'newsletter', 'email' => 'Jane@Example.com', 'email_normalized' => 'jane@example.com', 'status' => 'subscribed', 'token' => 't1'],
        ['uuid' => 'u2', 'brand_id' => $this->brandB->id, 'list_handle' => 'newsletter', 'email' => 'jane@example.com', 'email_normalized' => 'jane@example.com', 'status' => 'pending', 'token' => 't2'],
        ['uuid' => 'u3', 'brand_id' => $this->brandA->id, 'list_handle' => 'offers', 'email' => 'bob@example.com', 'email_normalized' => 'bob@example.com', 'status' => 'subscribed', 'token' => 't3'],
    ]);

    migration()->up();

    // Every row carries the key its own values produce, and no row was lost.
    $rows = DB::table('marketing_subscriptions')->orderBy('uuid')->get();

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect($row->uniqueness_key)
            ->toBe(Subscription::uniquenessKeyFor($row->list_handle, $row->email));
    }

    // Two brands holding the same address kept both rows, which is the whole
    // reason the brand stays a column of the index.
    expect($rows->where('uniqueness_key', Subscription::uniquenessKeyFor('newsletter', 'jane@example.com')))
        ->toHaveCount(2);

    expect(indexColumns('ms_brand_list_email_unique'))->toBe(['brand_id', 'uniqueness_key']);

    // And it constrains: the same address on the same list in the same brand
    // is refused, on a table that was migrated rather than created.
    expect(fn () => DB::table('marketing_subscriptions')->insert([
        'uuid' => 'u4',
        'brand_id' => $this->brandA->id,
        'list_handle' => 'newsletter',
        'email' => 'jane@example.com',
        'email_normalized' => 'jane@example.com',
        'uniqueness_key' => Subscription::uniquenessKeyFor('newsletter', 'jane@example.com'),
        'status' => 'pending',
        'token' => 't4',
    ]))->toThrow(QueryException::class);
});

it('does nothing when it has already run, and nothing on a fresh install', function (): void {
    // The suite migrated from scratch, so the table is already in its new
    // shape. Running the upgrade migration must be a no-op — twice.
    $before = indexColumns('ms_brand_list_email_unique');

    migration()->up();
    migration()->up();

    expect(indexColumns('ms_brand_list_email_unique'))->toBe($before)
        ->and($before)->toBe(['brand_id', 'uniqueness_key']);
});

/** The migration under test, loaded from the file the installer ships. */
function migration(): object
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_07_28_000002_rebuild_subscription_uniqueness_keys.php';
}

/** The columns an index on marketing_subscriptions currently covers. */
function indexColumns(string $name): ?array
{
    return collect(Schema::getIndexes('marketing_subscriptions'))
        ->firstWhere('name', $name)['columns'] ?? null;
}
