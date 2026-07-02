<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\SubscriptionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    if (! class_exists(\Goldnead\WebhookManager\Facades\WebhookManager::class)) {
        $this->markTestSkipped('goldnead/statamic-webhook-manager is not installed (run scripts/test-siblings.sh).');
    }
});

it('registers the marketing events as webhook-manager triggers', function (): void {
    foreach ([
        'marketing.subscriber.subscribed',
        'marketing.subscriber.pending',
        'marketing.subscriber.unsubscribed',
        'marketing.campaign.sent',
        'marketing.message.bounced',
        'marketing.message.complained',
    ] as $handle) {
        expect(\Goldnead\WebhookManager\Facades\WebhookManager::triggers()->get($handle))->not->toBeNull();
    }
});

it('registers the ESP inbound action handler', function (): void {
    $handler = \Goldnead\WebhookManager\Facades\WebhookManager::inboundActionHandlers()->get('marketing.process_esp_event');

    expect($handler)->not->toBeNull()
        ->and($handler)->toBeInstanceOf(\Goldnead\Marketing\Integrations\WebhookManager\ProcessEspEventHandler::class);
});

it('re-emits a subscription as the webhook-manager TriggerDetected event', function (): void {
    Mail::fake();
    Event::fake([\Goldnead\WebhookManager\Events\TriggerDetected::class]);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false));

    app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'hook@example.com',
    );

    Event::assertDispatched(
        \Goldnead\WebhookManager\Events\TriggerDetected::class,
        function ($event) {
            return $event->trigger->triggerHandle === 'marketing.subscriber.subscribed'
                && ($event->trigger->payload['email'] ?? null) === 'hook@example.com';
        },
    );
});
