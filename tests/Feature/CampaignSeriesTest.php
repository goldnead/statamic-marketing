<?php

use Carbon\CarbonImmutable;
use Goldnead\Events\Models\Event;
use Goldnead\Events\Models\Occurrence;
use Goldnead\Leadhub\Contracts\Repositories\SegmentRepository;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\PostalCode;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Series\SeriesSync;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * Die Kampagnenserie aus Terminen: eine Vorlage, je Termin eine wartende
 * Kampagne und ein Umkreis-Segment, Freigabe vor dem Versand.
 *
 * Der Lauf gegen den echten statamic-events-Speicher (Eloquent, Migrationen
 * je Test geladen) und den echten LeadHub — Geo-Segmente brauchen Zeilen in
 * der Postleitzahl-Tabelle, also liegen drei um Ulm herum in der Welt:
 * das Zentrum selbst, eines in ~30 km, eines in ~78 km.
 */
beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subs = app(SubscriptionService::class);
    $this->segments = app(SegmentRepository::class);

    foreach ([['89077', 48.40, 9.97], ['89075', 48.67, 9.97], ['89522', 49.10, 9.97]] as [$plz, $lat, $lng]) {
        PostalCode::query()->create([
            'country' => 'DE',
            'postal_code' => $plz,
            'latitude' => (string) $lat,
            'longitude' => (string) $lng,
        ]);
    }
});

function seriesTemplate(array $settings = []): Campaign
{
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'konzertmail',
        name: 'Konzert in der Nähe',
        subject: 'Konzert in {{ event:city }} am {{ event:date }}',
        listHandle: 'newsletter',
        content: '<p>Wir spielen am {{ event:date }} in {{ event:city }}.</p>',
        status: Campaign::STATUS_SERIES,
        meta: ['series' => $settings],
    ));

    return app(CampaignRepository::class)->find('konzertmail');
}

function seriesEvent(): Event
{
    // Der Slug entsteht aus dem Titel und ist je Marke eindeutig — mehrere
    // Termine im selben Test brauchen also mehrere Titel.
    $event = Event::create(['title' => 'Anders zieht um — das Konzert '.substr(uniqid(), -4)]);
    $event->publish();

    return $event;
}

function seriesOccurrence(Event $event, ?CarbonImmutable $startsAt = null, array $attributes = []): Occurrence
{
    return Occurrence::create(array_merge([
        'event_id' => $event->id,
        'starts_at' => ($startsAt ?? CarbonImmutable::now()->addDays(30)->setTime(20, 0))->utc(),
        'timezone' => 'Europe/Berlin',
        'venue_name' => 'Roxy',
        'venue_city' => 'Ulm',
        'venue_postal_code' => '89077',
        'venue_country' => 'DE',
        'tickets_url' => 'https://example.com/tickets',
    ], $attributes));
}

/** Ein Abo mit LeadHub-Kontakt an einer Postleitzahl — der Join-Schlüssel ist die contact_uuid. */
function seriesSubscriber(string $email, ?string $postalCode): Subscription
{
    $contact = Contact::create(array_filter([
        'email' => $email,
        'status' => 'qualified',
        'postal_code' => $postalCode,
        'country' => 'DE',
    ]));

    $subscription = test()->subs->subscribe(test()->list, $email, ['first_name' => 'X']);
    $subscription->contact_uuid = $contact->uuid;
    $subscription->save();

    return $subscription;
}

function seriesChild(Occurrence $occurrence): ?Campaign
{
    return app(CampaignRepository::class)->find('konzertmail-'.$occurrence->uuid);
}

it('legt je Termin eine wartende Kampagne und ein Umkreis-Segment an', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    // OccurrenceScheduled hat den Listener gefeuert — ohne jeden Befehl.
    $child = seriesChild($occurrence);

    expect($child)->not->toBeNull()
        ->and($child->status)->toBe(Campaign::STATUS_AWAITING_APPROVAL)
        ->and($child->series)->toBe('konzertmail')
        ->and($child->sourceKey)->toBe('occurrence:'.$occurrence->uuid)
        ->and($child->segmentHandle)->toBe('series-konzertmail-'.$occurrence->uuid)
        ->and($child->name)->toBe('Konzert in der Nähe (Ulm)')
        ->and($child->listHandle)->toBe('newsletter');

    $segment = $this->segments->findByHandle('series-konzertmail-'.$occurrence->uuid);

    expect($segment)->not->toBeNull()
        ->and($segment->name)->toBe('Konzert: Ulm 89077 (50 km)')
        ->and($segment->rules['conditions'][0]['type'])->toBe('geo')
        ->and($segment->rules['conditions'][0]['plz'])->toBe('89077')
        ->and($segment->rules['conditions'][0]['value'])->toBe(50);

    // 7 Tage vorher, 10 Uhr in der Zeitzone des Termins.
    $expected = $occurrence->localStart()->subDays(7)->setTime(10, 0)->utc();

    expect($child->scheduledAt?->equalTo($expected))->toBeTrue()
        ->and($child->meta['event']['city'])->toBe('Ulm')
        ->and($child->meta['event']['date'])->toBe($occurrence->localStart()->format('d.m.Y'))
        ->and($child->meta['event']['tickets_url'])->toBe('https://example.com/tickets');
});

