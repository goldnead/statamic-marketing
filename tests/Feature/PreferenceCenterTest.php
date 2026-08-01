<?php

use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Models\Event;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\ListPreference;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionPreferences;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\Mail;

/**
 * The preference rules — the service, not a page.
 *
 * Marketing no longer renders a preference page. That screen belongs to
 * `goldnead/statamic-preference-center`, which shows mailing lists,
 * notification types and the suppression state together; two addons shipping
 * the same form meant marketing kept linking to its own copy and installing
 * the centre changed nothing a reader could see.
 *
 * What did not move is everything below. `SubscriptionPreferences` is the
 * consent logic — which rows a token may see, which of them may be switched
 * on, what a refusal is — and the preference centre reads it rather than
 * reimplementing it (`Sources/MarketingSource.php` over there names this class
 * by constant). So the rules are pinned here, at the layer that owns them,
 * where they hold for whichever addon renders them.
 *
 * These tests used to drive the removed HTTP page. Everything they asserted
 * about behaviour is kept; only the assertions about markup are gone, and the
 * one thing markup was proving — that a blocked row cannot be switched on even
 * when the request pretends otherwise — was never enforced by the `disabled`
 * attribute anyway. It is enforced in `apply()`, and that is where it is
 * checked now.
 *
 * The five lists are the ones adriangoldner.com actually runs, because the
 * defect this answers only exists once a brand has more than one: an
 * unsubscribe has always been per list, so somebody who wanted to keep the
 * events had to wait for four more mails and click four more links.
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
    $this->preferences = app(SubscriptionPreferences::class);

    $this->newsletter = $this->subscriptions->subscribe($lists->find('newsletter'), 'jane@example.com');
    $this->events = $this->subscriptions->subscribe($lists->find('events'), 'jane@example.com');

    $this->token = $this->newsletter->token;
});

/**
 * Every row a token resolves to, as handle => state.
 *
 * The three states are the ones a renderer has to distinguish and the only
 * ones: on, off, and cannot be switched on at all.
 */
function preferenceStates(string $token): array
{
    $center = app(SubscriptionPreferences::class)->forToken($token);

    expect($center)->not->toBeNull();

    return $center->rows->mapWithKeys(fn (ListPreference $row) => [
        $row->handle() => match (true) {
            $row->suppressed => 'blocked',
            $row->active => 'active',
            default => 'inactive',
        },
    ])->all();
}

/** Applies a wanted selection the way a renderer would, and reports back. */
function applyPreferences(string $token, array $wanted): array
{
    $preferences = app(SubscriptionPreferences::class);
    $center = $preferences->forToken($token);

    expect($center)->not->toBeNull();

    return $preferences->apply($center, $wanted);
}

function currentStatus(string $listHandle, string $email = 'jane@example.com'): ?string
{
    return Subscription::query()
        ->where('list_handle', $listHandle)
        ->where('email', $email)
        ->value('status');
}

it('resolves every list of the brand with its current state, from the token alone', function (): void {
    // No session is involved and none is needed: the token is the credential,
    // because almost no subscriber has an account and a login form in front of
    // an unsubscribe is a dark pattern.
    $center = $this->preferences->forToken($this->token);

    expect($center->email())->toBe('jane@example.com')
        ->and(preferenceStates($this->token))->toBe([
            'offers' => 'inactive',
            'chorleitung' => 'inactive',
            'events' => 'active',
            'newsletter' => 'active',
            'saenger' => 'inactive',
        ]);
});

