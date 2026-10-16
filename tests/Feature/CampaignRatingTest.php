<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Rating;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Ein Klick im Mailfuss bewertet die Ausgabe: Gut, In Ordnung, Schlecht.
 *
 * Der Klick zaehlt sofort (GET), die Begruendung ist ein zweiter, freiwilliger
 * Schritt auf der Danke-Seite (POST). Eine Stimme je Zustellung, die letzte
 * Wahl gilt. Die Links sind signiert wie der Klick-Zaehler, damit niemand
 * fremde Empfaenger-IDs durchprobieren kann.
 */
beforeEach(function (): void {
    Mail::fake();
    app()->setLocale('de');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $subscription = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'jane@example.com',
    );

    $this->message = Message::create([
        'campaign_handle' => 'ausgabe_20',
        'subscription_id' => $subscription->id,
        'email' => $subscription->email,
        'status' => Message::STATUS_SENT,
        'sent_at' => now()->subHour(),
    ]);
});

function ratingLink(string $choice, ?string $uuid = null): string
{
    return URL::signedRoute('marketing.rate', [
        'uuid' => $uuid ?? test()->message->uuid,
        'choice' => $choice,
    ]);
}

it('speichert die Wahl mit dem ersten Klick und zeigt die Danke-Seite', function (): void {
    $this->get(ratingLink('good'))
        ->assertOk()
        ->assertSee('Danke');

    $rating = Rating::query()->sole();

    expect($rating->choice)->toBe('good')
        ->and($rating->campaign_handle)->toBe('ausgabe_20')
        ->and($rating->message_id)->toBe($this->message->id)
        ->and($rating->reason)->toBeNull();
});

it('ueberschreibt die Wahl beim zweiten Klick, ohne doppelt zu zaehlen', function (): void {
    $this->get(ratingLink('good'))->assertOk();
    $this->get(ratingLink('bad'))->assertOk();
    $this->get(ratingLink('bad'))->assertOk();

    expect(Rating::query()->count())->toBe(1)
        ->and(Rating::query()->sole()->choice)->toBe('bad');
});

it('haengt die Begruendung an dieselbe Stimme', function (): void {
    $this->get(ratingLink('ok'))->assertOk();

    $action = URL::signedRoute('marketing.rate.reason', ['uuid' => $this->message->uuid]);

    $this->post($action, ['reason' => '  Zu lang für den Handy-Bildschirm.  '])
        ->assertOk()
        ->assertSee('Danke');

    $rating = Rating::query()->sole();

    expect(Rating::query()->count())->toBe(1)
        ->and($rating->choice)->toBe('ok')
        ->and($rating->reason)->toBe('Zu lang für den Handy-Bildschirm.');
});

it('behaelt die Begruendung, wenn die Wahl spaeter geaendert wird', function (): void {
    $this->get(ratingLink('bad'))->assertOk();
    $this->post(URL::signedRoute('marketing.rate.reason', ['uuid' => $this->message->uuid]), [
        'reason' => 'Zu viel Text.',
    ])->assertOk();

    $this->get(ratingLink('ok'))->assertOk();

    $rating = Rating::query()->sole();

    expect($rating->choice)->toBe('ok')->and($rating->reason)->toBe('Zu viel Text.');
});

it('nimmt eine leere Begruendung an und laesst die Stimme stehen', function (): void {
    $this->get(ratingLink('good'))->assertOk();

    $this->post(URL::signedRoute('marketing.rate.reason', ['uuid' => $this->message->uuid]), ['reason' => '   '])
        ->assertOk();

    expect(Rating::query()->sole()->reason)->toBeNull();
});

it('kuerzt eine zu lange Begruendung ab statt sie abzulehnen', function (): void {
    $this->get(ratingLink('good'))->assertOk();

    $this->post(URL::signedRoute('marketing.rate.reason', ['uuid' => $this->message->uuid]), [
        'reason' => str_repeat('a', Rating::REASON_MAX + 500),
    ])->assertOk();

    expect(mb_strlen(Rating::query()->sole()->reason))->toBe(Rating::REASON_MAX);
});

