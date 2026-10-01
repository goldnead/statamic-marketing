<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * The Control Panel half of the concert-mail series: the tabs and badges of
 * the listing, the "series for terms" section of the editor, and the approval
 * summary of a waiting child. Against the real statamic-events store, like
 * CampaignSeriesTest, because what these screens show is what the sync wrote.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-events/database/migrations');

    $user = User::make()->email('serie@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    foreach ([['89077', 48.40, 9.97], ['89075', 48.67, 9.97]] as [$plz, $lat, $lng]) {
        PostalCode::query()->create([
            'country' => 'DE',
            'postal_code' => $plz,
            'latitude' => (string) $lat,
            'longitude' => (string) $lng,
        ]);
    }
});

function cpSeriesCampaign(string $status = Campaign::STATUS_SERIES, array $meta = []): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'konzertmail',
        name: 'Konzert in der Nähe',
        subject: 'Konzert in {{ event:city }}',
        listHandle: 'newsletter',
        content: '<p>Wir spielen in {{ event:city }}.</p>',
        status: $status,
        meta: $meta,
    ));

    return app(CampaignRepository::class)->find('konzertmail');
}

function cpSeriesOccurrence(array $attributes = []): Occurrence
{
    $event = Event::create(['title' => 'Anders zieht um '.substr(uniqid(), -5)]);
    $event->publish();

    return Occurrence::create(array_merge([
        'event_id' => $event->id,
        'starts_at' => CarbonImmutable::now()->addDays(30)->setTime(20, 0)->utc(),
        'timezone' => 'Europe/Berlin',
        'venue_name' => 'Roxy',
        'venue_city' => 'Ulm',
        'venue_postal_code' => '89077',
        'venue_country' => 'DE',
    ], $attributes));
}

/** @return array<string, mixed> */
function cpProps(string $route, ?string $handle = null): array
{
    $url = $handle === null ? cp_route($route) : cp_route($route, $handle);

    return json_decode(
        test()->withHeaders(['X-Inertia' => 'true'])->get($url)->assertOk()->getContent(),
        true,
    )['props'];
}

/** @return array<string, mixed> */
function cpSeriesPatch(array $overrides = []): array
{
    return array_merge([
        'name' => 'Konzert in der Nähe',
        'subject' => 'Konzert in {{ event:city }}',
        'list' => 'newsletter',
        'content' => '<p>Wir spielen in {{ event:city }}.</p>',
    ], $overrides);
}

it('zählt Serien und wartende Kampagnen in den Reitern der Liste', function (): void {
    cpSeriesCampaign();
    cpSeriesOccurrence();
    cpSeriesOccurrence(['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075']);

    $props = cpProps('marketing.campaigns.index');
    $tabs = collect($props['tabs'])->keyBy('name');

    expect($tabs['all']['count'])->toBe(3)
        ->and($tabs['awaiting_approval']['count'])->toBe(2)
        ->and($tabs['series']['count'])->toBe(1);

    $rows = collect($props['campaigns'])->keyBy('handle');

    expect($rows['konzertmail']['status_label'])->toBe('Series')
        ->and($rows['konzertmail']['children_count'])->toBe(2);

    $child = $rows->first(fn (array $row): bool => $row['status'] === Campaign::STATUS_AWAITING_APPROVAL);

    expect($child['status_label'])->toBe('Awaiting approval')
        ->and($child['series'])->toBe('konzertmail')
        ->and($child['event']['city'])->not->toBeEmpty()
        ->and($child['recipients'])->toBeNull();
});

it('spricht die Status in der Sprache des CP', function (): void {
    app()->setLocale('de');
    cpSeriesCampaign();
    cpSeriesOccurrence();

    $labels = collect(cpProps('marketing.campaigns.index')['campaigns'])->pluck('status_label', 'status');

    expect($labels['series'])->toBe('Serie')
        ->and($labels['awaiting_approval'])->toBe('Wartet auf Freigabe');
});

