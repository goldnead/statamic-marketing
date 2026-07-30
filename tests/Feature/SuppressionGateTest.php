<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Mail\ConfirmSubscriptionMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Goldnead\Suppression\Reasons;
use Goldnead\Suppression\SuppressionService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * The four places this addon can put a mail on the wire, measured against an
 * address that must never receive one.
 *
 * The campaign cases go through `CampaignSender::queue()` on the sync queue —
 * the real path, jobs and all — rather than calling a job by hand, because a
 * gate that only holds when it is invoked directly is not a gate.
 */
beforeEach(function (): void {
    Mail::fake();

    $this->suppressions = app(SuppressionService::class);

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'gated',
        name: 'Gated',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('gated');

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'gated-campaign',
        name: 'Gated Campaign',
        subject: 'Hello',
        listHandle: 'gated',
        content: '<p>Hello there.</p>',
    ));

    $this->campaign = app(CampaignRepository::class)->find('gated-campaign');
});

/**
 * T1 — a complaint-suppressed address is never mailed again.
 *
 * Prevents: mailing a complainant, the one regulatory failure in this system.
 * Asserted on the message rows as well as on the mailer, because "no mail went
 * out" and "the address never entered the send" are different guarantees, and
 * only the second one survives a queue that is retried.
 */
it('never lets a complaint-suppressed address into a campaign', function (): void {
    $service = app(SubscriptionService::class);
    $service->subscribe($this->list, 'objector@example.test');
    $service->subscribe($this->list, 'fine@example.test');

    $this->suppressions->suppress('objector@example.test', Reasons::COMPLAINT);

    app(CampaignSender::class)->queue($this->campaign);

    expect(Message::query()->where('email', 'objector@example.test')->count())->toBe(0)
        ->and(Message::query()->where('email', 'fine@example.test')->count())->toBe(1);

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->hasTo('objector@example.test'));
});

it('keeps a hard-bounced address out of the same campaign', function (): void {
    app(SubscriptionService::class)->subscribe($this->list, 'dead@example.test');

    $this->suppressions->suppress('dead@example.test', Reasons::HARD_BOUNCE);

    app(CampaignSender::class)->queue($this->campaign);

    expect(Message::query()->where('email', 'dead@example.test')->count())->toBe(0);
    Mail::assertNothingSent();
});

/**
 * T2 — suppression survives resubscription.
 *
 * Prevents: the trivial bypass. A blocked address types itself into the public
 * sign-up form and comes back as a fresh, subscribed row — the block has to
 * outlive that, or it is a suggestion.
 */
it('survives the address signing itself up again', function (): void {
    $this->suppressions->suppress('objector@example.test', Reasons::COMPLAINT);

    app(SubscriptionService::class)->subscribe($this->list, 'objector@example.test');

    // The consent record is written — somebody said something, and recording it
    // is right. Sending to them is not.
    expect(Subscription::query()->where('email_normalized', 'objector@example.test')->exists())->toBeTrue();

    app(CampaignSender::class)->queue($this->campaign);

    expect(Message::query()->where('email', 'objector@example.test')->count())->toBe(0);
    Mail::assertNothingSent();
});

/**
 * T4 — the gate fails closed at the audience.
 *
 * Prevents: a database hiccup turning into "nobody is suppressed" and therefore
 * into a send to everyone. The campaign goes back to draft rather than out.
 */
it('aborts the campaign rather than sending when the gate cannot answer', function (): void {
    app(SubscriptionService::class)->subscribe($this->list, 'anyone@example.test');

    Schema::drop('suppressions');

    expect(fn () => app(CampaignSender::class)->queue($this->campaign))
        ->toThrow(SuppressionCheckFailed::class);

    Mail::assertNothingSent();

    expect(Message::query()->count())->toBe(0)
        ->and(app(CampaignRepository::class)->find('gated-campaign')->status)->toBe(Campaign::STATUS_DRAFT);
});

/**
 * The time-of-check/time-of-use gap. A campaign snapshotting 20,000 recipients
 * runs for hours after its audience was built, and every complaint arriving in
 * between would be answered by a queue that stopped listening.
 */
