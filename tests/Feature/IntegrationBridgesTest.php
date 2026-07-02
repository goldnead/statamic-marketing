<?php

use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Integrations\WebhookManager\WebhookManagerBridge;

/**
 * The sibling addons are not installed in the default suite — these tests
 * prove the no-op-when-absent contract (mirroring LeadHub's bridge tests).
 */
it('reports the webhook-manager bridge unavailable when the addon is absent', function (): void {
    expect(class_exists(\Goldnead\WebhookManager\Facades\WebhookManager::class))->toBeFalse()
        ->and(WebhookManagerBridge::available())->toBeFalse();
});

it('reports the automations bridge unavailable when the addon is absent', function (): void {
    expect(class_exists(\Goldnead\StatamicAutomations\Facades\Automations::class))->toBeFalse()
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

    \Illuminate\Support\Facades\Mail::fake();

    app(\Goldnead\Marketing\Contracts\Repositories\MailingListRepository::class)->save(
        new \Goldnead\Marketing\Data\MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false),
    );

    $subscription = app(\Goldnead\Marketing\Services\SubscriptionService::class)->subscribe(
        app(\Goldnead\Marketing\Contracts\Repositories\MailingListRepository::class)->find('newsletter'),
        'bridge@example.com',
    );

    expect($subscription->isSubscribed())->toBeTrue();
});
