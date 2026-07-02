<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();

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

it('unsubscribes via the tokenized link', function (): void {
    $this->get(route('marketing.unsubscribe', $this->subscription->token))
        ->assertOk()
        ->assertSee(__('marketing::public.unsubscribed_title'));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(Subscription::STATUS_UNSUBSCRIBED)
        ->and($subscription->unsubscribed_at)->not->toBeNull();

    // List tag is removed from the contact, but no global opt-out by default.
    $contact = LeadHub::findByEmail('jane@example.com');

    expect($contact['tags'])->not->toContain('list:newsletter');

    $model = \Goldnead\Leadhub\Models\Contact::query()->where('uuid', $contact['uuid'])->first();
    expect((bool) $model->do_not_contact)->toBeFalse();
});

it('handles RFC 8058 one-click unsubscribes without a session', function (): void {
    $this->post(route('marketing.unsubscribe.post', $this->subscription->token))
        ->assertNoContent();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);
});

it('opts the contact out globally when configured', function (): void {
    config()->set('marketing.unsubscribe.global_opt_out', true);

    $this->get(route('marketing.unsubscribe', $this->subscription->token))->assertOk();

    $contact = LeadHub::findByEmail('jane@example.com');
    $model = \Goldnead\Leadhub\Models\Contact::query()->where('uuid', $contact['uuid'])->first();

    expect((bool) $model->do_not_contact)->toBeTrue();
});

it('404s for unknown tokens', function (): void {
    $this->get(route('marketing.unsubscribe', 'garbage-token'))->assertNotFound();
});