it('re-checks immediately before transport and skips the message', function (): void {
    $subscription = app(SubscriptionService::class)->subscribe($this->list, 'late@example.test');

    $message = Message::query()->create([
        'campaign_handle' => 'gated-campaign',
        'subscription_id' => $subscription->id,
        'email' => $subscription->email,
        'status' => Message::STATUS_PENDING,
    ]);

    // Suppressed after the audience was built, before this message is sent.
    $this->suppressions->suppress('late@example.test', Reasons::COMPLAINT);

    (new \Goldnead\Marketing\Jobs\SendMessageJob($message->id))->handle(
        app(CampaignRepository::class),
        app(MailingListRepository::class),
        app(\Goldnead\Marketing\Services\CampaignRenderer::class),
        app(\Goldnead\Suppression\Contracts\Gate::class),
    );

    Mail::assertNothingSent();

    expect($message->fresh()->status)->toBe(Message::STATUS_SKIPPED)
        ->and($message->fresh()->error)->toContain('Suppressed');
});

it('fails the single message rather than sending when the gate cannot answer', function (): void {
    $subscription = app(SubscriptionService::class)->subscribe($this->list, 'late@example.test');

    $message = Message::query()->create([
        'campaign_handle' => 'gated-campaign',
        'subscription_id' => $subscription->id,
        'email' => $subscription->email,
        'status' => Message::STATUS_PENDING,
    ]);

    Schema::drop('suppressions');

    (new \Goldnead\Marketing\Jobs\SendMessageJob($message->id))->handle(
        app(CampaignRepository::class),
        app(MailingListRepository::class),
        app(\Goldnead\Marketing\Services\CampaignRenderer::class),
        app(\Goldnead\Suppression\Contracts\Gate::class),
    );

    Mail::assertNothingSent();

    expect($message->fresh()->status)->toBe(Message::STATUS_FAILED);
});

/**
 * The double opt-in confirmation. A public form anybody can type any address
 * into is the easiest way to make this system mail a blocked mailbox.
 */
it('withholds the confirmation mail from a suppressed address', function (): void {
    app(MailingListRepository::class)->save(new MailingList(
        handle: 'gated-doi',
        name: 'Gated DOI',
        doubleOptIn: true,
    ));

    $doi = app(MailingListRepository::class)->find('gated-doi');

    $this->suppressions->suppress('objector@example.test', Reasons::COMPLAINT);

    app(SubscriptionService::class)->subscribe($doi, 'objector@example.test');

    Mail::assertNothingSent();

    // The pending row is still there, so releasing the suppression later picks
    // the ordinary flow back up rather than losing the sign-up.
    expect(Subscription::query()->where('email_normalized', 'objector@example.test')->first()->status)
        ->toBe(Subscription::STATUS_PENDING);
});

it('still sends the confirmation mail to an address nobody blocked', function (): void {
    app(MailingListRepository::class)->save(new MailingList(
        handle: 'gated-doi',
        name: 'Gated DOI',
        doubleOptIn: true,
    ));

    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('gated-doi'),
        'fine@example.test'
    );

    Mail::assertSent(ConfirmSubscriptionMail::class);
});

/**
 * The test send is the one gate that speaks. An editor standing at the screen
 * waiting for a mail that never arrives learns that the button is broken; a
 * refusal that names the reason teaches them the actual state of the address.
 */
it('refuses a test send to a suppressed address, out loud', function (): void {
    $this->suppressions->suppress('objector@example.test', Reasons::COMPLAINT);

    expect(fn () => app(CampaignSender::class)->sendTest($this->campaign, 'objector@example.test'))
        ->toThrow(InvalidArgumentException::class, 'suppression list');

    Mail::assertNothingSent();
});

it('still sends a test to an address nobody blocked', function (): void {
    app(CampaignSender::class)->sendTest($this->campaign, 'fine@example.test');

    Mail::assertSent(CampaignMail::class);
});

it('refuses a test send when the gate cannot answer', function (): void {
    Schema::drop('suppressions');

    expect(fn () => app(CampaignSender::class)->sendTest($this->campaign, 'fine@example.test'))
        ->toThrow(InvalidArgumentException::class);

    Mail::assertNothingSent();
});
