<?php

use Carbon\CarbonImmutable;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Services\CampaignSender;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $service = app(SubscriptionService::class);

    $this->jane = $service->subscribe($this->list, 'jane@example.com', ['first_name' => 'Jane']);
    $this->john = $service->subscribe($this->list, 'john@example.com', ['first_name' => 'John']);

    $unsubscribed = $service->subscribe($this->list, 'gone@example.com');
    $service->unsubscribe($unsubscribed);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'welcome',
        name: 'Welcome',
        subject: 'Hello {{ first_name }}!',
        listHandle: 'newsletter',
        content: '<p>Hi {{ first_name }}, read our <a href="https://example.com/news">news</a>.</p>',
    ));

    $this->campaign = app(CampaignRepository::class)->find('welcome');
});

it('sends a campaign to all subscribed members and finalizes it', function (): void {
    app(CampaignSender::class)->queue($this->campaign);

    // Sync queue: jobs already ran.
    Mail::assertSent(CampaignMail::class, 2);

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
        return $mail->hasTo('jane@example.com')
            && $mail->rendered->subject === 'Hello Jane!'
            && str_contains($mail->rendered->html, '/unsubscribe/'.$this->jane->token)
            && str_contains($mail->rendered->html, '/o/') // open pixel
            && str_contains($mail->rendered->html, '/c/'); // rewritten click link
    });

    // The unsubscribed member never got a message row.
    expect(Message::forCampaign('welcome')->count())->toBe(2)
        ->and(Message::forCampaign('welcome')->where('status', Message::STATUS_SENT)->count())->toBe(2);

    $campaign = app(CampaignRepository::class)->find('welcome');

    expect($campaign->status)->toBe(Campaign::STATUS_SENT)
        ->and($campaign->sentAt)->not->toBeNull();
});

it('skips subscribers who unsubscribed between snapshot and delivery', function (): void {
    // Simulate by unsubscribing after messages exist: create pending message manually.
    $message = Message::create([
        'campaign_handle' => 'welcome',
        'subscription_id' => $this->jane->id,
        'email' => $this->jane->email,
        'status' => Message::STATUS_PENDING,
    ]);

    app(SubscriptionService::class)->unsubscribe($this->jane);

    // Campaign must be in sending state for finalization to apply.
    $this->campaign->status = Campaign::STATUS_SENDING;
    app(CampaignRepository::class)->save($this->campaign);

    (new \Goldnead\Marketing\Jobs\SendMessageJob($message->id))->handle(
        app(CampaignRepository::class),
        app(MailingListRepository::class),
        app(\Goldnead\Marketing\Services\CampaignRenderer::class),
    );

    expect($message->fresh()->status)->toBe(Message::STATUS_SKIPPED);

    Mail::assertNotSent(CampaignMail::class);
});

it('refuses to queue a campaign without a subject or list', function (): void {
    app(CampaignRepository::class)->save(new Campaign(handle: 'broken', name: 'Broken'));

    app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('broken'));
})->throws(InvalidArgumentException::class);

it('sends scheduled campaigns via the command when due', function (): void {
    app(CampaignSender::class)->schedule($this->campaign, CarbonImmutable::now()->addHour());

    // Not due yet.
    $this->artisan('marketing:send-scheduled')->assertSuccessful();
    expect(app(CampaignRepository::class)->find('welcome')->status)->toBe(Campaign::STATUS_SCHEDULED);

    // Travel past the scheduled time.
    $this->travel(2)->hours();

    $this->artisan('marketing:send-scheduled')->assertSuccessful();

    expect(app(CampaignRepository::class)->find('welcome')->status)->toBe(Campaign::STATUS_SENT);
    Mail::assertSent(CampaignMail::class, 2);
});

it('sends a test email without creating message records', function (): void {
    app(CampaignSender::class)->sendTest($this->campaign, 'me@example.com');

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
        return $mail->hasTo('me@example.com')
            && str_starts_with($mail->rendered->subject, '[Test] ');
    });

    expect(Message::query()->count())->toBe(0);

    $campaign = app(CampaignRepository::class)->find('welcome');
    expect($campaign->status)->toBe(Campaign::STATUS_DRAFT);
});

it('wraps content in the referenced template layout', function (): void {
    app(\Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository::class)->save(
        new \Goldnead\Marketing\Data\EmailTemplate(
            handle: 'branded',
            name: 'Branded',
            html: '<html><body><header>BRAND</header>{{ content }}<a href="{{ unsubscribe_url }}">bye</a></body></html>',
        ),
    );

    $this->campaign->templateHandle = 'branded';
    app(CampaignRepository::class)->save($this->campaign);

    app(CampaignSender::class)->queue(app(CampaignRepository::class)->find('welcome'));

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
        return str_contains($mail->rendered->html, 'BRAND')
            && (str_contains($mail->rendered->html, 'Hi Jane') || str_contains($mail->rendered->html, 'Hi John'));
    });
});
