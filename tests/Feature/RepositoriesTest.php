<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Repositories\Eloquent\EloquentMailingListRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Driver-agnostic contract tests: run against whichever driver
 * MARKETING_DRIVER selects (flat by default; the CI matrix flips it).
 */
it('persists, finds, lists, and deletes mailing lists', function (): void {
    $repo = app(MailingListRepository::class);

    $repo->save(new MailingList(handle: 'newsletter', name: 'Newsletter', description: 'Weekly news', doubleOptIn: true));
    $repo->save(new MailingList(handle: 'updates', name: 'Updates'));

    expect($repo->all())->toHaveCount(2);

    $found = $repo->find('newsletter');

    expect($found->name)->toBe('Newsletter')
        ->and($found->description)->toBe('Weekly news')
        ->and($found->doubleOptIn)->toBeTrue();

    // Null double_opt_in falls back to the config default.
    config()->set('marketing.subscriptions.double_opt_in', false);
    expect($repo->find('updates')->usesDoubleOptIn())->toBeFalse();

    $repo->delete('updates');

    expect($repo->all())->toHaveCount(1)
        ->and($repo->find('updates'))->toBeNull();
});

it('persists campaigns with status and schedule round-tripping', function (): void {
    $repo = app(CampaignRepository::class);

    $repo->save(new Campaign(
        handle: 'welcome',
        name: 'Welcome',
        subject: 'Hi!',
        preheader: 'A warm hello',
        fromName: 'Adrian',
        fromEmail: 'adrian@example.com',
        listHandle: 'newsletter',
        templateHandle: 'branded',
        content: '<p>Hello</p>',
        status: Campaign::STATUS_SCHEDULED,
        scheduledAt: CarbonImmutable::parse('2026-07-01 09:00:00'),
    ));

    $found = $repo->find('welcome');

    expect($found->subject)->toBe('Hi!')
        ->and($found->preheader)->toBe('A warm hello')
        ->and($found->fromEmail)->toBe('adrian@example.com')
        ->and($found->listHandle)->toBe('newsletter')
        ->and($found->templateHandle)->toBe('branded')
        ->and($found->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and($found->scheduledAt->toDateTimeString())->toBe('2026-07-01 09:00:00');
});

it('returns only due scheduled campaigns from due()', function (): void {
    $repo = app(CampaignRepository::class);

    $repo->save(new Campaign(handle: 'past', name: 'Past', status: Campaign::STATUS_SCHEDULED, scheduledAt: CarbonImmutable::now()->subHour()));
    $repo->save(new Campaign(handle: 'future', name: 'Future', status: Campaign::STATUS_SCHEDULED, scheduledAt: CarbonImmutable::now()->addHour()));
    $repo->save(new Campaign(handle: 'draft', name: 'Draft'));

    $due = $repo->due(now());

    expect($due)->toHaveCount(1)
        ->and($due->first()->handle)->toBe('past');
});

it('persists templates', function (): void {
    $repo = app(EmailTemplateRepository::class);

    $repo->save(new EmailTemplate(handle: 'branded', name: 'Branded', html: '<html>{{ content }}</html>'));

    expect($repo->find('branded')->html)->toContain('{{ content }}');

    $repo->delete('branded');

    expect($repo->find('branded'))->toBeNull();
});

it('writes YAML files under the flat path when using the flat driver', function (): void {
    if (config('marketing.storage.driver') !== 'flat') {
        $this->markTestSkipped('Flat driver only.');
    }

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));

    $file = config('marketing.storage.flat.path').'/lists/newsletter.yaml';

    expect(is_file($file))->toBeTrue()
        ->and(file_get_contents($file))->toContain('name: Newsletter');
});

it('serves the same contract through the eloquent driver', function (): void {
    config()->set('marketing.storage.driver', 'eloquent');

    $repo = app(MailingListRepository::class);

    expect($repo)->toBeInstanceOf(EloquentMailingListRepository::class);

    $repo->save(new MailingList(handle: 'db_list', name: 'DB List'));

    expect($repo->find('db_list')->name)->toBe('DB List')
        ->and(MailingListRecord::query()->where('handle', 'db_list')->exists())->toBeTrue();
});

/**
 * `HasBrand` fuellt `brand_id` in einem `creating`-Hook, und `brand_id` ist
 * NOT NULL. Ein stummgeschalteter Dispatcher ist damit kein Randfall, sondern
 * ein gescheiterter INSERT — und Stummschalten ist alltaeglich: Laravels
 * eigenes `WithoutModelEvents` auf einem Seeder macht genau das.
 *
 * Am 19.09.2026 lief ein Host damit in
 * „NOT NULL constraint failed: marketing_lists.brand_id" und riss jeden
 * Seeder danach mit. Deshalb setzen die Repositories die Marke selbst.
 */
it('writes the brand even when model events are muted', function (): void {
    config()->set('marketing.storage.driver', 'eloquent');

    Model::withoutEvents(function (): void {
        app(MailingListRepository::class)->save(new MailingList(handle: 'ohne_events', name: 'Ohne Events'));
        app(EmailTemplateRepository::class)->save(new EmailTemplate(handle: 'tpl', name: 'Vorlage', html: '<p>x</p>'));
        app(CampaignRepository::class)->save(new Campaign(handle: 'kampagne', name: 'Kampagne', subject: 'Betreff'));
    });

    foreach (['marketing_lists' => 'ohne_events', 'marketing_templates' => 'tpl', 'marketing_campaigns' => 'kampagne'] as $tabelle => $handle) {
        $zeile = DB::table($tabelle)->where('handle', $handle)->first();

        expect($zeile)->not->toBeNull("{$tabelle} hat {$handle} nicht angelegt")
            ->and($zeile->brand_id)->not->toBeNull("{$tabelle}.brand_id ist leer geblieben");
    }
});

/**
 * Nur beim Anlegen. Ein Update darf eine bestehende Zeile niemals auf die
 * gerade aktive Marke umhaengen — auf einem Host mit mehreren Marken waere das
 * die Uebergabe einer Kampagne an eine fremde Marke, also genau das, wogegen
 * die Markentrennung da ist.
 */
it('does not move an existing row to the current brand on update', function (): void {
    config()->set('marketing.storage.driver', 'eloquent');

    $repo = app(MailingListRepository::class);
    $repo->save(new MailingList(handle: 'bleibt', name: 'Erst so'));

    DB::table('marketing_lists')->where('handle', 'bleibt')->update(['brand_id' => 424242]);

    $repo->save(new MailingList(handle: 'bleibt', name: 'Dann so'));

    $zeile = DB::table('marketing_lists')->where('handle', 'bleibt')->first();

    expect($zeile->brand_id)->toBe(424242)
        ->and($zeile->name)->toBe('Dann so');
});
