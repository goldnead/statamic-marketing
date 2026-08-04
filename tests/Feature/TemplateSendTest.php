<?php

use Goldnead\Leadhub\Contracts\Repositories\ContactRepository;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\MailLogEntry;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Sending\SingleSendResult;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Suppression\Contracts\Gate as SuppressionGate;
use Goldnead\Suppression\Exceptions\SuppressionCheckFailed;
use Illuminate\Support\Facades\Mail;

/**
 * The one-recipient send path when the mail is a template rather than a
 * campaign.
 *
 * This is the mode that exists because the node was unusable without it. A site
 * that writes its welcome mails as `et_templates` — an address, a subject, a
 * template slug — had no way through `marketing.send_email` at all and sent
 * them through the domain-neutral `send_email` in `automations` instead, which
 * asks nobody anything. Seven live marketing mails were going out with no
 * consent check, no suppression check, no opt-out check and no cap.
 *
 * So the assertion that matters here is not "it sends". It is that the four
 * gates are the *same* four gates, asked in the *same* order, on a path where
 * there is no campaign to hang any of them off.
 */
beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->jane = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'welcome-sequenz-1-willkommen',
        name: 'Willkommen',
        html: '<html><body><p>Schön, dass du dabei bist, {{ first_name }}.</p>'
            .'<a href="{{ unsubscribe_url }}">Abmelden</a></body></html>',
    ));

    $this->sendTemplate = fn (
        ?MailClass $class = null,
        ?Subscription $subscription = null,
        string $template = 'welcome-sequenz-1-willkommen',
    ): SingleSendResult => app(SingleSend::class)->sendTemplate(
        $template,
        '{{ first_name }}, schön, dass du dabei bist',
        $this->list,
        $subscription ?? $this->jane->fresh(),
        $class,
    );

    $this->enableCap = function (int $max = 3): void {
        config()->set('marketing.frequency_cap.enabled', true);
        config()->set('marketing.frequency_cap.max', $max);
        config()->set('marketing.frequency_cap.window_hours', 168);
    };

    $this->fillTheWindow = function (int $count): void {
        foreach (range(1, $count) as $ignored) {
            MailLogEntry::query()->create([
                'brand_id' => app('brand-context')->currentId(),
                'email_normalized' => 'jane@example.com',
                'mail_class' => MailClass::Marketing->value,
                'reference' => 'earlier',
                'sent_at' => now(),
            ]);
        }
    };
});

it('sends the template to a subscriber who has consented', function (): void {
    $result = ($this->sendTemplate)();

    Mail::assertSent(CampaignMail::class, 1);

    expect($result->wasSent())->toBeTrue()
        ->and($result->message->status)->toBe(Message::STATUS_SENT);

    // The template body is the mail — it is not wrapped in, or replaced by, the
    // built-in fallback layout — and marketing's own merge tokens still resolve
    // inside it, including the ones a template send has no campaign to get
    // from.
    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
        return str_contains($mail->rendered->html, 'Schön, dass du dabei bist, Jane.')
            && ! str_contains($mail->rendered->html, '{{ unsubscribe_url }}')
            && $mail->rendered->subject === 'Jane, schön, dass du dabei bist';
    });
});

it('writes a message row that points at the template and at no campaign', function (): void {
    $result = ($this->sendTemplate)();

    $message = Message::query()->firstWhere('uuid', $result->message->uuid);

    // The decision this test exists to hold. There is no campaign behind this
    // mail, so `campaign_handle` says so literally rather than naming one that
    // cannot be looked up. `template_handle` keeps the row self-describing:
    // "which mail was this" is the first question a bounce, a complaint or a
    // support request asks, and it has to be answerable from the row alone.
    expect($message->campaign_handle)->toBeNull()
        ->and($message->template_handle)->toBe('welcome-sequenz-1-willkommen')
        ->and($message->subscription_id)->toBe($this->jane->id)
        ->and($message->email)->toBe('jane@example.com')
        ->and((int) $message->brand_id)->toBe((int) $this->jane->brand_id);

    // And the consequence: every campaign report is a `where campaign_handle =
    // ?`, which never matches NULL. A template send is not part of any
    // campaign's numbers, and none of those queries had to learn about it.
    expect(Message::query()->forCampaign('welcome-sequenz-1-willkommen')->count())->toBe(0)
        ->and(Message::query()->forTemplate('welcome-sequenz-1-willkommen')->count())->toBe(1);
});

/*
 * The four gates, in order — the same four, on a path with no campaign.
 */

it('does not send a template to somebody who is not subscribed to the list', function (): void {
    app(SubscriptionService::class)->unsubscribe($this->jane, ['reason' => 'test']);

    $result = ($this->sendTemplate)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('not_subscribed')
        ->and(Message::query()->count())->toBe(0);
});

it('does not send a template to a suppressed address', function (): void {
    $this->mock(SuppressionGate::class)
        ->shouldReceive('isSuppressed')->andReturn(true);

    $result = ($this->sendTemplate)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('suppressed');
});

it('does not send a template when the suppression list cannot be read', function (): void {
    // Fail closed here too. Not knowing is not permission, whichever mode the
    // mail was written in.
    $this->mock(SuppressionGate::class)
        ->shouldReceive('isSuppressed')->andThrow(new SuppressionCheckFailed('store unreachable'));

    $result = ($this->sendTemplate)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('suppression_unavailable');
});

