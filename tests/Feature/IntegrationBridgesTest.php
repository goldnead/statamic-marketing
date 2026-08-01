<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\StatamicAutomations\Facades\Automations;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Support\Facades\Mail;

/**
 * The sibling addons are not installed in the default suite — these tests
 * prove the no-op-when-absent contract (mirroring LeadHub's bridge tests).
 */
it('reports the webhook-manager bridge unavailable when the addon is absent', function (): void {
    expect(class_exists(WebhookManager::class))->toBeFalse()
        ->and(WebhookManagerBridge::available())->toBeFalse();
});

it('reports the automations bridge unavailable when the addon is absent', function (): void {
    expect(class_exists(Automations::class))->toBeFalse()
        ->and(AutomationsBridge::available())->toBeFalse();
});

it('boots both bridges as silent no-ops', function (): void {
    app(WebhookManagerBridge::class)->boot(app('events'));
    app(AutomationsBridge::class)->boot(app('events'));

    // Reaching this line means neither bridge threw despite the missing addons.
    expect(true)->toBeTrue();
});

it('keeps subscription flows working with bridges enabled in config', function (): void {
    config()->set('marketing.integrations.automations', true);
    config()->set('marketing.integrations.webhook_manager', true);

    Mail::fake();

    app(MailingListRepository::class)->save(
        new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false),
    );

    $subscription = app(SubscriptionService::class)->subscribe(
        app(MailingListRepository::class)->find('newsletter'),
        'bridge@example.com',
    );

    expect($subscription->isSubscribed())->toBeTrue();
});
