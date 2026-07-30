<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

/**
 * The preference centre.
 *
 * The five lists are the ones adriangoldner.com actually runs, because the
 * defect this page answers only exists once a brand has more than one: an
 * unsubscribe has always been per list, so somebody who wanted to keep the
 * events had to wait for four more mails and click four more links, and the
 * page that told them they were unsubscribed never mentioned the other four
 * existed.
 */
beforeEach(function (): void {
    Mail::fake();

    $lists = app(MailingListRepository::class);

    $lists->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));
    $lists->save(new MailingList(handle: 'chorleitung', name: 'Chorleitung', description: 'Für Chorleiterinnen und Chorleiter', doubleOptIn: true));
    $lists->save(new MailingList(handle: 'saenger', name: 'Sänger', doubleOptIn: true));
    $lists->save(new MailingList(handle: 'events', name: 'Events', doubleOptIn: false));
    $lists->save(new MailingList(handle: 'offers', name: 'Angebote', doubleOptIn: false));

    $this->subscriptions = app(SubscriptionService::class);

    $this->newsletter = $this->subscriptions->subscribe($lists->find('newsletter'), 'jane@example.com');
    $this->events = $this->subscriptions->subscribe($lists->find('events'), 'jane@example.com');

    $this->token = $this->newsletter->token;
});

