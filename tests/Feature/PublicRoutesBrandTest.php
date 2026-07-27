<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Marketing\Models\MailingListRecord;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The public routes under multi-brand.
 *
 * A confirmation link, an unsubscribe link and a tracking pixel are opened
 * without a session. No brand is current, the fail-closed scope hides the very
 * record the token points at, and the link 404s while tracking silently stores
 * nothing. Every test here runs with no brand set on purpose — that is the
 * situation, not an omission.
 */
beforeEach(function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $this->brandA = Brand::create(['handle' => 'brand-a', 'name' => 'Brand A']);
    $this->brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $this->subA = BrandContext::runFor($this->brandA, fn () => Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'a@example.com',
        'status' => Subscription::STATUS_PENDING,
        'token' => Str::random(48),
    ]));

    $this->subB = BrandContext::runFor($this->brandB, fn () => Subscription::create([
        'list_handle' => 'other',
        'email' => 'b@example.com',
        'status' => Subscription::STATUS_SUBSCRIBED,
        'confirmed_at' => now(),
        'token' => Str::random(48),
    ]));

    BrandContext::forget();
});

afterEach(function (): void {
    BrandContext::withoutBrandScope(function (): void {
        Message::query()->delete();
        Subscription::query()->delete();
        MailingListRecord::query()->delete();
    });
});

it('confirms a subscription from a link opened without any session', function (): void {
    $this->get('/!/marketing/confirm/'.$this->subA->token)->assertOk();

    BrandContext::setCurrent($this->brandA);
    expect($this->subA->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('unsubscribes from a link opened without any session', function (): void {
    $this->get('/!/marketing/unsubscribe/'.$this->subB->token)->assertOk();

    BrandContext::setCurrent($this->brandB);
    expect($this->subB->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('accepts the one-click unsubscribe a mail provider sends itself', function (): void {
    // RFC 8058: a POST with no session and no CSRF token. This returned 419
    // because the excluded middleware was named after a class Laravel had
    // since renamed — the exclusion silently matched nothing.
    $this->post('/!/marketing/unsubscribe/'.$this->subB->token)->assertNoContent();

    BrandContext::setCurrent($this->brandB);
    expect($this->subB->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('records an open from a pixel loaded without a session', function (): void {
    $message = BrandContext::runFor($this->brandB, fn () => Message::create([
        'campaign_handle' => 'c1',
        'subscription_id' => $this->subB->id,
        'email' => 'b@example.com',
        'status' => Message::STATUS_SENT,
    ]));

    $this->get('/!/marketing/o/'.$message->uuid.'.gif')->assertOk();

    BrandContext::setCurrent($this->brandB);
    expect($message->fresh()->opens)->toBe(1);
});

it('records a click from a redirect followed without a session', function (): void {
    $message = BrandContext::runFor($this->brandB, fn () => Message::create([
        'campaign_handle' => 'c1',
        'subscription_id' => $this->subB->id,
        'email' => 'b@example.com',
        'status' => Message::STATUS_SENT,
    ]));

    $url = URL::signedRoute('marketing.track.click', [
        'uuid' => $message->uuid,
        'url' => 'https://example.com/x',
    ]);

    $this->get($url)->assertRedirect('https://example.com/x');

    BrandContext::setCurrent($this->brandB);
    expect($message->fresh()->clicks)->toBe(1);
});

it('leaves an unknown token as a 404 and changes nothing', function (): void {
    $this->get('/!/marketing/confirm/'.str_repeat('x', 48))->assertNotFound();
    $this->get('/!/marketing/unsubscribe/'.str_repeat('x', 48))->assertNotFound();

    BrandContext::setCurrent($this->brandA);
    expect($this->subA->fresh()->status)->toBe(Subscription::STATUS_PENDING);
});

it('touches only the brand its own token belongs to — the security boundary', function (): void {
    // Brand B's token must never reach into brand A. Both subscriptions exist,
    // both are addressable by their own token, and neither request may see the
    // other's row.
    $this->get('/!/marketing/unsubscribe/'.$this->subB->token)->assertOk();

    BrandContext::setCurrent($this->brandA);
    expect($this->subA->fresh()->status)->toBe(Subscription::STATUS_PENDING);

    BrandContext::setCurrent($this->brandB);
    expect($this->subB->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('subscribes through the public form, which names its list', function (): void {
    // Flat-file storage keeps lists in YAML, where nothing carries a brand, so
    // there is no brand to derive from a list handle at all. Multi-brand needs
    // the eloquent driver — which is exactly what a multi-brand install runs.
    config()->set('marketing.storage.driver', 'eloquent');

    BrandContext::runFor($this->brandB, fn () => MailingListRecord::create([
        'handle' => 'public-list',
        'name' => 'Public',
        'double_opt_in' => false,
    ]));

    $this->post('/!/marketing/subscribe', [
        'email' => 'newcomer@example.com',
        'list' => 'public-list',
    ])->assertRedirect();

    // The row must land in the brand that owns the list, not in the default one.
    $created = BrandContext::withoutBrandScope(
        fn () => Subscription::query()->where('email', 'newcomer@example.com')->first()
    );

    expect($created)->not->toBeNull()
        ->and($created->brand_id)->toBe($this->brandB->id);
});
