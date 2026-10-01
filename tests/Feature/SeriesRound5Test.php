<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Jobs\StartCampaignJob;
use Goldnead\Marketing\Jobs\SyncSeriesJob;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Series\SeriesSync;
use Goldnead\Marketing\Services\BlockLayoutCompiler;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Services\VariantAssigner;
use Goldnead\Marketing\Support\LayoutBlocks;
use Goldnead\Suppression\Contracts\Gate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Round 5 of the series: the review findings, each one first as a failing
 * test. Same clock and same small postal code register as
 * SeriesPresaleAndMoreEventsTest.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 12:00', 'Europe/Berlin'));
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    foreach ([['89077', 48.40, 9.97], ['89075', 48.67, 9.97]] as [$plz, $lat, $lng]) {
        PostalCode::query()->create(['country' => 'DE', 'postal_code' => $plz, 'latitude' => (string) $lat, 'longitude' => (string) $lng]);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function r5Template(array $settings = [], string $handle = 'serie', array $attributes = []): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(...array_merge([
        'handle' => $handle,
        'name' => 'Serie',
        'subject' => 'Konzert in {{ event:city }}',
        'listHandle' => 'newsletter',
        'content' => '<p>Wir spielen in {{ event:city }}.</p>',
        'status' => Campaign::STATUS_SERIES,
        'meta' => ['series' => $settings],
    ], $attributes)));

    return app(CampaignRepository::class)->find($handle);
}

function r5Term(string $localStart, array $attributes = [], ?Event $event = null): Occurrence
{
    if ($event === null) {
        $event = Event::create(['title' => 'Tour '.substr(uniqid(), -5)]);
        $event->publish();
    }

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

function r5Child(string $template, Occurrence $occurrence): ?Campaign
{
    return app(CampaignRepository::class)->find($template.'-'.$occurrence->uuid);
}

function r5Layout(array $blocks): void
{
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'bausteine',
        name: 'Bausteine',
        html: app(BlockLayoutCompiler::class)->compile($blocks),
        type: EmailTemplate::TYPE_BLOCKS,
        blocks: $blocks,
    ));
}

// 1 — never after the concert ----------------------------------------------

it('legt kein Kind an, dessen Versandzeit nach dem Konzert läge', function (): void {
    r5Template(['days_before' => 0, 'send_time' => '22:00']);
    $occurrence = r5Term('2026-10-17 20:00');

    $result = app(SeriesSync::class)->syncAll();

    expect(r5Child('serie', $occurrence))->toBeNull()
        ->and($result['skipped_too_late'])->toBe(1);
});

it('legt keine VVK-Mail an, die erst nach dem Konzert ginge', function (): void {
    if (! Schema::hasColumn('event_occurrences', 'presale_starts_at')) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    r5Template(['anchor' => 'presale', 'days_after_presale' => 30]);
    $occurrence = r5Term('2026-11-10 20:00', [
        'presale_starts_at' => CarbonImmutable::parse('2026-11-01 10:00', 'Europe/Berlin')->utc(),
    ]);

    expect(r5Child('serie', $occurrence))->toBeNull();
});

it('verschickt ein Serien-Kind nicht mehr, wenn sein Konzert schon war', function (): void {
    r5Template();
    $occurrence = r5Term('2026-10-17 20:00');
    app(SubscriptionService::class)->subscribe(app(MailingListRepository::class)->find('newsletter'), 'a@example.com');

    $child = r5Child('serie', $occurrence);
    $child->status = Campaign::STATUS_SENDING;
    $child->segmentHandle = null; // would otherwise be a different refusal
    $meta = $child->meta;
    $meta['event']['starts_at'] = CarbonImmutable::parse('2026-09-30 20:00', 'Europe/Berlin')->toIso8601String();
    $child->meta = $meta;
    app(CampaignRepository::class)->save($child);

    Log::spy();

    (new StartCampaignJob($child->handle))->handle(
        app(CampaignRepository::class),
        app(ContactRepository::class),
        app(Gate::class),
        app(VariantAssigner::class),
    );

    expect(Message::forCampaign($child->handle)->count())->toBe(0);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'already started'))->once();
});

// 2 — decoupled sync, and the race ------------------------------------------

it('stößt bei Termin-Ereignissen einen einzigen gebündelten Sync-Job an', function (): void {
    Queue::fake();
    r5Template();

    $event = Event::create(['title' => 'Tour gebündelt']);
    $event->publish();
    r5Term('2026-10-17 20:00', [], $event);
    r5Term('2026-10-24 20:00', [], $event);
    r5Term('2026-10-31 20:00', [], $event);

    Queue::assertPushed(SyncSeriesJob::class, 1);
});

