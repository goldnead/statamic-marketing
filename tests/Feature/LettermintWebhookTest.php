<?php

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\EspEventProcessor;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Contracts\Gate;
use Goldnead\Suppression\Facades\Suppression;
use Goldnead\Suppression\Models\SuppressionEvent;
use Goldnead\Suppression\Reasons;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const LM_SECRET = 'whsec_test_secret_not_real';

beforeEach(function (): void {
    Mail::fake();
    Cache::flush();
    config(['marketing.lettermint.webhook_secret' => LM_SECRET]);

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

/**
 * Posts a delivery signed the way Lettermint signs it: HMAC-SHA256 over
 * "{t}.{raw body}" with the whsec_ secret as is.
 *
 * @param  array<string,string>  $headers  overrides, keyed by the real header name
 */
function lettermint(array $payload, ?string $secret = LM_SECRET, ?int $timestamp = null, array $headers = []): TestResponse
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $t = $timestamp ?? time();
    $headers = array_merge([
        'X-Lettermint-Signature' => 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, (string) $secret),
        'X-Lettermint-Delivery' => (string) $t,
        'X-Lettermint-Event' => $payload['event'] ?? '',
        'X-Lettermint-Attempt' => '1',
    ], $headers);

    $server = ['CONTENT_TYPE' => 'application/json'];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', route('marketing.webhooks.lettermint'), [], [], [], $server, $body);
}

function lmMessageEvent(string $event, array $data = []): array
{
    return [
        'id' => (string) Str::uuid(),
        'event' => $event,
        'timestamp' => '2025-08-08T20:15:30.000Z',
        'data' => array_merge([
            'message_id' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
            'subject' => 'Summer sale - 50% off!',
            'recipient' => 'jane@example.com',
            'metadata' => [],
            'tag' => 'newsletter',
        ], $data),
    ];
}

function lmSuppression(string $reason, string $value = 'jane@example.com', string $type = 'email'): array
{
    return [
        'id' => (string) Str::uuid(),
        'event' => 'suppression.added',
        'timestamp' => '2026-07-29T08:15:30.000Z',
        'context' => ['scope' => 'team', 'team_id' => '9f4e3d2c-1b0a-4987-9654-3210fedcba98', 'project_id' => null, 'route_id' => null],
        'data' => [
            'suppression_id' => '1d74d4c9-8492-4f5f-ae3f-f6fd671399ea',
            'type' => $type,
            'value' => $value,
            'reason' => $reason,
            'applies_to' => 'all',
        ],
    ];
}

it('suppresses and opts the contact out on message.hard_bounced', function (): void {
    lettermint(lmMessageEvent('message.hard_bounced'))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_BOUNCED);

    $contact = LeadHub::findByEmail('jane@example.com');
    expect((bool) Contact::query()->where('uuid', $contact['uuid'])->first()->do_not_contact)->toBeTrue();
});