it('ist idempotent: ein zweiter Lauf ändert nichts und zählt nichts', function (): void {
    seriesTemplate();
    seriesOccurrence(seriesEvent());

    $result = app(SeriesSync::class)->syncAll();

    expect($result)->toBe(['created' => 0, 'updated' => 0, 'removed' => 0, 'skipped_no_postal_code' => 0, 'skipped_no_presale' => 0, 'skipped_too_late' => 0]);
});

it('zieht Versandzeit und Momentaufnahme bei Verschiebung nach', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    // 19:00 deutscher Abend, als lokale Zeit gedacht — nicht 19:00 UTC.
    $moved = CarbonImmutable::now()->addDays(60)->setTimezone('Europe/Berlin')->setTime(19, 0);

    $occurrence->reschedule($moved->utc()); // feuert OccurrenceRescheduled

    $child = seriesChild($occurrence);

    $expected = $moved->subDays(7)->setTime(10, 0)->utc();

    expect($child->scheduledAt?->equalTo($expected))->toBeTrue()
        ->and($child->status)->toBe(Campaign::STATUS_AWAITING_APPROVAL)
        ->and($child->meta['event']['time'])->toBe('19:00')
        ->and($child->meta['event']['date'])->toBe($moved->format('d.m.Y'));
});

it('löscht Kampagne und Segment, wenn der Termin abgesagt wird', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    expect(seriesChild($occurrence))->not->toBeNull();

    $occurrence->cancel(); // feuert OccurrenceCancelled

    expect(seriesChild($occurrence))->toBeNull()
        ->and($this->segments->findByHandle('series-konzertmail-'.$occurrence->uuid))->toBeNull();
});

it('lässt bereits gesendete Kampagnen und ihre Segmente in Ruhe', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    $child = seriesChild($occurrence);
    $child->status = Campaign::STATUS_SENT;
    $child->sentAt = CarbonImmutable::now();
    app(CampaignRepository::class)->save($child);

    $occurrence->cancel();

    expect(seriesChild($occurrence))->not->toBeNull()
        ->and($this->segments->findByHandle('series-konzertmail-'.$occurrence->uuid))->not->toBeNull();
});

it('löscht die Kinder einer gelöschten Vorlage mitsamt ihren Segmenten', function (): void {
    $occurrence = seriesOccurrence(seriesEvent()); // erst der Termin, dann die Vorlage
    seriesTemplate();

    $result = app(SeriesSync::class)->syncAll();

    expect($result['created'])->toBe(1)
        ->and(seriesChild($occurrence))->not->toBeNull();

    app(CampaignRepository::class)->delete('konzertmail');

    $result = app(SeriesSync::class)->syncAll();

    expect($result['removed'])->toBe(1)
        ->and(seriesChild($occurrence))->toBeNull()
        ->and($this->segments->findByHandle('series-konzertmail-'.$occurrence->uuid))->toBeNull();
});

it('überspringt Termine ohne Postleitzahl und zählt sie', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent(), null, ['venue_postal_code' => null]);
    seriesOccurrence(seriesEvent());

    $result = app(SeriesSync::class)->syncAll();

    expect($result['skipped_no_postal_code'])->toBe(1)
        ->and(seriesChild($occurrence))->toBeNull();
});

it('legt nichts für einen Termin an, der schon vorbei ist', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent(), CarbonImmutable::now()->subDay()->setTime(20, 0));

    expect(seriesChild($occurrence))->toBeNull();

    $result = app(SeriesSync::class)->syncAll();

    expect($result['created'])->toBe(0)
        ->and(seriesChild($occurrence))->toBeNull();
});

