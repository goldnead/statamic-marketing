<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\ListPreference;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionPreferences;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\Mail;

/**
 * The preference rules under multi-brand.
 *
 * This is where they could do real damage, and it is why a public request
 * derives its brand from the value the visitor already carries. Such a request
 * has no session, so nothing is current; if the derivation failed open, one
 * token would resolve one person's memberships across every brand on the
 * install and let them be changed from there.
 *
 * Marketing no longer serves the preference page — that belongs to
 * `goldnead/statamic-preference-center` — so these tests ask
 * `SubscriptionPreferences` inside the brand the middleware would have set,
 * which is the same state and the same question. What is still marketing's own
 * route, and still driven through HTTP here, is the unsubscribe: it derives its
 * brand from the token exactly as before.
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

/**
 * Every row a token resolves to, handle => state, inside the brand the
 * middleware would have derived from that token.
 */
function brandStates(Brand $brand, string $token): array
{
    $states = BrandContext::runFor($brand, function () use ($token) {
        $center = app(SubscriptionPreferences::class)->forToken($token);

        expect($center)->not->toBeNull();

        return $center->rows->mapWithKeys(fn (ListPreference $row) => [
            $row->handle() => match (true) {
                $row->suppressed => 'blocked',
                $row->active => 'active',
                default => 'inactive',
            },
        ])->all();
    });

    ksort($states);

    return $states;
}

/** Applies a selection inside one brand, the way a renderer there would. */
function applyInBrand(Brand $brand, string $token, array $wanted): array
{
    return BrandContext::runFor($brand, function () use ($token, $wanted) {
        $preferences = app(SubscriptionPreferences::class);
        $center = $preferences->forToken($token);

        expect($center)->not->toBeNull();

        return $preferences->apply($center, $wanted);
    });
}

it('resolves a token only the lists of its own brand', function (): void {
    expect(brandStates($this->brandA, $this->aNews->token))->toBe([
        'a_events' => 'active',
        'a_news' => 'active',
    ]);
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

    // And here is why that route shows nothing: one row is built per list of
    // *this* brand and the person's subscriptions are keyed onto it by handle —
    // and a list handle has exactly one owner across the whole install, enforced
    // by the flat store and by a global unique index. Brand B's membership has
    // no row of brand A's to land on, whatever the identity match returns.
    //
    // If a later change ever made handles unique per brand instead, this fails
    // and says so here, because that is the day the brand scope on the
    // subscription query becomes the only thing left holding this.
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

    // So: one address in two brands, and one brand in the answer.
    expect(brandStates($this->brandA, $this->aNews->token))->toBe([
        'a_events' => 'active',
        'a_news' => 'active',
    ]);
});

it('asks the suppression question in the brand whose lists it is showing', function (): void {
    // D1: a hard bounce is a fact about the mailbox and is recorded once for
    // every brand; a complaint is a fact about one relationship and stays in the
    // brand that received it. One answer covers exactly one brand's lists, so
    // each row has to be asked "blocked *here*" — not "blocked anywhere", which
    // would let brand B's complaint shut brand A down, and not "blocked in this
    // brand's own rows only", which would let a dead mailbox keep being offered.
    BrandContext::runFor($this->brandB, fn () => app(SuppressionService::class)
        ->suppress('jane@example.com', Reasons::COMPLAINT));

    expect(brandStates($this->brandA, $this->aNews->token))->toBe([
        'a_events' => 'active',
        'a_news' => 'active',
    ]);

    BrandContext::runFor($this->brandB, fn () => app(SuppressionService::class)
        ->suppress('jane@example.com', Reasons::HARD_BOUNCE));

    expect(brandStates($this->brandA, $this->aNews->token))->toBe([
        'a_events' => 'blocked',
        'a_news' => 'blocked',
    ]);
});

it('refuses to change another brand\'s list from this brand\'s token', function (): void {
    applyInBrand($this->brandA, $this->aNews->token, ['a_news', 'a_events', 'b_news']);

    // Not created in brand A under a borrowed handle, and not touched in B.
    expect(BrandContext::runFor($this->brandA,
        fn () => Subscription::query()->where('list_handle', 'b_news')->exists()
    ))->toBeFalse();

    expect(statusIn($this->brandB, 'b_news'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('keeps "unsubscribe from everything" inside the brand of its token', function (): void {
    BrandContext::runFor($this->brandA, function (): void {
        $preferences = app(SubscriptionPreferences::class);
        $preferences->unsubscribeFromEverything($preferences->forToken($this->aNews->token));
    });

    expect(statusIn($this->brandA, 'a_news'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(statusIn($this->brandA, 'a_events'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(statusIn($this->brandB, 'b_news'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('answers another brand\'s list handle exactly as it answers an invented one', function (): void {
    // Both are simply not lists of this brand, and the difference must not
    // show: telling a stranger which handles exist elsewhere on the install is
    // telling them which brands exist. The two answers differ only in the
    // handle that was echoed back, and in nothing else.
    $withForeign = applyInBrand($this->brandA, $this->aNews->token, ['a_news', 'a_events', 'b_news']);
    $withInvented = applyInBrand($this->brandA, $this->aNews->token, ['a_news', 'a_events', 'not-a-list-anywhere']);

    expect($withForeign['unknown'])->toBe(['b_news'])
        ->and($withInvented['unknown'])->toBe(['not-a-list-anywhere'])
        ->and($withForeign['refused'])->toBe($withInvented['refused'])
        ->and($withForeign['subscribed'])->toBe($withInvented['subscribed'])
        ->and($withForeign['unsubscribed'])->toBe($withInvented['unsubscribed']);
});

it('derives the brand from the token on the unsubscribe route it still owns', function (): void {
    // Marketing keeps exactly one public tokenized page, and the derivation on
    // it is the part that must not fail open: no session, so nothing is
    // current, and a fail-closed scope would otherwise hide the very row the
    // link addresses.
    $this->get('/!/marketing/unsubscribe/'.$this->bNews->token)->assertOk();

    expect(statusIn($this->brandB, 'b_news'))->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and(statusIn($this->brandA, 'a_news'))->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and(statusIn($this->brandA, 'a_events'))->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('gives a made-up token nothing at all', function (): void {
    $invented = $this->get('/!/marketing/unsubscribe/'.str_repeat('x', 48));

    expect($invented->getStatusCode())->toBe(404)
        ->and($invented->getContent())->not->toContain('jane@example.com');
});