it('zeigt im Editor der Vorlage Einstellungen, erzeugte Kampagnen und Termine ohne PLZ', function (): void {
    cpSeriesCampaign(meta: ['series' => ['radius_km' => 30]]);
    $occurrence = cpSeriesOccurrence();
    cpSeriesOccurrence(['venue_postal_code' => null, 'venue_city' => 'Irgendwo']);
    cpSeriesOccurrence(['venue_postal_code' => '', 'venue_city' => 'Nirgendwo']);

    $series = cpProps('marketing.campaigns.edit', 'konzertmail')['series'];

    expect($series['available'])->toBeTrue()
        ->and($series['is_child'])->toBeFalse()
        ->and($series['enabled'])->toBeTrue()
        ->and($series['can_toggle'])->toBeFalse() // hat schon ein Kind
        ->and($series['settings']['radius_km'])->toBe(30)
        ->and($series['settings']['days_before'])->toBe(7)
        ->and($series['skipped_no_postal_code'])->toBe(2)
        ->and($series['children'])->toHaveCount(1)
        ->and($series['children'][0]['id'])->toBe('konzertmail-'.$occurrence->uuid)
        ->and($series['children'][0]['city'])->toBe('Ulm')
        ->and($series['children'][0]['status'])->toBe(Campaign::STATUS_AWAITING_APPROVAL)
        ->and(collect($series['events'])->pluck('value'))->toContain($occurrence->event->uuid)
        ->and(collect($series['columns'])->pluck('field')->all())
        ->toBe(['city', 'term', 'scheduled_at', 'status', 'recipients']);
});

it('macht aus einem Entwurf per Schalter eine Vorlage und legt die Kinder sofort an', function (): void {
    cpSeriesCampaign(Campaign::STATUS_DRAFT);
    $occurrence = cpSeriesOccurrence();

    expect(app(CampaignRepository::class)->find('konzertmail-'.$occurrence->uuid))->toBeNull();

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), cpSeriesPatch([
        'series_enabled' => true,
        'series' => [
            'radius_km' => 40,
            'days_before' => 5,
            'send_time' => '09:30',
            'event_ids' => [],
            'country' => 'de',
        ],
    ]))->assertSessionHasNoErrors()->assertSessionHas('success');

    $template = app(CampaignRepository::class)->find('konzertmail');

    expect($template->status)->toBe(Campaign::STATUS_SERIES)
        ->and($template->meta['series'])->toMatchArray([
            'radius_km' => 40,
            'days_before' => 5,
            'send_time' => '09:30',
            'event_ids' => [],
            'country' => 'DE',
        ]);

    $child = app(CampaignRepository::class)->find('konzertmail-'.$occurrence->uuid);

    expect($child)->not->toBeNull()
        ->and($child->scheduledAt->equalTo($occurrence->localStart()->subDays(5)->setTime(9, 30)->utc()))->toBeTrue();
});

it('macht eine Vorlage ohne Kinder wieder zum Entwurf', function (): void {
    cpSeriesCampaign(meta: ['series' => ['radius_km' => 30]]);

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), cpSeriesPatch([
        'series_enabled' => false,
    ]))->assertSessionHasNoErrors();

    $campaign = app(CampaignRepository::class)->find('konzertmail');

    expect($campaign->status)->toBe(Campaign::STATUS_DRAFT)
        ->and($campaign->meta)->not->toHaveKey('series');
});

it('lässt eine Vorlage mit Kindern nicht zum Entwurf werden', function (): void {
    cpSeriesCampaign();
    cpSeriesOccurrence();

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), cpSeriesPatch([
        'series_enabled' => false,
    ]))->assertSessionHasErrors('series_enabled');

    expect(app(CampaignRepository::class)->find('konzertmail')->status)->toBe(Campaign::STATUS_SERIES);
});

it('macht nur Entwürfe zur Vorlage', function (): void {
    cpSeriesCampaign(Campaign::STATUS_SCHEDULED);

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), cpSeriesPatch([
        'series_enabled' => true,
    ]))->assertSessionHasErrors('series_enabled');

    expect(app(CampaignRepository::class)->find('konzertmail')->status)->toBe(Campaign::STATUS_SCHEDULED);
});

it('weist eine unmögliche Uhrzeit und einen Umkreis von null zurück', function (): void {
    cpSeriesCampaign(Campaign::STATUS_DRAFT);

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), cpSeriesPatch([
        'series_enabled' => true,
        'series' => ['send_time' => '25:00', 'radius_km' => 0],
    ]))->assertSessionHasErrors(['series.send_time', 'series.radius_km']);

    expect(app(CampaignRepository::class)->find('konzertmail')->status)->toBe(Campaign::STATUS_DRAFT);
});