it('sendet nach Freigabe an die Nähe und an niemanden weiter weg', function (): void {
    seriesTemplate();
    // In 3 Tagen: die berechnete Versandzeit (7 Tage vorher) ist vorbei —
    // die Kampagne wartet ohne Zeitpunkt, die Freigabe sendet sofort.
    $occurrence = seriesOccurrence(seriesEvent(), CarbonImmutable::now()->addDays(3)->setTime(20, 0));

    seriesSubscriber('nahe@example.com', '89075');
    seriesSubscriber('weit@example.com', '89522');
    seriesSubscriber('ohne@example.com', null);

    $child = seriesChild($occurrence);

    expect($child->scheduledAt)->toBeNull()
        ->and($child->status)->toBe(Campaign::STATUS_AWAITING_APPROVAL);

    // Ohne Freigabe schickt der Scheduler nichts, auch nicht bei fälligem Termin.
    $this->artisan('marketing:send-scheduled');

    Mail::assertNothingSent();
    expect(Message::forCampaign($child->handle)->count())->toBe(0);

    app(CampaignSender::class)->approve($child);
    $this->artisan('marketing:send-scheduled');

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return $mail->hasTo('nahe@example.com')
            && str_contains($mail->rendered->subject, 'Konzert in Ulm am');
    });
    Mail::assertNotSent(fn (CampaignMail $mail) => $mail->hasTo('weit@example.com'));
    Mail::assertNotSent(fn (CampaignMail $mail) => $mail->hasTo('ohne@example.com'));
});

it('ist fail closed: fehlt das Segment, bekommt eine Serien-Kampagne niemand', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    seriesSubscriber('nahe@example.com', '89075');

    $child = seriesChild($occurrence);
    $child->segmentHandle = 'existiert-nicht';
    $child->status = Campaign::STATUS_DRAFT; // queue() verlangt einen sendbaren Status
    app(CampaignRepository::class)->save($child);

    Log::spy();

    app(CampaignSender::class)->queue($child);

    Mail::assertNothingSent();
    expect(Message::forCampaign($child->handle)->count())->toBe(0);
    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message) => str_contains($message, 'existiert-nicht')
    );
});

it('ist fail closed, wenn LeadHub segmentMemberIds nicht kann', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    seriesSubscriber('nahe@example.com', '89075');

    $child = seriesChild($occurrence);
    $child->status = Campaign::STATUS_DRAFT;
    app(CampaignRepository::class)->save($child);

    LeadHub::swap(new class
    {
        public function create(array $attributes): array
        {
            return ['uuid' => 'stub-uuid', 'email' => $attributes['email'] ?? null];
        }
    });

    app(CampaignSender::class)->queue($child);

    Mail::assertNothingSent();
    expect(Message::forCampaign($child->handle)->count())->toBe(0);
});

it('nimmt die Freigabe zurück und stellt die Kampagne wieder hinten an', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    $child = seriesChild($occurrence);
    app(CampaignSender::class)->approve($child);

    expect($child->status)->toBe(Campaign::STATUS_SCHEDULED);

    app(CampaignSender::class)->withdraw($child);

    $reloaded = app(CampaignRepository::class)->find($child->handle);

    expect($reloaded->status)->toBe(Campaign::STATUS_AWAITING_APPROVAL)
        ->and($reloaded->scheduledAt)->not->toBeNull();

    $this->artisan('marketing:send-scheduled');

    Mail::assertNothingSent();
});

it('verweigert die Freigabe, wenn der Termin vorbei ist', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    $child = seriesChild($occurrence);
    $child->meta = ['event' => ['starts_at' => CarbonImmutable::now()->subDay()->toIso8601String()]];
    app(CampaignRepository::class)->save($child);

    expect(fn () => app(CampaignSender::class)->approve($child))
        ->toThrow(InvalidArgumentException::class);
});

it('rendert der Vorlage in der Vorschau Beispielwerte für den Termin', function (): void {
    $template = seriesTemplate();

    $rendered = app(CampaignRenderer::class)->render($template, $this->list, null);

    expect($rendered->subject)->toContain('Ulm')
        ->and($rendered->html)->toContain('Wir spielen am');
});

