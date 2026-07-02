<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;

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

    expect($repo)->toBeInstanceOf(\Goldnead\Marketing\Repositories\Eloquent\EloquentMailingListRepository::class);

    $repo->save(new MailingList(handle: 'db_list', name: 'DB List'));

    expect($repo->find('db_list')->name)->toBe('DB List')
        ->and(\Goldnead\Marketing\Models\MailingListRecord::query()->where('handle', 'db_list')->exists())->toBeTrue();
});