it('leaves the subscription alone on message.soft_bounced', function (): void {
    lettermint(lmMessageEvent('message.soft_bounced'))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('marks the subscription complained on message.spam_complaint', function (): void {
    lettermint(lmMessageEvent('message.spam_complaint'))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_COMPLAINED);
});

it('unsubscribes on message.unsubscribed', function (): void {
    lettermint(lmMessageEvent('message.unsubscribed', ['unsubscribed_at' => '2025-08-08T20:16:30.000Z']))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('maps suppression.added by reason', function (string $reason, string $status): void {
    lettermint(lmSuppression($reason))->assertOk();

    expect($this->subscription->fresh()->status)->toBe($status);
})->with([
    'hard_bounce' => ['hard_bounce', Subscription::STATUS_BOUNCED],
    'spam_complaint' => ['spam_complaint', Subscription::STATUS_COMPLAINED],
    'unsubscribe' => ['unsubscribe', Subscription::STATUS_UNSUBSCRIBED],
]);

it('ignores a manual suppression, a domain suppression and suppression.removed', function (): void {
    lettermint(lmSuppression('manual'))->assertOk();
    lettermint(lmSuppression('hard_bounce', 'example.com', 'domain'))->assertOk();

    $removed = lmSuppression('hard_bounce');
    $removed['event'] = 'suppression.removed';
    lettermint($removed)->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('acknowledges the test delivery and events it does not use without a side effect', function (): void {
    lettermint([
        'id' => 'test-7f9c8e2a-1b3d-4f6e-b7d2-5c9f3a7e8b0c',
        'event' => 'webhook.test',
        'timestamp' => '2025-08-08T20:14:12.000Z',
        'data' => ['message' => 'This is a test webhook from Lettermint'],
    ])->assertOk();
    lettermint(lmMessageEvent('message.delivered'))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('rejects a wrong signature with 401 and changes nothing', function (): void {
    lettermint(lmMessageEvent('message.hard_bounced'), 'whsec_someone_elses_secret')->assertStatus(401);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('rejects a body that was changed after signing', function (): void {
    $payload = lmMessageEvent('message.hard_bounced');
    $t = time();
    $signed = $t.'.'.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $payload['data']['recipient'] = 'someone.else@example.com';
    $server = [
        'HTTP_X_LETTERMINT_SIGNATURE' => 't='.$t.',v1='.hash_hmac('sha256', $signed, LM_SECRET),
        'HTTP_X_LETTERMINT_DELIVERY' => (string) $t,
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', route('marketing.webhooks.lettermint'), [], [], [], $server, json_encode($payload, JSON_UNESCAPED_SLASHES))
        ->assertStatus(401);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('rejects a missing signature header', function (): void {
    $this->call('POST', route('marketing.webhooks.lettermint'), [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(lmMessageEvent('message.hard_bounced')))
        ->assertStatus(401);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('rejects a stale timestamp', function (): void {
    lettermint(lmMessageEvent('message.hard_bounced'), LM_SECRET, time() - 3600)->assertStatus(401);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('rejects a delivery header that differs from the signed timestamp', function (): void {
    lettermint(lmMessageEvent('message.hard_bounced'), LM_SECRET, null, ['X-Lettermint-Delivery' => (string) (time() - 5)])
        ->assertStatus(401);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('is closed while no secret is configured', function (): void {
    config(['marketing.lettermint.webhook_secret' => '']);

    lettermint(lmMessageEvent('message.hard_bounced'), '')->assertNotFound();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('processes a repeated delivery id only once', function (): void {
    $payload = lmMessageEvent('message.unsubscribed');
    lettermint($payload)->assertOk();

    $this->subscription->fresh()->update(['status' => Subscription::STATUS_SUBSCRIBED]);

    lettermint($payload)->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('does not swallow the retry when processing the first delivery threw', function (): void {
    $payload = lmMessageEvent('message.unsubscribed');

    $broken = Mockery::mock(EspEventProcessor::class);
    $broken->shouldReceive('process')->once()->andThrow(new RuntimeException('database went away'));
    app()->instance(EspEventProcessor::class, $broken);

    lettermint($payload)->assertStatus(500);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_SUBSCRIBED);

    app()->forgetInstance(EspEventProcessor::class);

    // Lettermint retries with the same event id; the unsubscribe must land now.
    lettermint($payload)->assertOk()->assertJson(['handled' => true]);

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('lists a hard bounce on the suppression list', function (): void {
    lettermint(lmMessageEvent('message.hard_bounced'))->assertOk();

    $suppression = Suppression::find('jane@example.com');

    expect($suppression)->not->toBeNull()
        ->and($suppression->reason)->toBe(Reasons::HARD_BOUNCE)
        ->and(app(Gate::class)->isSuppressed('jane@example.com'))->toBeTrue();
});

it('lists a hard bounce for an address that is not a subscriber', function (): void {
    lettermint(lmMessageEvent('message.hard_bounced', ['recipient' => 'buyer@example.com']))
        ->assertOk()
        ->assertJson(['handled' => true]);

    expect(app(Gate::class)->isSuppressed('buyer@example.com'))->toBeTrue();
});

it('lists a hard_bounce suppression.added event', function (): void {
    lettermint(lmSuppression('hard_bounce', 'gone@example.com'))->assertOk();

    expect(Suppression::find('gone@example.com')?->reason)->toBe(Reasons::HARD_BOUNCE);
});

it('lists a spam complaint on the suppression list', function (): void {
    lettermint(lmMessageEvent('message.spam_complaint'))->assertOk();

    expect(Suppression::find('jane@example.com')?->reason)->toBe(Reasons::COMPLAINT);
});

it('counts a soft bounce without listing the address', function (): void {
    lettermint(lmMessageEvent('message.soft_bounced'))->assertOk();

    expect(Suppression::find('jane@example.com'))->toBeNull()
        ->and(app(Gate::class)->isSuppressed('jane@example.com'))->toBeFalse()
        ->and(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_SOFT_BOUNCE)->count())->toBe(1);
});

it('leaves an unsubscribe off the suppression list', function (): void {
    lettermint(lmMessageEvent('message.unsubscribed'))->assertOk();

    expect(Suppression::find('jane@example.com'))->toBeNull();
});

it('still marks the subscription bounced and answers 200 when the suppression tables are missing', function (): void {
    Log::spy();
    Schema::dropIfExists('suppression_events');
    Schema::dropIfExists('suppressions');

    lettermint(lmMessageEvent('message.hard_bounced'))->assertOk();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_BOUNCED);
    Log::shouldHaveReceived('warning')->once();
});

it('keeps the subscription update and answers 200 when writing the suppression list throws', function (): void {
    Log::spy();
    Suppression::shouldReceive('suppress')->once()->andThrow(new RuntimeException('database went away'));

    lettermint(lmMessageEvent('message.hard_bounced'))->assertOk();

    $contact = LeadHub::findByEmail('jane@example.com');

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_BOUNCED)
        ->and((bool) Contact::query()->where('uuid', $contact['uuid'])->first()->do_not_contact)->toBeTrue();
    Log::shouldHaveReceived('error');
});

it('scopes a complaint to the brand of the message, not to the default brand', function (): void {
    config()->set('brand-context.multi_brand', true);
    app('brand-context')->forget();

    $brandB = Brand::create(['handle' => 'brand-b', 'name' => 'Brand B']);

    $uuid = BrandContext::runFor($brandB, function (): string {
        app(MailingListRepository::class)->save(new MailingList(handle: 'news-b', name: 'News B', doubleOptIn: false));
        $subscription = app(SubscriptionService::class)->subscribe(app(MailingListRepository::class)->find('news-b'), 'bee@example.com');

        return Message::create([
            'campaign_handle' => 'b-1',
            'subscription_id' => $subscription->id,
            'email' => 'bee@example.com',
            'status' => Message::STATUS_SENT,
        ])->uuid;
    });

    // A webhook runs with no current brand.
    app('brand-context')->forget();

    app(EspEventProcessor::class)->process(['RecordType' => 'SpamComplaint', 'Email' => 'bee@example.com', 'ID' => 9, 'Metadata' => ['marketing_message' => $uuid]], 'postmark');

    $row = Goldnead\Suppression\Models\Suppression::query()->where('email_normalized', 'bee@example.com')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->brand_id)->toBe($brandB->id)
        ->and($row->reason)->toBe(Reasons::COMPLAINT);
});

it('counts a repeated Mailgun soft bounce once and a repeated Postmark one once', function (): void {
    $processor = app(EspEventProcessor::class);

    $mailgun = ['event-data' => ['id' => 'mg-evt-1', 'event' => 'failed', 'severity' => 'temporary', 'recipient' => 'jane@example.com']];
    $postmark = ['RecordType' => 'Bounce', 'Type' => 'SoftBounce', 'ID' => 4242, 'MessageID' => 'pm-1', 'Email' => 'jane@example.com'];

    foreach ([[$mailgun, 'mailgun'], [$postmark, 'postmark']] as [$payload, $provider]) {
        $processor->process($payload, $provider);
        $processor->process($payload, $provider);
    }

    expect(SuppressionEvent::query()->where('event_type', SuppressionEvent::TYPE_SOFT_BOUNCE)->count())->toBe(2);
});

it('trims the address and accepts an integer provider event id', function (): void {
    app(EspEventProcessor::class)->process(['type' => 'bounce', 'email' => '  jane@example.com ', 'hard' => true, 'event_id' => 12345]);

    expect(Suppression::find('jane@example.com')?->reason)->toBe(Reasons::HARD_BOUNCE);
});

it('writes the suppression event only once per provider event id', function (): void {
    $event = lmMessageEvent('message.hard_bounced');
    lettermint($event)->assertOk();

    Cache::flush();
    lettermint($event)->assertOk();

    expect(SuppressionEvent::query()->where('email_normalized', 'jane@example.com')->count())->toBe(1);
});