it('unsubscribes a single list and leaves the others running', function (): void {
    applyPreferences($this->token, ['events']);

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

    applyPreferences($this->token, ['newsletter', 'events', 'chorleitung']);

    $fresh = $chorleitung->fresh();

    expect($fresh->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($fresh->confirmed_at)->not->toBeNull()
        ->and($fresh->unsubscribed_at)->toBeNull();

    Mail::assertNotSent(ConfirmSubscriptionMail::class);
});

it('records how the restored consent was given', function (): void {
    // A consent record that says only *that* it exists cannot be defended
    // later. This one says the token in the mailbox was the proof.
    applyPreferences($this->token, ['newsletter', 'events']);

    $this->travel(2)->seconds();

    applyPreferences($this->token, []);

    $this->travel(2)->seconds();

    applyPreferences($this->token, ['newsletter']);

    $restored = Event::query()
        ->where('type', 'marketing.subscribed')
        ->get()
        ->first(fn (Event $event) => ($event->payload['consent_proof'] ?? null) === 'unsubscribe_token');

    expect($restored)->not->toBeNull()
        ->and($restored->payload['reason'])->toBe('preference_center')
        ->and($restored->payload['list'])->toBe('newsletter');
});

it('adds a list the person was never on', function (): void {
    applyPreferences($this->token, ['newsletter', 'events', 'offers']);

    $created = Subscription::query()->where('list_handle', 'offers')->first();

    expect($created)->not->toBeNull()
        ->and($created->email)->toBe('jane@example.com')
        ->and($created->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($created->source)->toBe('preference_center');
});

it('ends every list of the brand at once', function (): void {
    $center = $this->preferences->forToken($this->token);

    $this->preferences->unsubscribeFromEverything($center);

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(currentStatus('events'))->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('does not turn ending everything into a CRM-wide opt-out', function (): void {
    // "Everything" means every list of this brand. It says nothing about
    // another brand's mailings and nothing at all about mail that does not
    // rest on consent in the first place.
    $this->preferences->unsubscribeFromEverything($this->preferences->forToken($this->token));

    $contact = Contact::query()->where('email', 'jane@example.com')->first();

    expect((bool) $contact->do_not_contact)->toBeFalse();
});

it('cannot be used to lift a block on the contact — the security rule', function (): void {
    // A hard bounce, a spam complaint or an opt-out set by hand all land on
    // the same flag. The unsubscribe token is in every mail this brand sends,
    // so it may end consent and may restore consent the person themselves
    // ended, but it must never undo a decision made *about* the address.
    Contact::query()->where('email', 'jane@example.com')->update(['do_not_contact' => true]);

    $result = applyPreferences($this->token, ['newsletter', 'events', 'offers']);

    expect(Subscription::query()->where('list_handle', 'offers')->exists())->toBeFalse()
        ->and($result['refused'])->toContain('offers')
        ->and(preferenceStates($this->token))->toBe([
            'offers' => 'blocked',
            'chorleitung' => 'blocked',
            'events' => 'blocked',
            'newsletter' => 'blocked',
            'saenger' => 'blocked',
        ]);
});

it('cannot be used to lift a block that exists only in the suppression list', function (): void {
    // The same rule, met at the source the four send paths were pointed at in
    // 1.8.0. Nothing is written to the contact here — a provider event lands in
    // the `suppressions` table and nowhere else — so asking LeadHub alone sees
    // an ordinary subscriber and offers every list back.
    app(SuppressionService::class)->suppress('jane@example.com', Reasons::COMPLAINT);

    expect((bool) Contact::query()->where('email', 'jane@example.com')->value('do_not_contact'))
        ->toBeFalse();

    // The manipulated request: a renderer's `disabled` attribute is a
    // suggestion, and this is the selection that arrives once somebody has
    // removed it. The refusal is the service's, which is why it survives.
    $result = applyPreferences($this->token, ['newsletter', 'events', 'offers']);

    expect($result['refused'])->toContain('offers')
        ->and(Subscription::query()->where('list_handle', 'offers')->exists())->toBeFalse()
        ->and(preferenceStates($this->token))->toBe([
            'offers' => 'blocked',
            'chorleitung' => 'blocked',
            'events' => 'blocked',
            'newsletter' => 'blocked',
            'saenger' => 'blocked',
        ]);
});

it('asks about the address each row would actually be mailed at', function (): void {
    // One person, two mailboxes, tied together by the `contact_uuid` this
    // resolution uses as its identity. The rows therefore do not all carry the
    // same address, and a single question asked for the token's address would
    // answer for the wrong mailbox on every other row.
    $saenger = $this->subscriptions->subscribe(
        app(MailingListRepository::class)->find('saenger'),
        'jane.alt@example.com',
    );

    $saenger->update(['contact_uuid' => $this->newsletter->fresh()->contact_uuid]);

    expect($saenger->fresh()->contact_uuid)->not->toBeNull();

    // The second mailbox is gone; the first one is fine.
    app(SuppressionService::class)->suppress('jane.alt@example.com', Reasons::HARD_BOUNCE);

    expect(preferenceStates($this->token))->toBe([
        'offers' => 'inactive',
        'chorleitung' => 'inactive',
        'events' => 'active',
        'newsletter' => 'active',
        'saenger' => 'blocked',
    ]);

    // And the rows that are not blocked still work, which is the half of this
    // a blanket answer would also have got wrong.
    applyPreferences($this->token, ['newsletter', 'events', 'offers']);

    expect(currentStatus('offers'))->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and(currentStatus('saenger', 'jane.alt@example.com'))->toBe(Subscription::STATUS_PENDING);
});

it('names the blocked list it did not switch on, rather than dropping it', function (): void {
    // Silence here is the failure mode: nobody files a ticket about a
    // newsletter, they file a spam complaint. The renderer can only say what
    // the service hands it, so the service hands it the handle.
    Contact::query()->where('email', 'jane@example.com')->update(['do_not_contact' => true]);

    $result = applyPreferences($this->token, ['newsletter']);

    expect($result['refused'])->toContain('newsletter')
        ->and($result['subscribed'])->toBe([]);
});

it('leaves a bounced subscription alone in both directions', function (): void {
    // Saving without a list selected must not rewrite a bounce to
    // "unsubscribed": that status is the reason the sending path stopped, and
    // overwriting it would lose it.
    $this->newsletter->update(['status' => Subscription::STATUS_BOUNCED]);

    applyPreferences($this->token, ['events']);

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_BOUNCED);

    applyPreferences($this->token, ['newsletter', 'events']);

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_BOUNCED)
        ->and(currentStatus('events'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('ends the subscription on the page a tokenized unsubscribe link lands on', function (): void {
    // Marketing's own page, the one it keeps. It does one thing, and it does it
    // without any optional package installed.
    $response = $this->get(route('marketing.unsubscribe', $this->token))->assertOk();

    expect(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(currentStatus('events'))->toBe(Subscription::STATUS_SUBSCRIBED);

    $response->assertSee(__('marketing::public.unsubscribed_title'));
});

it('offers no preference page of its own when no preference centre is installed', function (): void {
    // The duplication that was removed: marketing must not present a second
    // multi-list form, and it must not link to a route nobody registered.
    $content = $this->get(route('marketing.unsubscribe', $this->token))->assertOk()->getContent();

    expect($content)->not->toContain('preference-center')
        ->and($content)->not->toContain('data-list=')
        ->and(app(\Goldnead\Marketing\Support\PreferenceLink::class)->centerAvailable())->toBeFalse();
});

it('leaves the RFC 8058 one-click path exactly as it was', function (): void {
    // One click, no page, 204. That endpoint is for mail providers, not for
    // people; giving it a body would break deliverability. It stays on
    // marketing whatever else is installed — stopping mail is not optional.
    $response = $this->post(route('marketing.unsubscribe.post', $this->token))->assertNoContent();

    expect($response->getContent())->toBe('')
        ->and(currentStatus('newsletter'))->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('reports a selection naming a list that does not exist', function (): void {
    $result = applyPreferences($this->token, ['newsletter', 'made-up']);

    expect($result['unknown'])->toBe(['made-up'])
        ->and(Subscription::query()->where('list_handle', 'made-up')->exists())->toBeFalse();
});

it('resolves nothing for a token nobody was ever sent', function (): void {
    expect($this->preferences->forToken(str_repeat('x', 48)))->toBeNull();

    $this->get(route('marketing.unsubscribe', str_repeat('x', 48)))->assertNotFound();
    $this->post(route('marketing.unsubscribe.post', str_repeat('x', 48)))->assertNotFound();
});
