<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Mail::fake();

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
        'campaign_handle' => 'welcome',
        'subscription_id' => $subscription->id,
        'email' => $subscription->email,
        'status' => Message::STATUS_SENT,
        'sent_at' => now(),
    ]);
});

it('records opens through the pixel endpoint', function (): void {
    $response = $this->get(route('marketing.track.open', ['uuid' => $this->message->uuid]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('image/gif');

    $message = $this->message->fresh();

    expect($message->opens)->toBe(1)
        ->and($message->first_opened_at)->not->toBeNull()
        ->and(MessageEvent::query()->where('type', 'open')->count())->toBe(1);

    // Second open increments the counter but keeps first_opened_at.
    $first = $message->first_opened_at;
    $this->get(route('marketing.track.open', ['uuid' => $this->message->uuid]));

    expect($this->message->fresh()->opens)->toBe(2)
        ->and($this->message->fresh()->first_opened_at->equalTo($first))->toBeTrue();
});

it('records clicks and redirects through the signed endpoint', function (): void {
    $url = URL::signedRoute('marketing.track.click', [
        'uuid' => $this->message->uuid,
        'url' => 'https://example.com/news',
    ]);

    $response = $this->get($url);

    $response->assertRedirect('https://example.com/news');

    $message = $this->message->fresh();

    expect($message->clicks)->toBe(1)
        ->and($message->opens)->toBe(0) // counter untouched...
        ->and($message->first_opened_at)->not->toBeNull() // ...but open implied
        ->and(MessageEvent::query()->where('type', 'click')->value('url'))->toBe('https://example.com/news');
});

it('rejects unsigned click URLs', function (): void {
    $this->get(route('marketing.track.click', [
        'uuid' => $this->message->uuid,
        'url' => 'https://evil.example.com',
    ]))->assertForbidden();

    expect($this->message->fresh()->clicks)->toBe(0);
});

it('serves the pixel even for unknown messages', function (): void {
    $this->get(route('marketing.track.open', ['uuid' => 'unknown-uuid']))->assertOk();
});
