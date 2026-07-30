<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
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
 * case rather than a contrivance: LeadHub keeps one contact per address, so
 * both subscriptions carry the *same* `contact_uuid`. The identity that finds
 * the person's other subscriptions therefore matches across brands on its own,
 * and only the brand scope stops it. That is exactly the thing worth pinning.
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

it('shows a token only the lists of its own brand', function (): void {
    $content = $this->get('/!/marketing/preferences/'.$this->aNews->token)->assertOk()->getContent();

    preg_match_all('/data-list="([^"]+)"/', $content, $matches);

    expect($matches[1])->toBe(['a_events', 'a_news'])
        ->and($content)->not->toContain('b_news')
        ->and($content)->not->toContain('B Newsletter');
});

it('shares one contact across the brands and still shows only one brand of it', function (): void {
    // Both subscriptions really are the same person by the identity this page
    // uses. If the brand scope were the only thing keeping them apart and it
    // were removed, this expectation would be what breaks.
    $a = BrandContext::runFor($this->brandA, fn () => Subscription::query()->where('list_handle', 'a_news')->first());
    $b = BrandContext::runFor($this->brandB, fn () => Subscription::query()->where('list_handle', 'b_news')->first());

    expect($a->contact_uuid)->not->toBeNull()->and($a->contact_uuid)->toBe($b->contact_uuid);
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