it('behält beim Speichern der Vorlage die hinterlegten Beispielwerte', function (): void {
    cpSeriesCampaign(meta: ['series' => ['preview_event' => ['city' => 'Probestadt']]]);

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), cpSeriesPatch([
        'series_enabled' => true,
        'series' => ['radius_km' => 20],
    ]))->assertSessionHasNoErrors();

    $meta = app(CampaignRepository::class)->find('konzertmail')->meta['series'];

    expect($meta['preview_event'])->toBe(['city' => 'Probestadt'])
        ->and($meta['radius_km'])->toBe(20);
});

it('zeigt einem Kind nur den Weg zur Vorlage', function (): void {
    cpSeriesCampaign();
    $occurrence = cpSeriesOccurrence();

    $series = cpProps('marketing.campaigns.edit', 'konzertmail-'.$occurrence->uuid)['series'];

    expect($series['is_child'])->toBeTrue()
        ->and($series['template']['name'])->toBe('Konzert in der Nähe')
        ->and($series)->not->toHaveKey('children');
});

it('fasst auf der Seite einer wartenden Kampagne zusammen, was rausgeht', function (): void {
    cpSeriesCampaign();
    $occurrence = cpSeriesOccurrence();

    // Ein Kontakt in ~30 km, einer am Ort: beide im 50-km-Umkreis.
    foreach (['nah@example.com' => '89075', 'ort@example.com' => '89077'] as $email => $plz) {
        Contact::create(['email' => $email, 'status' => 'qualified', 'postal_code' => $plz, 'country' => 'DE']);
    }

    $props = cpProps('marketing.campaigns.show', 'konzertmail-'.$occurrence->uuid);
    $approval = $props['approval'];

    expect($props['statusLabel'])->toBe('Awaiting approval')
        ->and($approval['subject'])->toBe('Konzert in Ulm')
        ->and($approval['list'])->toBe('Newsletter')
        ->and($approval['recipients'])->toBe(2)
        ->and($approval['event']['city'])->toBe('Ulm')
        ->and($approval['scheduled_at'])->not->toBeNull()
        ->and($approval['template']['name'])->toBe('Konzert in der Nähe')
        ->and($approval['approve_url'])->toBe(cp_route('marketing.campaigns.approve', 'konzertmail-'.$occurrence->uuid))
        ->and($approval['can_send'])->toBeTrue();
});

it('hat für gewöhnliche Kampagnen keine Freigabe', function (): void {
    cpSeriesCampaign(Campaign::STATUS_DRAFT);

    expect(cpProps('marketing.campaigns.show', 'konzertmail')['approval'])->toBeNull();
});

it('rendert die Vorschau einer gerade eingeschalteten Vorlage mit dem Beispieltermin', function (): void {
    cpSeriesCampaign(Campaign::STATUS_DRAFT);

    $this->postJson(cp_route('marketing.campaigns.live-preview'), [
        'handle' => 'konzertmail',
        'name' => 'Konzert',
        'subject' => 'Konzert in {{ event:city }}',
        'content' => '<p>Wir spielen in {{ event:city }}.</p>',
        'list_handle' => 'newsletter',
        'series' => true,
    ])->assertOk()->assertJsonPath('data.error', null);

    expect($this->postJson(cp_route('marketing.campaigns.live-preview'), [
        'handle' => 'konzertmail',
        'name' => 'Konzert',
        'content' => '<p>Wir spielen in {{ event:city }}.</p>',
        'list_handle' => 'newsletter',
        'series' => true,
    ])->json('data.html'))->toContain('Wir spielen in Ulm.');
});

it('rendert die Vorschau eines Kindes mit seiner eigenen Stadt', function (): void {
    cpSeriesCampaign();
    $occurrence = cpSeriesOccurrence(['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075']);

    expect($this->postJson(cp_route('marketing.campaigns.live-preview'), [
        'handle' => 'konzertmail-'.$occurrence->uuid,
        'name' => 'Kind',
        'content' => '<p>Wir spielen in {{ event:city }}.</p>',
        'list_handle' => 'newsletter',
    ])->json('data.html'))->toContain('Wir spielen in Neu-Ulm.');
});
