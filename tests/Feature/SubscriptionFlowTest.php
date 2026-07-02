<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\Mail;

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
        ->and($subscription->token)->not->toBeEmpty();

    Mail::assertSent(ConfirmSubscriptionMail::class, function (ConfirmSubscriptionMail $mail) use ($subscription) {
        return $mail->subscription->is($subscription) && $mail->hasTo('jane@example.com');
    });

    // No LeadHub contact before confirmation.
    expect(LeadHub::findByEmail('jane@example.com'))->toBeNull();

    $this->get(route('marketing.confirm', $subscription->token))
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
    $service = app(\Goldnead\Marketing\Services\SubscriptionService::class);
    $list = app(MailingListRepository::class)->find('newsletter');

    $first = $service->subscribe($list, 'dup@example.com');
    $service->markSubscribed($first);

    $second = $service->subscribe($list, 'dup@example.com');

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe(Subscription::STATUS_SUBSCRIBED)
        ->and(Subscription::forList('newsletter')->count())->toBe(1);
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
