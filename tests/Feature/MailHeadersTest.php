<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Sending\SingleSend;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\DeliveryHeaders;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/**
 * Zusätzliche Kopfzeilen an allen Marketing-Mails, aus der Umgebung befüllbar.
 *
 * Kampagne, Testversand und Sequenz laufen alle durch `CampaignMail`; eine
 * fremde Mailable (Kaufbestätigung, Login) kennt die Einstellung nicht.
 */
beforeEach(function (): void {
    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subscription = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');

    $this->captured = [];

    Event::listen(MessageSent::class, function ($event): void {
        $this->captured[] = $event->message;
    });
});

it('parses a name: value;name2: value2 string into headers', function (): void {
    config()->set('marketing.delivery.mail_headers', 'X-Route: broadcast; X-Other: a:b ;;bad;: x');

    expect(DeliveryHeaders::configured())->toBe(['X-Route' => 'broadcast', 'X-Other' => 'a:b']);
});

it('puts the configured header on a campaign mail', function (): void {
    config()->set('marketing.delivery.mail_headers', 'X-Route: broadcast');

    $campaign = new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hello', content: 'Hi');
    $rendered = app(CampaignRenderer::class)->render($campaign, $this->list, $this->subscription);

    Mail::to('jane@example.com')->send(new CampaignMail($campaign, $rendered));

    expect($this->captured)->toHaveCount(1)
        ->and($this->captured[0]->getHeaders()->get('X-Route')?->getBodyAsString())->toBe('broadcast');
});

it('puts the configured header on a sequence mail', function (): void {
    config()->set('marketing.delivery.mail_headers', ['X-Route' => 'broadcast']);

    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'welcome-1',
        name: 'Willkommen',
        html: '<html><body><p>Hallo</p><a href="{{ unsubscribe_url }}">Abmelden</a></body></html>',
    ));

    app(SingleSend::class)->sendTemplate('welcome-1', 'Hallo', $this->list, $this->subscription->fresh());

    expect($this->captured)->toHaveCount(1)
        ->and($this->captured[0]->getHeaders()->get('X-Route')?->getBodyAsString())->toBe('broadcast');
});

it('sets no extra header when the setting is empty', function (): void {
    config()->set('marketing.delivery.mail_headers', []);

    $campaign = new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hello', content: 'Hi');
    $rendered = app(CampaignRenderer::class)->render($campaign, $this->list, $this->subscription);

    Mail::to('jane@example.com')->send(new CampaignMail($campaign, $rendered));

    expect(DeliveryHeaders::configured())->toBe([])
        ->and($this->captured[0]->getHeaders()->has('X-Route'))->toBeFalse();
});

it('leaves a foreign mailable alone', function (): void {
    config()->set('marketing.delivery.mail_headers', 'X-Route: broadcast');

    Mail::to('jane@example.com')->send(new class extends Mailable
    {
        public function build(): self
        {
            return $this->subject('Kauf')->html('<p>Danke</p>');
        }
    });

    expect($this->captured)->toHaveCount(1)
        ->and($this->captured[0]->getHeaders()->has('X-Route'))->toBeFalse();
});
