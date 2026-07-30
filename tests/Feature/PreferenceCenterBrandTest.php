<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\Mail;

/**
 * The preference centre under multi-brand.
 *
 * This is where the page could do real damage, and it is the reason the brand
 * is derived from the token rather than read from a session. The page is
 * opened with no session at all, so nothing is current; if the derivation
 * failed open, one token would show one person their memberships across every
 * brand on the install and let them be changed from there.
 *
 * The same address is deliberately used in both brands, which is the ordinary
 * case rather than a contrivance. Until leadhub 1.11.0 that meant one contact
 * for both, so the identity reached across brands on its own and the brand
 * scope was the only thing holding it. 1.11.0 isolated the flat contact store
 * per brand, so it is now two contacts and that particular door is shut a
 * second time.
 *
 * The address itself still crosses: `subscriptionsOfThePerson()` matches on
 * `email_normalized` as well as on the contact, and both brands hold a
 * subscription row carrying it. `marketing_subscriptions` is therefore the path
 * the brand scope still has to hold shut, and it is what these tests pin.
 */
beforeEach(function (): void {
    Mail::fake();

    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    BrandContext::runFor($this->brandA, function (): void {
        $lists = app(MailingListRepository::class);
        $lists->save(new MailingList(handle: 'a_news', name: 'A Newsletter', doubleOptIn: false));
        $lists->save(new MailingList(handle: 'a_events', name: 'A Events', doubleOptIn: false));

        $this->aNews = app(SubscriptionService::class)->subscribe($lists->find('a_news'), 'jane@example.com');
        $this->aEvents = app(SubscriptionService::class)->subscribe($lists->find('a_events'), 'jane@example.com');
    });

    BrandContext::runFor($this->brandB, function (): void {
        $lists = app(MailingListRepository::class);
        $lists->save(new MailingList(handle: 'b_news', name: 'B Newsletter', doubleOptIn: false));

        $this->bNews = app(SubscriptionService::class)->subscribe($lists->find('b_news'), 'jane@example.com');
    });

    BrandContext::forget();
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(function (): void {
        Message::query()->delete();
        Subscription::query()->delete();
        MailingListRecord::query()->delete();
    });
});

function statusIn(Brand $brand, string $listHandle): ?string
{
    return BrandContext::runFor($brand, fn () => Subscription::query()
        ->where('list_handle', $listHandle)
        ->value('status'));
}