it('überschreibt ein Kind nicht, das während des Abgleichs in den Versand ging', function (): void {
    r5Template();
    $occurrence = r5Term('2026-10-17 20:00');
    $handle = 'serie-'.$occurrence->uuid;
    $occurrence->update(['venue_name' => 'Ulmer Zelt']); // something to pull even

    $sync = new class(app(CampaignRepository::class), app(SegmentRepository::class)) extends SeriesSync
    {
        // Between "load the child" and "write it back", the sender claims it.
        protected function pullChildEven(Campaign $template, Campaign $child, Occurrence $occurrence, array $settings, string $segmentHandle): bool
        {
            $claimed = clone $child;
            $claimed->status = Campaign::STATUS_SENDING;
            $this->campaigns->save($claimed);

            return parent::pullChildEven($template, $child, $occurrence, $settings, $segmentHandle);
        }
    };

    $sync->syncAll();

    expect(app(CampaignRepository::class)->find($handle)->status)->toBe(Campaign::STATUS_SENDING);
});

// 3 — presale taken away after approval ------------------------------------

it('zählt ein Kind, das mit seinem Vorverkaufsdatum verschwindet, als entfernt und sagt es im Log', function (): void {
    if (! Schema::hasColumn('event_occurrences', 'presale_starts_at')) {
        $this->markTestSkipped('statamic-events < 2.7: no presale_starts_at.');
    }

    r5Template(['anchor' => 'presale']);
    $occurrence = r5Term('2027-03-14 20:00', [
        'presale_starts_at' => CarbonImmutable::parse('2026-11-02 10:00', 'Europe/Berlin')->utc(),
    ]);
    $handle = 'serie-'.$occurrence->uuid;
    expect(r5Child('serie', $occurrence))->not->toBeNull();

    Queue::fake(); // the listener; the run below is the one measured
    $occurrence->update(['presale_starts_at' => null]);

    Log::spy();
    $result = app(SeriesSync::class)->syncAll();

    expect($result['removed'])->toBe(1)
        ->and($result['skipped_no_presale'])->toBe(0)
        ->and(r5Child('serie', $occurrence))->toBeNull();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => str_contains($message, $handle))->once();
});

// 4 — no postal code table ---------------------------------------------------

it('übersteht einen Lauf ohne PLZ-Verzeichnis und lässt die weiteren Konzerte dann leer', function (): void {
    Schema::drop('leadhub_postal_codes');
    // DDL commits under MySQL: the next test must migrate from scratch.
    $this->schemaWasChanged();
    r5Template();

    $event = Event::create(['title' => 'Tour ohne Verzeichnis']);
    $event->publish();
    $main = r5Term('2026-10-17 20:00', [], $event);
    r5Term('2026-11-07 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075'], $event);

    app(SeriesSync::class)->syncAll();

    expect(r5Child('serie', $main))->not->toBeNull()
        ->and(r5Child('serie', $main)->meta['more_events'])->toBe([]);
});

// 5 — only http(s) behind a button -------------------------------------------

it('lässt Knopf und Link weg, wenn der Ticket-Link kein http(s) ist', function (string $url): void {
    r5Layout([
        ['type' => LayoutBlocks::SET_CONTENT],
        ['type' => LayoutBlocks::SET_EVENT_BOX],
        ['type' => LayoutBlocks::SET_MORE_EVENTS],
    ]);
    r5Template([], 'serie', ['templateHandle' => 'bausteine']);

    $event = Event::create(['title' => 'Tour böse']);
    $event->publish();
    $main = r5Term('2026-10-17 20:00', ['tickets_url' => $url], $event);
    r5Term('2026-11-07 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075', 'tickets_url' => $url], $event);

    $html = app(CampaignRenderer::class)->render(r5Child('serie', $main), app(MailingListRepository::class)->find('newsletter'))->html;

    expect(r5Child('serie', $main)->meta['event']['tickets_url'])->toBe('')
        ->and($html)->not->toContain('Tickets buchen')
        ->and($html)->not->toContain($url);
})->with(['javascript:alert(1)', 'data:text/html,<b>x</b>']);

// 6 — dark mode scoped -------------------------------------------------------

it('färbt im Dunkelmodus nur die Überschriften der Bausteine um, nicht jede h3', function (): void {
    $html = app(BlockLayoutCompiler::class)->compile([['type' => LayoutBlocks::SET_CONTENT]]);

    expect($html)->not->toMatch('/\.m-shell h3\b/')
        ->and($html)->toContain('.m-shell td.m-box h3');
});

// 7 — stable managed_by -------------------------------------------------------