it('gibt die Kampagne über den CP-Endpunkt frei', function (): void {
    $this->user = User::make()->email('editor@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    $child = seriesChild($occurrence);

    $this->post(cp_route('marketing.campaigns.approve', $child->handle))
        ->assertRedirect();

    expect(app(CampaignRepository::class)->find($child->handle)->status)->toBe(Campaign::STATUS_SCHEDULED);
});

/** Der Handle des Segments, das die Serie für diese Vorlage und diesen Termin anlegt. */
function seriesSegmentHandle(string $template, Occurrence $occurrence): string
{
    return 'series-'.$template.'-'.$occurrence->uuid;
}

function seriesEditor(): void
{
    $user = User::make()->email('editor@example.com')->makeSuper();
    $user->save();
    test()->actingAs($user);
}

function seriesPatchFields(array $overrides = []): array
{
    return array_merge([
        'name' => 'Konzert in der Nähe',
        'subject' => 'Neuer Betreff {{ event:city }}',
        'list' => 'newsletter',
        'content' => '<p>Neu</p>',
    ], $overrides);
}

// --- Review 2026-10-01 ---------------------------------------------------

it('sendet eine Serien-Kampagne ohne Segment-Handle an niemanden', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    seriesSubscriber('nahe@example.com', '89075');

    $child = seriesChild($occurrence);
    $child->segmentHandle = null;
    $child->status = Campaign::STATUS_DRAFT;
    app(CampaignRepository::class)->save($child);

    Log::spy();

    app(CampaignSender::class)->queue($child);

    Mail::assertNothingSent();
    expect(Message::forCampaign($child->handle)->count())->toBe(0);
    Log::shouldHaveReceived('error')->once();
});

it('sendet eine Serien-Kampagne mit inaktivem Segment an niemanden', function (): void {
    $template = seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    seriesSubscriber('nahe@example.com', '89075');

    $handle = seriesSegmentHandle($template->handle, $occurrence);
    $segment = $this->segments->findByHandle($handle);
    expect($segment)->not->toBeNull();
    $this->segments->update($segment, ['is_active' => false]);

    $child = seriesChild($occurrence);
    $child->status = Campaign::STATUS_DRAFT;
    app(CampaignRepository::class)->save($child);

    Log::spy();

    app(CampaignSender::class)->queue($child);

    Mail::assertNothingSent();
    Log::shouldHaveReceived('error')->once();
});

it('sperrt Liste und Segment einer Serien-Kampagne im CP', function (): void {
    seriesEditor();
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    $child = seriesChild($occurrence);
    $segmentBefore = $child->segmentHandle;
    app(CampaignSender::class)->approve($child); // scheduled = bearbeitbar

    $this->patch(cp_route('marketing.campaigns.update', $child->handle), seriesPatchFields([
        'list' => 'anderswo',
        'segment' => null,
    ]))->assertRedirect();

    $reloaded = app(CampaignRepository::class)->find($child->handle);

    expect($reloaded->subject)->toBe('Neuer Betreff {{ event:city }}')
        ->and($reloaded->listHandle)->toBe('newsletter')
        ->and($reloaded->segmentHandle)->toBe($segmentBefore)
        ->and($segmentBefore)->not->toBeEmpty();
});

it('legt je Vorlage ein eigenes Segment an, auch bei verschiedenem Radius', function (): void {
    seriesTemplate(['radius_km' => 50]);
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'konzertmail-weit',
        name: 'Weit',
        subject: 'Weit',
        listHandle: 'newsletter',
        content: '<p>x</p>',
        status: Campaign::STATUS_SERIES,
        meta: ['series' => ['radius_km' => 100]],
    ));
    $occurrence = seriesOccurrence(seriesEvent());

    $result = app(SeriesSync::class)->syncAll();

    $a = $this->segments->findByHandle(seriesSegmentHandle('konzertmail', $occurrence));
    $b = $this->segments->findByHandle(seriesSegmentHandle('konzertmail-weit', $occurrence));

    expect($result)->toBe(['created' => 0, 'updated' => 0, 'removed' => 0, 'skipped_no_postal_code' => 0, 'skipped_no_presale' => 0, 'skipped_too_late' => 0])
        ->and($a)->not->toBeNull()
        ->and($b)->not->toBeNull()
        ->and($a->getAttribute('rules')['conditions'][0]['value'])->toBe(50)
        ->and($b->getAttribute('rules')['conditions'][0]['value'])->toBe(100)
        ->and($a->name)->toBe('Konzert: Ulm 89077 (50 km)')
        ->and($b->name)->toBe('Konzert: Ulm 89077 (100 km)')
        ->and(app(CampaignRepository::class)->find('konzertmail-weit-'.$occurrence->uuid)->segmentHandle)
        ->toBe(seriesSegmentHandle('konzertmail-weit', $occurrence));

    // Die eine Vorlage zu löschen lässt das Segment der anderen stehen.
    app(CampaignRepository::class)->delete('konzertmail-weit');
    app(SeriesSync::class)->syncAll();

    expect($this->segments->findByHandle(seriesSegmentHandle('konzertmail-weit', $occurrence)))->toBeNull()
        ->and($this->segments->findByHandle(seriesSegmentHandle('konzertmail', $occurrence)))->not->toBeNull();
});