/** Every row the page rendered, as handle => state, read out of the DOM. */
function renderedStates(string $html): array
{
    preg_match_all('/data-list="([^"]+)"\s+data-state="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn ($m) => [$m[1] => $m[2]])->all();
}

function currentStatus(string $listHandle, string $email = 'jane@example.com'): ?string
{
    return Subscription::query()
        ->where('list_handle', $listHandle)
        ->where('email', $email)
        ->value('status');
}

it('shows every list of the brand with its current state, opened without a session', function (): void {
    $response = $this->get(route('marketing.preferences', $this->token))->assertOk();

    // No cookie went out with the request and none is needed to read the page:
    // the token is the credential, because almost no subscriber has an account
    // and a login form in front of an unsubscribe is a dark pattern.
    expect(renderedStates($response->getContent()))->toBe([
        'offers' => 'inactive',
        'chorleitung' => 'inactive',
        'events' => 'active',
        'newsletter' => 'active',
        'saenger' => 'inactive',
    ]);

    $response->assertSee('jane@example.com')
        ->assertSee(__('marketing::public.preferences_all_button'));
});

it('unsubscribes a single list and leaves the others running', function (): void {
    $this->post(route('marketing.preferences.update', $this->token), [
        'action' => 'save',
        'lists' => ['events'],
    ])->assertRedirect(route('marketing.preferences', $this->token));

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(currentStatus('events'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('switches a list back on without asking for a second double opt-in', function (): void {
    // The token was delivered to this mailbox, which is the same proof a
    // confirmation mail collects. Asking for it twice would establish a fact
    // the click has already established.
    $chorleitung = $this->subscriptions->subscribe(
        app(MailingListRepository::class)->find('chorleitung'),
        'jane@example.com',
    );
    $this->subscriptions->markSubscribed($chorleitung);
    $this->subscriptions->unsubscribe($chorleitung);

    Mail::fake();

    $this->post(route('marketing.preferences.update', $this->token), [
        'action' => 'save',
        'lists' => ['newsletter', 'events', 'chorleitung'],
    ])->assertRedirect();

    $fresh = $chorleitung->fresh();

    expect($fresh->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($fresh->confirmed_at)->not->toBeNull()
        ->and($fresh->unsubscribed_at)->toBeNull();

    Mail::assertNotSent(ConfirmSubscriptionMail::class);
});

it('records how the restored consent was given', function (): void {
    // A consent record that says only *that* it exists cannot be defended
    // later. This one says the token in the mailbox was the proof.
    $this->post(route('marketing.preferences.update', $this->token), ['lists' => ['newsletter', 'events']])
        ->assertRedirect();

    $this->travel(2)->seconds();

    $this->post(route('marketing.preferences.update', $this->token), ['lists' => []])->assertRedirect();

    $this->travel(2)->seconds();

    $this->post(route('marketing.preferences.update', $this->token), ['lists' => ['newsletter']])->assertRedirect();

    $restored = Event::query()
        ->where('type', 'marketing.subscribed')
        ->get()
        ->first(fn (Event $event) => ($event->payload['consent_proof'] ?? null) === 'unsubscribe_token');

    expect($restored)->not->toBeNull()
        ->and($restored->payload['reason'])->toBe('preference_center')
        ->and($restored->payload['list'])->toBe('newsletter');
});

it('adds a list the person was never on', function (): void {
    $this->post(route('marketing.preferences.update', $this->token), [
        'lists' => ['newsletter', 'events', 'offers'],
    ])->assertRedirect();

    $created = Subscription::query()->where('list_handle', 'offers')->first();

    expect($created)->not->toBeNull()
        ->and($created->email)->toBe('jane@example.com')
        ->and($created->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($created->source)->toBe('preference_center');
});

it('ends every list of the brand at once', function (): void {
    $this->post(route('marketing.preferences.update', $this->token), ['action' => 'unsubscribe_all'])
        ->assertRedirect();

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(currentStatus('events'))->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('does not turn ending everything into a CRM-wide opt-out', function (): void {
    // "Everything" means every list of this brand. It says nothing about
    // another brand's mailings and nothing at all about mail that does not
    // rest on consent in the first place.
    $this->post(route('marketing.preferences.update', $this->token), ['action' => 'unsubscribe_all'])
        ->assertRedirect();

    $contact = Contact::query()->where('email', 'jane@example.com')->first();

    expect((bool) $contact->do_not_contact)->toBeFalse();
});

it('cannot be used to lift a block on the contact — the security rule', function (): void {
    // A hard bounce, a spam complaint or an opt-out set by hand all land on
    // the same flag. The unsubscribe token is in every mail this brand sends,
    // so it may end consent and may restore consent the person themselves
    // ended, but it must never undo a decision made *about* the address.
    Contact::query()->where('email', 'jane@example.com')->update(['do_not_contact' => true]);

    $this->post(route('marketing.preferences.update', $this->token), ['lists' => ['newsletter', 'events', 'offers']])
        ->assertRedirect();

    expect(Subscription::query()->where('list_handle', 'offers')->exists())->toBeFalse();

    $response = $this->get(route('marketing.preferences', $this->token))->assertOk();

    expect(renderedStates($response->getContent()))->toBe([
        'offers' => 'blocked',
        'chorleitung' => 'blocked',
        'events' => 'blocked',
        'newsletter' => 'blocked',
        'saenger' => 'blocked',
    ]);

    // The row is there and it is not usable. A disabled checkbox is only a
    // suggestion, so the refusal is enforced by the service above, not here.
    expect($response->getContent())->toContain('disabled');
    $response->assertSee(__('marketing::public.preferences_blocked'));
});

it('says out loud that a blocked list was not switched on', function (): void {
    Contact::query()->where('email', 'jane@example.com')->update(['do_not_contact' => true]);

    $this->followingRedirects()
        ->post(route('marketing.preferences.update', $this->token), ['lists' => ['newsletter']])
        ->assertOk()
        ->assertSee(__('marketing::public.preferences_error_blocked'));
});

it('leaves a bounced subscription alone in both directions', function (): void {
    // Saving the form without its checkbox must not rewrite a bounce to
    // "unsubscribed": that status is the reason the sending path stopped, and
    // overwriting it would lose it.
    $this->newsletter->update(['status' => Subscription::STATUS_BOUNCED]);

    $this->post(route('marketing.preferences.update', $this->token), ['lists' => ['events']])->assertRedirect();

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_BOUNCED);

    $this->post(route('marketing.preferences.update', $this->token), ['lists' => ['newsletter', 'events']])->assertRedirect();

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_BOUNCED)
        ->and(currentStatus('events'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('shows what is still running on the page a tokenized unsubscribe link lands on', function (): void {
    $response = $this->get(route('marketing.unsubscribe', $this->token))->assertOk();

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED);

    // The moment of highest attention: the page now says what else exists and
    // offers the controls, instead of being a dead end.
    expect(renderedStates($response->getContent()))->toBe([
        'offers' => 'inactive',
        'chorleitung' => 'inactive',
        'events' => 'active',
        'newsletter' => 'inactive',
        'saenger' => 'inactive',
    ]);

    $response->assertSee(__('marketing::public.unsubscribed_manage'))
        ->assertSee(__('marketing::public.preferences_all_button'));
});

it('leaves the RFC 8058 one-click path exactly as it was', function (): void {
    // One click, no page, 204. That endpoint is for mail providers, not for
    // people; giving it a body would break deliverability.
    $response = $this->post(route('marketing.unsubscribe.post', $this->token))->assertNoContent();

    expect($response->getContent())->toBe('')
        ->and(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('reports a selection naming a list that does not exist', function (): void {
    $this->followingRedirects()
        ->post(route('marketing.preferences.update', $this->token), ['lists' => ['newsletter', 'made-up']])
        ->assertOk()
        ->assertSee(__('marketing::public.preferences_error_unknown'));

    expect(Subscription::query()->where('list_handle', 'made-up')->exists())->toBeFalse();
});

it('404s for a token nobody was ever sent', function (): void {
    $this->get(route('marketing.preferences', str_repeat('x', 48)))->assertNotFound();
    $this->post(route('marketing.preferences.update', str_repeat('x', 48)), ['lists' => []])->assertNotFound();
});