it('schreibt den Verweis am Segment relativ und fasst das Segment beim nächsten Lauf nicht an', function (): void {
    if (! Schema::hasColumn('leadhub_segments', 'managed_by')) {
        $this->markTestSkipped('statamic-leadhub < 2.15: no managed_by.');
    }

    r5Template();
    $occurrence = r5Term('2026-10-17 20:00');
    $segment = app(SegmentRepository::class)->findByHandle('series-serie-'.$occurrence->uuid);

    // Relative: the CLI run and the CP request know the host differently, and
    // an absolute URL would differ between them and rewrite the segment.
    expect($segment->managedBy()['url'])->toBe(route('statamic.cp.marketing.campaigns.show', ['handle' => 'serie'], false))
        ->and($segment->managedBy()['url'])->toStartWith('/');

    $before = $segment->getAttribute('updated_at');
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    config()->set('app.url', 'https://anders.example');
    app(SeriesSync::class)->syncAll();
    Carbon::setTestNow();

    expect((string) app(SegmentRepository::class)->findByHandle('series-serie-'.$occurrence->uuid)->getAttribute('updated_at'))
        ->toBe((string) $before);
});

// 10, 11, 13 — the blocks as the ANDERS mail has them -------------------------

it('setzt den Terminkasten dort ein, wo {{ terminkasten }} im Text steht, im Stil des Layouts', function (): void {
    r5Layout([
        ['type' => LayoutBlocks::SET_CONTENT],
        ['type' => LayoutBlocks::SET_EVENT_BOX, 'background' => '#f9f4f5'],
    ]);
    r5Template([], 'serie', [
        'templateHandle' => 'bausteine',
        'content' => '<p>Vorher</p><p>{{ terminkasten }}</p><p>Nachher</p>',
    ]);
    $occurrence = r5Term('2026-10-17 20:00');

    $html = app(CampaignRenderer::class)->render(r5Child('serie', $occurrence), app(MailingListRepository::class)->find('newsletter'))->html;

    expect(substr_count($html, 'Tickets buchen'))->toBe(1)
        ->and(strpos($html, 'Vorher'))->toBeLessThan(strpos($html, 'Tickets buchen'))
        ->and(strpos($html, 'Tickets buchen'))->toBeLessThan(strpos($html, 'Nachher'))
        ->and($html)->toContain('bgcolor="#f9f4f5"')
        ->and($html)->not->toContain('{{ terminkasten }}');
});

it('bringt den Preheader in jede Mail, versteckt für das Postfach und im Block-Layout sichtbar oben', function (): void {
    $list = app(MailingListRepository::class)->find('newsletter');
    $plain = new Campaign(handle: 'p', name: 'P', subject: 'x', preheader: 'Wir kommen in deine Nähe!', listHandle: 'newsletter', content: '<p>Hallo</p>');

    $fallback = app(CampaignRenderer::class)->render($plain, $list)->html;

    expect($fallback)->toContain('Wir kommen in deine Nähe!')
        ->and($fallback)->toMatch('/display:\s*none[^>]*>\s*Wir kommen in deine Nähe!/');

    r5Layout([['type' => LayoutBlocks::SET_CONTENT]]);
    $plain->templateHandle = 'bausteine';
    $blocks = app(CampaignRenderer::class)->render($plain, $list)->html;

    expect(substr_count($blocks, 'Wir kommen in deine Nähe!'))->toBe(2); // hidden + top line
});

it('schreibt weitere Konzerte wie den Kasten und hält das Datum zusammen', function (): void {
    r5Layout([['type' => LayoutBlocks::SET_CONTENT], ['type' => LayoutBlocks::SET_MORE_EVENTS]]);
    r5Template([], 'serie', ['templateHandle' => 'bausteine']);

    $event = Event::create(['title' => 'Tour Datum']);
    $event->publish();
    $main = r5Term('2026-10-17 20:00', [], $event);
    r5Term('2026-12-05 20:00', ['venue_city' => 'Neu-Ulm', 'venue_postal_code' => '89075'], $event);

    $html = app(CampaignRenderer::class)->render(r5Child('serie', $main), app(MailingListRepository::class)->find('newsletter'))->html;

    // Non-breaking between the words, and the whole date in one nowrap run,
    // so "20 Uhr" cannot be torn apart either.
    expect($html)->toMatch('~white-space:nowrap;">Samstag,&nbsp;den&nbsp;05\.12\.26&nbsp;um&nbsp;20 Uhr</strong>~');
});

it('nimmt die Schriftgrößen der ANDERS-Mail als Vorgabe des Terminkastens', function (): void {
    $html = app(BlockLayoutCompiler::class)->compile([['type' => LayoutBlocks::SET_EVENT_BOX]]);

    expect($html)->toContain('font-size:24px')   // date
        ->and($html)->toContain('font-size:18px') // venue
        ->and($html)->toContain('font-size:16px;line-height:165%') // address
        ->and($html)->toMatch('/font-size:14px;[^"]*font-weight:400/'); // button
});