it('does not send a template to a contact who has opted out of contact', function (): void {
    $contact = app(ContactRepository::class)->findByEmailNormalized('jane@example.com');

    $contact->do_not_contact = true;
    $contact->save();

    $result = ($this->sendTemplate)();

    Mail::assertNothingSent();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('opted_out');
});

it('defers a template send for a recipient who is at the frequency cap', function (): void {
    ($this->enableCap)(max: 3);
    ($this->fillTheWindow)(3);

    $result = ($this->sendTemplate)();

    Mail::assertNothingSent();

    expect($result->isDeferred())->toBeTrue()
        ->and($result->reason)->toBe('frequency_cap')
        ->and($result->retryAfterMinutes)->toBe(1440)
        ->and(Message::query()->count())->toBe(0);
});

it('asks the cap after suppression on the template path too', function (): void {
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    $this->mock(SuppressionGate::class)
        ->shouldReceive('isSuppressed')->andReturn(true);

    $result = ($this->sendTemplate)();

    expect($result->isBlocked())->toBeTrue()
        ->and($result->reason)->toBe('suppressed')
        ->and($result->isDeferred())->toBeFalse();
});

/*
 * Classification. A template has no `mail_class` field of its own the way a
 * campaign does, so the node states it — and the cap exceptions have to survive
 * the move.
 */

it('caps a template send that is classified marketing', function (): void {
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    $result = ($this->sendTemplate)(MailClass::Marketing);

    Mail::assertNothingSent();

    expect($result->isDeferred())->toBeTrue()
        ->and($result->reason)->toBe('frequency_cap');
});

it('lets a transactional template through the cap', function (): void {
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    $result = ($this->sendTemplate)(MailClass::Transactional);

    Mail::assertSent(CampaignMail::class, 1);

    expect($result->wasSent())->toBeTrue();
});

it('treats an unstated classification as marketing, never as exempt', function (): void {
    // The conservative direction, and the same one a campaign's own field
    // takes: forgetting to classify a mail costs a delay, never an exemption
    // nobody asked for.
    ($this->enableCap)(max: 1);
    ($this->fillTheWindow)(5);

    expect(($this->sendTemplate)(null)->isDeferred())->toBeTrue();
});

it('records the template send against the cap under the template slug', function (): void {
    ($this->enableCap)(max: 3);

    ($this->sendTemplate)();

    expect(MailLogEntry::query()->firstWhere('reference', 'welcome-sequenz-1-willkommen'))->not->toBeNull();
});

/*
 * A template that answers to nothing.
 */

it('refuses to send a template that cannot be resolved, rather than an empty mail', function (): void {
    $result = ($this->sendTemplate)(null, null, 'does-not-exist-anywhere');

    Mail::assertNothingSent();

    // Failed, not blocked: nobody said no. And no row — the mail was never
    // attempted, so there is nothing for a report to show as a failure.
    expect($result->isFailed())->toBeTrue()
        ->and($result->reason)->toBe('template_unresolved')
        ->and($result->error)->toContain('does-not-exist-anywhere')
        ->and(Message::query()->count())->toBe(0);

    // Not the built-in fallback layout, which is what the campaign path does
    // with an unknown template handle. That is right for a campaign — the
    // content is the mail and the layout is the frame — and wrong here, where
    // the template IS the mail and the fallback would deliver an empty one
    // under a subject the reader recognises.
    expect(app(CampaignRenderer::class)->findTemplateHtml('does-not-exist-anywhere'))->toBeNull();
});

it('explains a missing email-templates package instead of dying on its facade', function (): void {
    // `class_exists` on the facade is the only thing standing between this mode
    // and a fatal on an install without the optional package, so the message
    // behind that branch is asserted directly. It cannot be reached through
    // sendTemplate() in this suite: tests/Fixtures/EmailTemplatesStub.php
    // declares the facade, and Pest loads every test file before the first test
    // runs — so from the suite's point of view the package is always installed.
    $absent = SingleSend::emailTemplatesMissingMessage('welcome-sequenz-1-willkommen');

    expect($absent)->toContain('goldnead/statamic-email-templates')
        ->and($absent)->toContain('not installed')
        ->and($absent)->toContain('welcome-sequenz-1-willkommen');

    // And the other branch does not send anybody looking for a missing package
    // when the package is there and the slug is simply wrong.
    expect(SingleSend::missingTemplateMessage('welcome-sequenz-1-willkommen'))
        ->not->toContain('statamic-email-templates');

    expect(SingleSend::unresolvedTemplateMessage('welcome-sequenz-1-willkommen'))
        ->toContain('welcome-sequenz-1-willkommen');
});

/*
 * The campaign path is untouched by any of this.
 */

it('leaves campaign_handle set and template_handle empty on a campaign send', function (): void {
    $campaign = new Campaign(
        handle: 'welcome-1',
        name: 'Welcome 1',
        subject: 'Hello',
        listHandle: 'newsletter',
        content: '<p>Hi.</p>',
    );

    $result = app(SingleSend::class)->send($campaign, $this->list, $this->jane->fresh());

    expect($result->wasSent())->toBeTrue()
        ->and($result->message->campaign_handle)->toBe('welcome-1')
        ->and($result->message->template_handle)->toBeNull();
});
