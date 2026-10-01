<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Series\SeriesSync;
use Goldnead\Marketing\Services\BlockLayoutCompiler;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\TemplatePreview;
use Goldnead\Marketing\Support\LayoutBlocks;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Statamic\Facades\User;

/**
 * Paket 2 of the series: the presale anchor, the richer term snapshot, the
 * "Weitere Konzerte" list, the two mail blocks after the ANDERS mails, and
 * the LeadHub `managed_by` mark.
 *
 * The clock is fixed to 01.10.2026 noon (Berlin), so the dates below are
 * real weekdays: 17.10.2026 is the Saturday of the Oberndorf mail.
 *
 * The presale and `managed_by` tests need statamic-events 2.7 and
 * statamic-leadhub 2.15. Against older siblings they are skipped, and the
 * last test here proves the sync then still works without the mark.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Berlin'));
    Mail::fake();

    $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-events/database/migrations');

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    // Ulm, ~30 km north of it, Heidenheim (~78 km), Freiburg (~150 km).
    foreach ([
        ['89077', 48.40, 9.97], ['89075', 48.67, 9.97], ['89522', 49.10, 9.97], ['79098', 47.99, 7.85],
    ] as [$plz, $lat, $lng]) {
        PostalCode::query()->create(['country' => 'DE', 'postal_code' => $plz, 'latitude' => (string) $lat, 'longitude' => (string) $lng]);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function presaleTemplate(array $settings = [], string $handle = 'vvk'): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: $handle,
        name: 'VVK-Start',
        subject: 'Vorverkauf für {{ event:city }}',
        listHandle: 'newsletter',
        content: '<p>Ab {{ event:presale_date }}.</p>',
        status: Campaign::STATUS_SERIES,
        meta: ['series' => $settings],
    ));

    return app(CampaignRepository::class)->find($handle);
}

function termEvent(): Event
{
    $event = Event::create(['title' => 'So kurz davor '.substr(uniqid(), -5)]);
    $event->publish();

    return $event;
}

function term(Event $event, string $localStart, array $attributes = []): Occurrence
{
    return Occurrence::create(array_merge([
        'event_id' => $event->id,
        'starts_at' => CarbonImmutable::parse($localStart, 'Europe/Berlin')->utc(),
        'timezone' => 'Europe/Berlin',
        'venue_name' => 'Roxy',
        'venue_address' => 'Schillerstraße 1',
        'venue_city' => 'Ulm',
        'venue_postal_code' => '89077',
        'venue_country' => 'DE',
        'tickets_url' => 'https://tickets.example.com/ulm',
    ], $attributes));
}

function presaleSupported(): bool
{
    return Schema::hasColumn('event_occurrences', 'presale_starts_at');
}

function childOf(string $template, Occurrence $occurrence): ?Campaign
{
    return app(CampaignRepository::class)->find($template.'-'.$occurrence->uuid);
}

// --- Snapshot ------------------------------------------------------------

it('schreibt Wochentag, kurzes Datum, Uhrzeit als Wort und die Straße in die Momentaufnahme', function (): void {
    presaleTemplate(['anchor' => 'concert'], 'konzert');
    $even = term(termEvent(), '2026-10-17 20:00');
    $half = term(termEvent(), '2026-10-23 20:30', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075']);

    $event = childOf('konzert', $even)->meta['event'];

    expect($event['weekday'])->toBe('Samstag')
        ->and($event['date_short'])->toBe('17.10.26')
        ->and($event['time_label'])->toBe('20 Uhr')
        ->and($event['street'])->toBe('Schillerstraße 1')
        ->and(childOf('konzert', $half)->meta['event']['time_label'])->toBe('20:30 Uhr')
        ->and(childOf('konzert', $half)->meta['event']['weekday'])->toBe('Freitag');
});

// --- Presale anchor ------------------------------------------------------

it('plant eine VVK-Mail auf den Tag des Vorverkaufsstarts zur Versandzeit', function (): void {
    if (! presaleSupported()) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    presaleTemplate(['anchor' => 'presale', 'days_after_presale' => 2, 'send_time' => '09:00']);
    $occurrence = term(termEvent(), '2027-03-14 20:00', [
        'presale_starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Berlin')->utc(),
    ]);

    $child = childOf('vvk', $occurrence);

    expect($child)->not->toBeNull()
        ->and($child->scheduledAt->equalTo(CarbonImmutable::parse('2026-11-04 09:00', 'Europe/Berlin')))->toBeTrue()
        ->and($child->meta['event']['presale_date'])->toBe('02.11.2026');
});

it('benennt das Segment einer VVK-Serie als Vorverkauf, auch nach dem Umstellen', function (): void {
    if (! presaleSupported()) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    $template = presaleTemplate(['anchor' => 'concert']);
    $occurrence = term(termEvent(), '2027-03-14 20:00', [
        'presale_starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Berlin')->utc(),
    ]);
    $handle = 'series-vvk-'.$occurrence->uuid;

    expect(app(SegmentRepository::class)->findByHandle($handle)->name)->toStartWith('Konzert:');

    $template->meta = ['series' => ['anchor' => 'presale']];
    app(CampaignRepository::class)->save($template);
    app(SeriesSync::class)->syncAll();

    expect(app(SegmentRepository::class)->findByHandle($handle)->name)->toBe('Vorverkauf: Ulm 89077 (50 km)');
});

it('schickt eine VVK-Mail nie vor dem Beginn des Vorverkaufs', function (): void {
    if (! presaleSupported()) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    presaleTemplate(['anchor' => 'presale', 'days_after_presale' => 0, 'send_time' => '10:00']);
    $occurrence = term(termEvent(), '2027-03-14 20:00', [
        'presale_starts_at' => CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Berlin')->utc(),
    ]);

    expect(childOf('vvk', $occurrence)->scheduledAt->equalTo(CarbonImmutable::parse('2026-11-02 12:00', 'Europe/Berlin')))->toBeTrue();
});

it('überspringt Termine ohne Vorverkaufsdatum und zählt sie', function (): void {
    presaleTemplate(['anchor' => 'presale']);
    $occurrence = term(termEvent(), '2027-03-14 20:00');

    $result = app(SeriesSync::class)->syncAll();

    expect(childOf('vvk', $occurrence))->toBeNull()
        ->and($result['skipped_no_presale'])->toBe(1)
        ->and(app(SeriesSync::class)->missingPresale(app(CampaignRepository::class)->find('vvk')))->toBe(1);
});

it('sendet sofort nach Freigabe, wenn der Vorverkauf schon läuft und das Konzert noch kommt', function (): void {
    if (! presaleSupported()) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    presaleTemplate(['anchor' => 'presale']);
    $occurrence = term(termEvent(), '2027-03-14 20:00', [
        'presale_starts_at' => CarbonImmutable::parse('2026-09-01 10:00', 'Europe/Berlin')->utc(),
    ]);

    $child = childOf('vvk', $occurrence);

    expect($child->status)->toBe(Campaign::STATUS_AWAITING_APPROVAL)
        ->and($child->scheduledAt)->toBeNull();
});

it('legt die VVK-Mail an, sobald ein Termin ein Vorverkaufsdatum bekommt', function (): void {
    if (! presaleSupported()) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    presaleTemplate(['anchor' => 'presale']);
    $occurrence = term(termEvent(), '2027-03-14 20:00');

    expect(childOf('vvk', $occurrence))->toBeNull();

    // OccurrencePresaleChanged, nothing else: the listener does the rest.
    $occurrence->update(['presale_starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'Europe/Berlin')->utc()]);

    expect(childOf('vvk', $occurrence))->not->toBeNull();
});

// --- Weitere Konzerte ----------------------------------------------------

it('listet spätere Termine in der Nähe, nicht frühere, ferne oder abgesagte', function (): void {
    presaleTemplate(['anchor' => 'concert', 'more_radius_km' => 100, 'more_limit' => 3], 'konzert');
    $event = termEvent();

    $main = term($event, '2026-10-17 20:00');
    term($event, '2026-10-10 20:00', ['venue_city' => 'Früher', 'venue_postal_code' => '89075']);
    term($event, '2026-11-07 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075', 'venue_name' => 'Wiley']);
    term($event, '2026-11-21 20:30', ['venue_city' => 'Heidenheim', 'venue_postal_code' => '89522', 'venue_name' => 'Lokschuppen']);
    term($event, '2026-11-14 20:00', ['venue_city' => 'Freiburg', 'venue_postal_code' => '79098']);
    term($event, '2026-11-10 20:00', ['venue_city' => 'Abgesagt', 'venue_postal_code' => '89075'])->cancel();

    $more = childOf('konzert', $main->fresh())->meta['more_events'];

    expect(collect($more)->pluck('city')->all())->toBe(['Neu-Ulm', 'Heidenheim'])
        ->and($more[0]['venue'])->toBe('Wiley')
        ->and($more[1]['time_label'])->toBe('20:30 Uhr');
});

it('nimmt höchstens more_limit weitere Konzerte und keine, wenn ausgeschaltet', function (): void {
    presaleTemplate(['more_limit' => 1], 'eins');
    presaleTemplate(['more_enabled' => false], 'aus');
    $event = termEvent();

    $main = term($event, '2026-10-17 20:00');
    term($event, '2026-11-07 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075']);
    term($event, '2026-11-21 20:00', ['venue_city' => 'Heidenheim', 'venue_postal_code' => '89522']);

    expect(childOf('eins', $main)->meta['more_events'])->toHaveCount(1)
        ->and(childOf('eins', $main)->meta['more_events'][0]['city'])->toBe('Neu-Ulm')
        ->and(childOf('aus', $main)->meta['more_events'])->toBe([]);
});

it('zieht die weiteren Konzerte eines Kindes nach, wenn ein neuer Termin dazukommt', function (): void {
    presaleTemplate([], 'konzert');
    $event = termEvent();
    $main = term($event, '2026-10-17 20:00');

    expect(childOf('konzert', $main)->meta['more_events'])->toBe([]);

    term($event, '2026-11-07 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075']);

    expect(collect(childOf('konzert', $main)->meta['more_events'])->pluck('city')->all())->toBe(['Neu-Ulm']);
});

// --- Bausteine -----------------------------------------------------------

it('rendert Terminkasten und weitere Konzerte als Mail', function (): void {
    $compiler = app(BlockLayoutCompiler::class);
    $blocks = [
        ['type' => LayoutBlocks::SET_CONTENT],
        ['type' => LayoutBlocks::SET_EVENT_BOX, 'background' => '#f9f4f5', 'accent' => '#865561', 'button_color' => '#1d3635'],
        ['type' => LayoutBlocks::SET_MORE_EVENTS],
        ['type' => LayoutBlocks::SET_FOOTER],
    ];

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'anders',
        name: 'ANDERS',
        html: $compiler->compile($blocks),
        type: EmailTemplate::TYPE_BLOCKS,
        blocks: $blocks,
    ));

    $template = presaleTemplate([], 'konzert');
    $template->templateHandle = 'anders';
    app(CampaignRepository::class)->save($template);

    $event = termEvent();
    $main = term($event, '2026-10-17 20:00', ['venue_name' => 'Kultur & Co']);
    term($event, '2026-11-07 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075', 'tickets_url' => 'https://tickets.example.com/neu-ulm']);

    $list = app(MailingListRepository::class)->find('newsletter');
    $html = app(CampaignRenderer::class)->render(childOf('konzert', $main), $list)->html;

    expect($html)->toContain('Samstag, den 17.10.26 um 20 Uhr')
        ->and($html)->toContain('Kultur &amp; Co')
        ->and($html)->toContain('Schillerstraße 1<br>89077 Ulm')
        ->and($html)->toContain('href="https://tickets.example.com/ulm"')
        ->and($html)->toContain('Tickets buchen')
        ->and($html)->toContain('bgcolor="#f9f4f5"')
        ->and($html)->toContain('color:#865561')
        ->and($html)->toContain('Weitere Konzerte in deiner Nähe')
        ->and($html)->toContain('Neu-Ulm')
        ->and($html)->toContain('href="https://tickets.example.com/neu-ulm"');

    // A campaign without a term: neither block leaves a trace.
    $plain = new Campaign(handle: 'plain', name: 'Plain', subject: 'x', listHandle: 'newsletter', templateHandle: 'anders', content: '<p>Hallo</p>');
    $plainHtml = app(CampaignRenderer::class)->render($plain, $list)->html;

    expect($plainHtml)->not->toContain('Tickets buchen')
        ->and($plainHtml)->not->toContain('Weitere Konzerte')
        ->and($plainHtml)->toContain('Hallo');
});

it('lässt den Knopf weg, wenn ein Termin keinen Ticket-Link hat, und nimmt sonst die Farben des Layouts', function (): void {
    $html = app(BlockLayoutCompiler::class)->compile([['type' => LayoutBlocks::SET_EVENT_BOX]]);

    expect($html)->toContain('{{ if event:tickets_url }}')
        ->and($html)->toContain('bgcolor="'.BlockLayoutCompiler::THEME['soft'].'"')
        ->and($html)->toContain('color:'.BlockLayoutCompiler::THEME['accent']);
});

it('zeigt die Bausteine in der Layout-Vorschau mit Beispielterminen und kennt die Schleifenfelder', function (): void {
    $preview = app(TemplatePreview::class);
    $html = app(BlockLayoutCompiler::class)->compile([
        ['type' => LayoutBlocks::SET_CONTENT],
        ['type' => LayoutBlocks::SET_EVENT_BOX],
        ['type' => LayoutBlocks::SET_MORE_EVENTS],
    ]);

    $rendered = $preview->render($html);

    expect($rendered['html'])->toContain('Beispielhalle')
        ->and($rendered['html'])->toContain('Weitere Konzerte in deiner Nähe')
        ->and($preview->availableVariables())->toContain('event.weekday')
        ->and($preview->availableVariables())->toContain('city');
});

// --- LeadHub ---------------------------------------------------------------

it('markiert die Umkreis-Segmente als von der Serie verwaltet', function (): void {
    if (! Schema::hasColumn('leadhub_segments', 'managed_by')) {
        $this->markTestSkipped('statamic-leadhub < 2.15: no managed_by.');
    }

    $template = presaleTemplate([], 'konzert');
    $occurrence = term(termEvent(), '2026-10-17 20:00');

    $segment = app(SegmentRepository::class)->findByHandle('series-konzert-'.$occurrence->uuid);

    expect($segment->managedBy())->toBe([
        'source' => 'statamic-marketing',
        'label' => 'Serie „VVK-Start“',
        // Relative since round 5 (host-independent, see SeriesRound5Test).
        'url' => route('statamic.cp.marketing.campaigns.show', ['handle' => $template->handle], false),
    ]);

    // Renamed template: the mark follows on the next sync.
    $template->name = 'Konzertmail';
    app(CampaignRepository::class)->save($template);
    app(SeriesSync::class)->syncAll();

    expect(app(SegmentRepository::class)->findByHandle('series-konzert-'.$occurrence->uuid)->managedBy()['label'])
        ->toBe('Serie „Konzertmail“');
});

it('legt Segmente auch an, wenn LeadHub managed_by nicht kennt', function (): void {
    if (Schema::hasColumn('leadhub_segments', 'managed_by')) {
        Schema::table('leadhub_segments', fn ($table) => $table->dropColumn('managed_by'));
    }

    presaleTemplate([], 'konzert');
    $occurrence = term(termEvent(), '2026-10-17 20:00');

    expect(childOf('konzert', $occurrence))->not->toBeNull()
        ->and(app(SegmentRepository::class)->findByHandle('series-konzert-'.$occurrence->uuid))->not->toBeNull();
});

it('verlinkt die Zielgruppe auf das Segment in LeadHub', function (): void {
    $user = User::make()->email('vvk@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    presaleTemplate([], 'konzert');
    $occurrence = term(termEvent(), '2026-10-17 20:00');
    $segment = app(SegmentRepository::class)->findByHandle('series-konzert-'.$occurrence->uuid);
    $expected = cp_route('leadhub.segments.edit', $segment->getAttribute('uuid'));

    $props = fn (string $route, string $handle) => json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])->get(cp_route($route, $handle))->assertOk()->getContent(),
        true,
    )['props'];

    expect($props('marketing.campaigns.show', 'konzert-'.$occurrence->uuid)['approval']['segment_url'])->toBe($expected)
        ->and($props('marketing.campaigns.edit', 'konzert')['series']['children'][0]['segment_url'])->toBe($expected);
});

it('speichert Anker und weitere Konzerte aus dem Editor und weist Unsinn zurück', function (): void {
    $user = User::make()->email('vvk2@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(CampaignRepository::class)->save(new Campaign(handle: 'neu', name: 'Neu', subject: 'x', listHandle: 'newsletter', content: '<p>x</p>'));

    $patch = fn (array $series) => $this->patch(cp_route('marketing.campaigns.update', 'neu'), [
        'name' => 'Neu', 'list' => 'newsletter', 'content' => '<p>x</p>', 'series_enabled' => true, 'series' => $series,
    ]);

    $patch(['anchor' => 'irgendwann'])->assertSessionHasErrors('series.anchor');
    session()->forget('errors');

    $patch(['anchor' => 'presale', 'days_after_presale' => 3, 'more_enabled' => false, 'more_radius_km' => 60, 'more_limit' => 2])
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('neu')->meta['series'])->toMatchArray([
        'anchor' => 'presale', 'days_after_presale' => 3, 'more_enabled' => false, 'more_radius_km' => 60, 'more_limit' => 2,
    ]);
});
