<?php

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Events\MarketingUnsubscribed;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;

/*
 * The unsubscribe is saved before anything else happens. What follows (the
 * report event, the LeadHub sync, the listeners) is bookkeeping. A failure in
 * there used to bubble up as a 500 on the unsubscribe page, although the
 * person was already off the list: they saw an error, tried again or gave up,
 * and the one step that matters by law looked broken.
 */
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

it('confirms the unsubscribe even when a listener throws', function (): void {
    Exceptions::fake();

    Event::listen(MarketingUnsubscribed::class, function (): void {
        throw new RuntimeException('timeline store is down');
    });

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token), ['via' => 'page'])
        ->assertOk()
        ->assertSee(__('marketing::public.unsubscribed_title'));

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'timeline store is down');
});

it('still opts the contact out when the LeadHub timeline write throws', function (): void {
    Exceptions::fake();
    config()->set('marketing.unsubscribe.global_opt_out', true);

    // A proxy around the real manager: only `ingest` breaks, the lookups and
    // the opt-out keep working against the database.
    $leadhub = Mockery::mock(LeadHub::getFacadeRoot());
    $leadhub->shouldReceive('ingest')->andThrow(new RuntimeException('leadhub ingest failed'));
    LeadHub::swap($leadhub);

    $this->post(route('marketing.unsubscribe.post', $this->subscription->token))
        ->assertNoContent();

    expect($this->subscription->fresh()->status)->toBe(Subscription::STATUS_UNSUBSCRIBED);

    $contact = Contact::query()->where('email_normalized', 'jane@example.com')->first();

    expect((bool) $contact->do_not_contact)->toBeTrue();

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'leadhub ingest failed');
});
