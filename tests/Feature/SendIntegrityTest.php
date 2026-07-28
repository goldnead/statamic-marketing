<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * Two ways the system reported success while doing nothing, or told someone
 * two contradictory things at once.
 */
beforeEach(function (): void {
    $this->user = User::make()->email('editor@example.com')->makeSuper();
    $this->user->save();
    $this->actingAs($this->user);

    app(MailingListRepository::class)->save(
        new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: true)
    );
});

it('refuses a campaign handle that already has delivery history', function (): void {
    // A deleted campaign leaves its delivery rows behind — deliberately, they
    // are the record of what went to whom. But a message is identified by
    // campaign handle plus subscriber, so a new campaign on the same handle
    // inherits them, skips every recipient as "already sent", finishes at once
    // and reports success. Nobody receives anything.
    // A real subscription, not a hardcoded id: `marketing_messages` carries a
    // foreign key onto it, which SQLite does not enforce and MySQL does.
    $recipient = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'someone@example.com',
    );

    Message::create([
        'campaign_handle' => 'summer',
        'subscription_id' => $recipient->id,
        'email' => 'someone@example.com',
        'status' => Message::STATUS_SENT,
    ]);

    $this->post(cp_route('marketing.campaigns.store'), [
        'name' => 'Summer',
        'handle' => 'summer',
        'subject' => 'Hello again',
        'list' => 'newsletter',
    ])->assertSessionHasErrors('handle');

    expect(app(\Goldnead\Marketing\Contracts\Repositories\CampaignRepository::class)->find('summer'))
        ->toBeNull();
});

it('still accepts a handle whose campaign was deleted before it ever sent', function (): void {
    $this->post(cp_route('marketing.campaigns.store'), [
        'name' => 'Autumn',
        'handle' => 'autumn',
        'subject' => 'Hello',
        'list' => 'newsletter',
    ])->assertSessionHasNoErrors();
});

it('does not ask for confirmation when an editor adds someone by hand', function (): void {
    Mail::fake();

    $this->post(cp_route('marketing.lists.subscribers.store', ['handle' => 'newsletter']), [
        'email' => 'vouched@example.com',
    ])->assertSessionHasNoErrors();

    // The list uses double opt-in, and the addition is confirmed on the spot —
    // so asking the person to confirm what is already confirmed is the one
    // thing that must not happen.
    Mail::assertNotSent(ConfirmSubscriptionMail::class);

    expect(Subscription::query()->where('email', 'vouched@example.com')->value('status'))
        ->toBe(Subscription::STATUS_SUBSCRIBED);
});

it('still runs double opt-in for a public sign-up', function (): void {
    Mail::fake();

    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'stranger@example.com',
        [],
        ['source' => 'form'],
    );

    Mail::assertSent(ConfirmSubscriptionMail::class);

    expect(Subscription::query()->where('email', 'stranger@example.com')->value('status'))
        ->toBe(Subscription::STATUS_PENDING);
});