it('weist einen manipulierten Link ab und schreibt nichts', function (): void {
    // Die Wahl im signierten Link ausgetauscht.
    $this->get(str_replace('/good', '/bad', ratingLink('good')))->assertForbidden();

    // Eine andere Zustellung eingesetzt.
    $other = Message::create([
        'campaign_handle' => 'ausgabe_20',
        'subscription_id' => $this->message->subscription_id,
        'email' => 'fremd@example.com',
        'status' => Message::STATUS_SENT,
        'sent_at' => now()->subHour(),
    ]);
    $this->get(str_replace($this->message->uuid, $other->uuid, ratingLink('good')))->assertForbidden();

    // Ohne Signatur.
    $this->get(route('marketing.rate', ['uuid' => $this->message->uuid, 'choice' => 'good']))->assertForbidden();

    expect(Rating::query()->count())->toBe(0);
});

it('weist eine manipulierte Begruendungs-Adresse ab', function (): void {
    $this->get(ratingLink('good'))->assertOk();

    $this->post(route('marketing.rate.reason', ['uuid' => $this->message->uuid]), ['reason' => 'x'])
        ->assertForbidden();

    expect(Rating::query()->sole()->reason)->toBeNull();
});

it('kennt nur die drei Stufen', function (): void {
    $this->get(ratingLink('great'))->assertNotFound();

    expect(Rating::query()->count())->toBe(0);
});

it('antwortet 404 bei einer unbekannten Zustellung', function (): void {
    $this->get(ratingLink('good', '00000000-0000-0000-0000-000000000000'))->assertNotFound();
});

it('nimmt eine Begruendung ohne vorherige Wahl nicht an', function (): void {
    $this->post(URL::signedRoute('marketing.rate.reason', ['uuid' => $this->message->uuid]), ['reason' => 'x'])
        ->assertNotFound();

    expect(Rating::query()->count())->toBe(0);
});

it('lasst eine Link-Pruefung durch ein Sicherheitsprogramm keine Stimme schreiben', function (): void {
    $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Proofpoint URL Defense)')
        ->get(ratingLink('bad'))
        ->assertOk();

    expect(Rating::query()->count())->toBe(0);
});

it('uebersteht einen Zusatzparameter des Versanddienstes wie der Klick-Zaehler', function (): void {
    $url = ratingLink('good');
    [$path, $query] = explode('?', $url, 2);

    $this->get($path.'?_se=amFuZUBleGFtcGxlLmNvbQ&'.$query)->assertOk();

    expect(Rating::query()->sole()->choice)->toBe('good');
});

it('zeigt in der Test-Vorschau die Seite und schreibt keine Stimme', function (): void {
    $this->get(ratingLink('good', 'test-preview'))
        ->assertOk()
        ->assertSee('Danke');

    $this->post(URL::signedRoute('marketing.rate.reason', ['uuid' => 'test-preview']), ['reason' => 'x'])
        ->assertOk();

    expect(Rating::query()->count())->toBe(0);
});

it('stellt die drei Links als Variablen bereit und schreibt sie nicht auf den Klick-Zaehler um', function (): void {
    $campaign = app(CampaignRepository::class);

    $record = new Campaign(
        handle: 'ausgabe_20',
        name: 'Ausgabe 20',
        subject: 'Hallo',
        content: '<p><a href="{{ rating_good_url }}">Gut</a> <a href="{{ rating_ok_url }}">Ok</a> <a href="{{ rating_bad_url }}">Schlecht</a></p>',
        listHandle: 'newsletter',
    );
    $campaign->save($record);

    $list = app(MailingListRepository::class)->find('newsletter');
    $subscription = $this->message->subscription;

    $rendered = app(CampaignRenderer::class)
        ->render($record, $list, $subscription, $this->message);

    foreach (['good', 'ok', 'bad'] as $choice) {
        expect($rendered->html)->toContain('/feedback/'.$this->message->uuid.'/'.$choice.'?signature=');
    }

    // Kein Umweg ueber `/c/…`: der Link ist selbst schon signiert und zaehlt selbst.
    expect($rendered->html)->not->toContain('/c/'.$this->message->uuid.'?url=');
});
