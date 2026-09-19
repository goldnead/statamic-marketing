<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->subscription = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'jane@example.com',
    );
});

// Seit 2.23.3 meldet erst der Knopf ab, nicht schon der Aufruf — siehe die
// Begruendung weiter unten bei „does not unsubscribe on a bare GET". Der Weg
// bleibt derselbe, nur eine Seite laenger.
it('unsubscribes via the tokenized link', function (): void {
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])
        ->assertOk()
        ->assertSee(__('marketing::public.unsubscribed_title'));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and($subscription->unsubscribed_at)->not->toBeNull();

    // List tag is removed from the contact, but no global opt-out by default.
    $contact = LeadHub::findByEmail('jane@example.com');

    expect($contact['tags'])->not->toContain('list:newsletter');

    $model = Contact::query()->where('uuid', $contact['uuid'])->first();
    expect((bool) $model->do_not_contact)->toBeFalse();
});

it('handles RFC 8058 one-click unsubscribes without a session', function (): void {
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token))
        ->assertNoContent();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('opts the contact out globally when configured', function (): void {
    config()->set('marketing.unsubscribe.global_opt_out', true);

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])->assertOk();

    $contact = LeadHub::findByEmail('jane@example.com');
    $model = Contact::query()->where('uuid', $contact['uuid'])->first();

    expect((bool) $model->do_not_contact)->toBeTrue();
});

it('404s for unknown tokens', function (): void {
    $this->get(route('marketing.unsubscribe', 'garbage-token'))->assertNotFound();
});

/*
 * Ein GET ist nichts, was die Leserin unbedingt getan hat.
 *
 * Outlook SafeLinks, der Virenscanner am Mail-Gateway und die Linkvorschau
 * eines Messengers rufen jede URL in einer eingehenden Nachricht ab. Solange
 * der Abmeldelink beim blossen Aufruf abmeldet, meldet jeder dieser Abrufe die
 * Leserin ab, ohne dass sie es weiss — und zwar mit Zeitstempel, der aussieht
 * wie eine Handlung.
 *
 * Fuer die BESTAETIGUNG hat das Addon dieselbe Frage schon entschieden
 * (`confirm_requires_post`). Fuer die Abmeldung gilt dasselbe Argument, nur
 * andersherum. Gemessen am 18.09.2026 auf staging: ein einziger Seitenaufruf,
 * Status danach `unsubscribed`.
 */
it('does not unsubscribe on a bare GET', function (): void {
    $this->get(route('marketing.unsubscribe', $this->subscription->token))
        ->assertOk()
        ->assertSee(__('marketing::public.unsubscribe_confirm_button'));

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('unsubscribes when the button on that page is pressed', function (): void {
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])
        ->assertOk()
        ->assertSee(__('marketing::public.unsubscribed_title'));

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

/*
 * Der Weg, den Google und Yahoo verlangen, bleibt unangetastet: die Anbieter
 * schicken laut RFC 8058 genau `List-Unsubscribe=One-Click` im Rumpf und
 * erwarten, dass danach abgemeldet ist. Sie bekommen weiter 204 und keine
 * Seite.
 */
it('keeps the RFC 8058 one-click path answering with no content', function (): void {
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), [
        'List-Unsubscribe' => 'One-Click',
    ])->assertNoContent();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('answers a robot that sends no body at all with no content, too', function (): void {
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token))
        ->assertNoContent();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

/*
 * Der Rueckweg fuer Installationen, die den Ein-Klick-Ablauf zurueckwollen —
 * dieselbe Schraube, die `confirm_requires_post` fuer die Bestaetigung hat.
 */
it('still unsubscribes on GET when the install turns the confirmation off', function (): void {
    config()->set('marketing.unsubscribe.requires_post', false);

    $this->get(route('marketing.unsubscribe', $this->subscription->token))
        ->assertOk()
        ->assertSee(__('marketing::public.unsubscribed_title'));

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});
