<?php

use Goldnead\Marketing\Tests\Fixtures\MarketingDataFixture;
use Illuminate\Support\Str;

/**
 * `marketing_messages` after the column that every row used to be identified by
 * was allowed to be NULL.
 *
 * Making a NOT NULL column nullable is the one schema change SQLite cannot do
 * in place: Laravel rebuilds the whole table for it, copies the rows across and
 * re-creates the indexes. Every part of that is a chance to lose something, and
 * none of it is visible from a suite that runs `RefreshDatabase` against a
 * database migrated to head — there, the table is simply built nullable from
 * the start and the rebuild never happens.
 *
 * So this walks the real path: an install with messages in it, migrated
 * forward, then asked the three questions that would each be a silent
 * production defect.
 */
it('accepts a message that belongs to no campaign', function (): void {
    $this->migratePath($this->currentMigrations());

    (new MarketingDataFixture($this->isolated()))->seed(0);

    $subscription = $this->isolated()->table('marketing_subscriptions')->first();

    $id = $this->isolated()->table('marketing_messages')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'campaign_handle' => null,
        'template_handle' => 'welcome-sequenz-1-willkommen',
        'subscription_id' => $subscription->id,
        'brand_id' => $subscription->brand_id,
        'email' => 'template@example.com',
        'status' => 'sent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = $this->isolated()->table('marketing_messages')->find($id);

    expect($row->campaign_handle)->toBeNull()
        ->and($row->template_handle)->toBe('welcome-sequenz-1-willkommen');

    // And it is invisible to every campaign query, which is the whole reason
    // NULL was chosen over a placeholder handle. A template send is not part of
    // any campaign's numbers, and none of the existing reports had to learn
    // that.
    expect($this->isolated()->table('marketing_messages')->whereNotNull('campaign_handle')->count())
        ->toBe($this->isolated()->table('marketing_messages')->count() - 1);
});

it('carries existing messages and their indexes through the rebuild', function (): void {
    // The install as it stood before the column was touched, with data in it.
    $this->migratePath($this->releasedMigrations('v1.6.3'));

    $fixture = new MarketingDataFixture($this->isolated());
    $fixture->seed(0);

    $before = $this->isolated()->table('marketing_messages')->orderBy('id')->get()->all();

    expect($before)->not->toBeEmpty('the fixture must seed messages or this test proves nothing');

    $this->migratePath($this->currentMigrations());

    $after = $this->isolated()->table('marketing_messages')->orderBy('id')->get()->all();

    expect(count($after))->toBe(count($before));

    foreach ($before as $index => $row) {
        expect($after[$index]->uuid)->toBe($row->uuid)
            ->and($after[$index]->campaign_handle)->toBe($row->campaign_handle)
            ->and($after[$index]->email)->toBe($row->email)
            ->and($after[$index]->template_handle)->toBeNull();
    }

    // The indexes the rebuild had to carry over. Named rather than measured,
    // because an index is a performance guarantee and there is nothing
    // behavioural to assert about one — but losing them silently is exactly
    // what a table rebuild does when it goes wrong, and `campaign_handle` is
    // the column every campaign report filters on.
    $names = array_column($this->isolatedSchema()->getIndexes('marketing_messages'), 'name');

    expect($names)->toContain('marketing_messages_campaign_handle_index')
        ->and($names)->toContain('marketing_messages_campaign_variant_index')
        ->and($names)->toContain('marketing_messages_template_handle_index')
        ->and($names)->toContain('marketing_messages_uuid_unique');
});