/** Every row the page rendered for a token, handle => state, by handle. */
function pageStates(string $token): array
{
    $html = test()->get('/!/marketing/preferences/'.$token)->assertOk()->getContent();

    preg_match_all('/data-list="([^"]+)"\s+data-state="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

    $states = collect($matches)->mapWithKeys(fn ($m) => [$m[1] => $m[2]])->all();

    ksort($states);

    return $states;
}

it('shows a token only the lists of its own brand', function (): void {
    $content = $this->get('/!/marketing/preferences/'.$this->aNews->token)->assertOk()->getContent();

    preg_match_all('/data-list="([^"]+)"/', $content, $matches);

    expect($matches[1])->toBe(['a_events', 'a_news'])
        ->and($content)->not->toContain('b_news')
        ->and($content)->not->toContain('B Newsletter');
});

it('has no handle another brand\'s subscription could be drawn onto', function (): void {
    // What this test used to say: one contact spans both brands, so the identity
    // reaches across on its own and only the brand scope holds it. leadhub
    // 1.11.0 isolated the flat contact store per brand, so that is no longer
    // true — and its going is an improvement, not a regression.
    $a = BrandContext::runFor($this->brandA, fn () => Subscription::query()->where('list_handle', 'a_news')->first());
    $b = BrandContext::runFor($this->brandB, fn () => Subscription::query()->where('list_handle', 'b_news')->first());

    expect($a->contact_uuid)->not->toBeNull()
        ->and($b->contact_uuid)->not->toBeNull()
        ->and($a->contact_uuid)->not->toBe($b->contact_uuid);

    // What is still true, and is the sharper question: the *subscriptions* carry
    // the same address in both brands, and `subscriptionsOfThePerson()` matches
    // on `email_normalized` as well as on the contact. They are a second route
    // to the person that does not go through LeadHub at all.
    $handles = BrandContext::withoutBrandScope(fn () => Subscription::query()
        ->where('email_normalized', 'jane@example.com')
        ->pluck('list_handle')
        ->sort()
        ->values()
        ->all());

    expect($handles)->toBe(['a_events', 'a_news', 'b_news']);

    // And here is why that route shows nothing: the page builds one row per list
    // of *this* brand and keys the person's subscriptions onto it by handle — and
    // a list handle has exactly one owner across the whole install, enforced by
    // the flat store and by a global unique index. Brand B's membership has no
    // row of brand A's to land on, whatever the identity match returns.
    //
    // If a later change ever made handles unique per brand instead, this fails
    // and says so here, because that is the day the brand scope on the
    // subscription query becomes the only thing left holding this page.
    $collision = null;

    try {
        BrandContext::runFor($this->brandB, fn () => app(MailingListRepository::class)
            ->save(new MailingList(handle: 'a_news', name: 'B under A\'s handle', doubleOptIn: false)));
    } catch (Throwable $e) {
        $collision = $e;
    }

    expect($collision)->not->toBeNull()
        ->and(BrandContext::runFor($this->brandB,
            fn () => app(MailingListRepository::class)->all()->pluck('handle')->sort()->values()->all()
        ))->toBe(['b_news']);

    // So: one address in two brands, and one brand on the page.
    expect(pageStates($this->aNews->token))->toBe([
        'a_events' => 'active',
        'a_news' => 'active',
    ]);
});

it('asks the suppression question in the brand whose lists it is showing', function (): void {
    // D1: a hard bounce is a fact about the mailbox and is recorded once for
    // every brand; a complaint is a fact about one relationship and stays in the
    // brand that received it. This page shows exactly one brand's lists, so each
    // row has to be asked "blocked *here*" — not "blocked anywhere", which would
    // let brand B's complaint shut brand A's page, and not "blocked in this
    // brand's own rows only", which would let a dead mailbox keep being offered.
    BrandContext::runFor($this->brandB, fn () => app(SuppressionService::class)
        ->suppress('jane@example.com', Reasons::COMPLAINT));

    expect(pageStates($this->aNews->token))->toBe([
        'a_events' => 'active',
        'a_news' => 'active',
    ]);

    BrandContext::runFor($this->brandB, fn () => app(SuppressionService::class)
        ->suppress('jane@example.com', Reasons::HARD_BOUNCE));

    expect(pageStates($this->aNews->token))->toBe([
        'a_events' => 'blocked',
        'a_news' => 'blocked',
    ]);
});

it('refuses to change another brand\'s list from this brand\'s token', function (): void {
    $this->post('/!/marketing/preferences/'.$this->aNews->token, [
        'action' => 'save',
        'lists' => ['a_news', 'a_events', 'b_news'],
    ])->assertRedirect();

    // Not created in brand A under a borrowed handle, and not touched in B.
    expect(BrandContext::runFor($this->brandA,
        fn () => Subscription::query()->where('list_handle', 'b_news')->exists()
    ))->toBeFalse();

    expect(statusIn($this->brandB, 'b_news'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('keeps "unsubscribe from everything" inside the brand of its token', function (): void {
    $this->post('/!/marketing/preferences/'.$this->aNews->token, ['action' => 'unsubscribe_all'])
        ->assertRedirect();

    expect(statusIn($this->brandA, 'a_news'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(statusIn($this->brandA, 'a_events'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(statusIn($this->brandB, 'b_news'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('answers another brand\'s list handle exactly as it answers an invented one', function (): void {
    // Both are simply not lists of this brand, and the page must not let the
    // difference show: telling a stranger which handles exist elsewhere on the
    // install is telling them which brands exist.
    // Both selections keep this brand's two lists exactly as they are, so the
    // only difference between the requests is the handle that is not ours.
    $withForeign = $this->followingRedirects()->post('/!/marketing/preferences/'.$this->aNews->token, [
        'lists' => ['a_news', 'a_events', 'b_news'],
    ])->assertOk();

    $withInvented = $this->followingRedirects()->post('/!/marketing/preferences/'.$this->aNews->token, [
        'lists' => ['a_news', 'a_events', 'not-a-list-anywhere'],
    ])->assertOk();

    expect($withForeign->getContent())->toBe($withInvented->getContent())
        ->and($withForeign->getContent())->toContain(__('marketing::public.preferences_error_unknown'));
});

it('gives another brand\'s token nothing a made-up token would not get', function (): void {
    // The subscription exists and its token is valid — for brand B. Asked for
    // under a URL, the derivation puts the request in B, so it is B's page. It
    // must never be A's, and it must never be a 200 that leaks A.
    $foreign = $this->get('/!/marketing/preferences/'.$this->bNews->token)->assertOk();
    $invented = $this->get('/!/marketing/preferences/'.str_repeat('x', 48));

    expect($invented->getStatusCode())->toBe(404)
        ->and($foreign->getContent())->not->toContain('a_news')
        ->and($foreign->getContent())->not->toContain('A Newsletter')
        ->and($invented->getContent())->not->toContain('jane@example.com');
});
