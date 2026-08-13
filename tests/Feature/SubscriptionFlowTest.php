<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: true,
    ));
});

it('runs the double opt-in flow end to end', function (): void {
    $response = $this->post(route('marketing.subscribe'), [
        'list' => 'newsletter',
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
    ]);

    $response->assertRedirect();

    $subscription = Subscription::forList('newsletter')->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(Subscription::STATUS_PENDING)
        ->and($subscription->token)->not->toBeEmpty()
        ->and($subscription->confirmation_token)->not->toBeEmpty()
        ->and($subscription->confirmation_token)->not->toBe($subscription->token);

    Mail::assertSent(ConfirmSubscriptionMail::class, function (ConfirmSubscriptionMail $mail) use ($subscription) {
        return $mail->subscription->is($subscription) && $mail->hasTo('jane@example.com');
    });

    // No LeadHub contact before confirmation.
    expect(LeadHub::findByEmail('jane@example.com'))->toBeNull();

    $this->get(route('marketing.confirm', $subscription->confirmation_token))
        ->assertOk()
        ->assertSee(__('marketing::public.confirm_button'));

    $this->post(route('marketing.confirm.post', $subscription->confirmation_token))
        ->assertOk()
        ->assertSee(__('marketing::public.confirmed_title'));

    $subscription->refresh();

    expect($subscription->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and($subscription->confirmed_at)->not->toBeNull();

    // Confirmation upserts the LeadHub contact, links it, and tags the list.
    $contact = LeadHub::findByEmail('jane@example.com');

    expect($contact)->not->toBeNull()
        ->and($contact['first_name'])->toBe('Jane')
        ->and($contact['tags'])->toContain('list:newsletter')
        ->and($subscription->fresh()->contact_uuid)->toBe($contact['uuid']);
});

it('subscribes immediately when the list uses single opt-in', function (): void {
    app(MailingListRepository::class)->save(new MailingList(
        handle: 'updates',
        name: 'Updates',
        doubleOptIn: false,
    ));

    $this->post(route('marketing.subscribe'), [
        'list' => 'updates',
        'email' => 'solo@example.com',
    ])->assertRedirect();

    $subscription = Subscription::forList('updates')->first();

    expect($subscription->status)->toBe(Subscription::STATUS_SUBSCRIBED);
    expect(LeadHub::findByEmail('solo@example.com'))->not->toBeNull();

    Mail::assertNotSent(ConfirmSubscriptionMail::class);
});

it('is idempotent for already subscribed addresses', function (): void {
    $service = app(SubscriptionService::class);
    $list = app(MailingListRepository::class)->find('newsletter');

    $first = $service->subscribe($list, 'dup@example.com');
    $service->markSubscribed($first);

    $second = $service->subscribe($list, 'dup@example.com');

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and(Subscription::forList('newsletter')->count())->toBe(1);
});

/**
 * Two sign-ups for one address, arriving together.
 *
 * `subscribe()` looks the address up and then inserts it, and a public form
 * walks into the gap between those two statements routinely — an impatient
 * double-click, two tabs, a client prefetching the POST. The loser of that
 * race used to get the consent unique thrown in its face: an unhandled
 * exception, and a 500 with a stack trace on an anonymous endpoint, for having
 * done nothing wrong.
 *
 * The competing insert is staged from inside the `creating` event, which is
 * exactly the moment the real race happens — after this request decided the
 * row was absent, before its own INSERT lands.
 */
it('answers a simultaneous sign-up of the same address instead of failing', function (): void {
    $list = app(MailingListRepository::class)->find('newsletter');

    $schonPassiert = false;

    Subscription::creating(function (Subscription $subscription) use (&$schonPassiert) {
        if ($schonPassiert) {
            return;
        }

        $schonPassiert = true;

        DB::table('marketing_subscriptions')->insert([
            'uuid' => (string) Str::uuid(),
            // The brand this request is writing into — the competing request
            // is in the same one, which is what makes it a collision at all.
            // Read from the context rather than from $subscription, because
            // HasBrand stamps the model after this listener has run.
            'brand_id' => $subscription->brand_id ?? app('brand-context')->currentId(),
            'list_handle' => 'newsletter',
            'email' => 'gleichzeitig@example.com',
            'email_normalized' => 'gleichzeitig@example.com',
            'uniqueness_key' => Subscription::uniquenessKeyFor('newsletter', 'gleichzeitig@example.com'),
            'status' => Subscription::STATUS_PENDING,
            'token' => Str::random(48),
            'confirmation_token' => Str::random(48),
            'subscribed_at' => now(),
        ]);
    });

    $subscription = app(SubscriptionService::class)->subscribe($list, 'gleichzeitig@example.com');

    // The winner's row is the right answer for both requests, and there is
    // exactly one of it.
    expect($subscription->exists)->toBeTrue()
        ->and($subscription->email)->toBe('gleichzeitig@example.com')
        ->and(Subscription::query()->where('email', 'gleichzeitig@example.com')->count())->toBe(1);
});

it('silently drops honeypot submissions', function (): void {
    $this->post(route('marketing.subscribe'), [
        'list' => 'newsletter',
        'email' => 'bot@example.com',
        'website' => 'https://spam.example',
    ])->assertRedirect();

    expect(Subscription::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('returns a JSON envelope for JSON clients', function (): void {
    $this->postJson(route('marketing.subscribe'), [
        'list' => 'newsletter',
        'email' => 'json@example.com',
    ])->assertOk()->assertJson(['ok' => true, 'data' => ['status' => 'pending']]);
});

it('404s for an unknown list', function (): void {
    $this->post(route('marketing.subscribe'), [
        'list' => 'nope',
        'email' => 'x@example.com',
    ])->assertNotFound();
});