it('lässt eine Vorlage im CP speichern und gleicht die Kinder sofort ab', function (): void {
    seriesEditor();
    $occurrence = seriesOccurrence(seriesEvent()); // Termin zuerst, Vorlage ohne Sync danach
    seriesTemplate();

    expect(seriesChild($occurrence))->toBeNull();

    $this->patch(cp_route('marketing.campaigns.update', 'konzertmail'), seriesPatchFields(['name' => 'Umbenannt']))
        ->assertSessionHasNoErrors();

    expect(app(CampaignRepository::class)->find('konzertmail')->name)->toBe('Umbenannt')
        ->and(seriesChild($occurrence))->not->toBeNull();
});

it('entfernt beim Löschen einer Vorlage im CP sofort Kinder und Segmente', function (): void {
    seriesEditor();
    $template = seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());

    expect(seriesChild($occurrence))->not->toBeNull();

    $this->delete(cp_route('marketing.campaigns.destroy', 'konzertmail'))->assertRedirect();

    expect(app(CampaignRepository::class)->find('konzertmail'))->toBeNull()
        ->and(seriesChild($occurrence))->toBeNull()
        ->and($this->segments->findByHandle(seriesSegmentHandle($template->handle, $occurrence)))->toBeNull();
});

it('lässt die Aktion der Termine-Erweiterung nie an einer Exception scheitern', function (): void {
    seriesTemplate();

    app()->instance(SeriesSync::class, new class(app(CampaignRepository::class), app(SegmentRepository::class)) extends SeriesSync
    {
        // The listener syncs everything since "Weitere Konzerte" (a term
        // changes its neighbours' mails), so that is what has to throw.
        public function syncAll(): array
        {
            throw new RuntimeException('LeadHub kaputt');
        }
    });

    $occurrence = seriesOccurrence(seriesEvent());
    $occurrence->cancel();

    expect($occurrence->fresh()->isCancelled())->toBeTrue();
});

it('legt das Segment auch an, wenn ein paralleler Lauf es zwischen Suche und Anlage erzeugt', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    $existing = $this->segments->findByHandle(seriesSegmentHandle('konzertmail', $occurrence));

    $racing = Mockery::mock(SegmentRepository::class);
    $racing->shouldReceive('findByHandle')->andReturn(null, $existing);
    $racing->shouldReceive('create')->andThrow(new RuntimeException('UNIQUE constraint failed'));
    $racing->shouldReceive('update')->andReturn($existing);

    $result = (new SeriesSync(app(CampaignRepository::class), $racing))->syncAll();

    expect($result['created'])->toBe(0);
});

it('entfernt das ungesendete Kind eines entveröffentlichten Events', function (): void {
    seriesTemplate();
    $event = seriesEvent();
    $occurrence = seriesOccurrence($event);

    expect(seriesChild($occurrence))->not->toBeNull();

    $event->unpublish();
    app(SeriesSync::class)->syncAll();

    expect(seriesChild($occurrence))->toBeNull()
        ->and($this->segments->findByHandle(seriesSegmentHandle('konzertmail', $occurrence)))->toBeNull();
});

it('entfernt das Kind eines Events, das aus event_ids herausgenommen wurde', function (): void {
    $template = seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    $child = seriesChild($occurrence);
    app(CampaignSender::class)->approve($child); // auch freigegebene gehen

    $template->meta = ['series' => ['event_ids' => ['00000000-0000-0000-0000-000000000000']]];
    app(CampaignRepository::class)->save($template);

    app(SeriesSync::class)->syncAll();

    expect(seriesChild($occurrence))->toBeNull();
});

it('lässt ein freigegebenes Kind mit vergangener Versandzeit freigegeben', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent(), CarbonImmutable::now()->addDays(3)->setTime(20, 0));

    $child = seriesChild($occurrence);
    app(CampaignSender::class)->approve($child); // at = now, Status scheduled

    app(SeriesSync::class)->syncAll();

    $reloaded = app(CampaignRepository::class)->find($child->handle);

    expect($reloaded->status)->toBe(Campaign::STATUS_SCHEDULED)
        ->and($reloaded->scheduledAt)->not->toBeNull();
});

it('stellt ein Serien-Kind beim Zurücknehmen des Zeitplans wieder in die Warteschlange', function (): void {
    seriesTemplate();
    $occurrence = seriesOccurrence(seriesEvent());
    $child = seriesChild($occurrence);
    app(CampaignSender::class)->approve($child);

    app(CampaignSender::class)->unschedule($child);

    expect(app(CampaignRepository::class)->find($child->handle)->status)
        ->toBe(Campaign::STATUS_AWAITING_APPROVAL);
});
