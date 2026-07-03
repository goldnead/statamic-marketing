<?php

use Goldnead\Marketing\Integrations\Automations\AutomationsBridge;
use Goldnead\Marketing\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Marketing\ServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/*
 * Boot-order regression (mirrors LeadHub's WebhookManagerBridgeTest, which
 * locked in the fix from statamic-leadhub@9fd6d6a): sibling addon boot order
 * is not guaranteed. webhook-manager binds its 'webhook-manager' container
 * alias inside bootAddon(), so when Marketing boots first the binding does
 * not exist yet and eager registration would silently lose every trigger.
 * The provider must defer the bridge boots into an app->booted() callback
 * AND queue a guarded retry at the end of the booted queue.
 */

it('binds both bridges as singletons so the boot guards hold across resolutions', function (): void {
    expect(app(WebhookManagerBridge::class))->toBe(app(WebhookManagerBridge::class))
        ->and(app(AutomationsBridge::class))->toBe(app(AutomationsBridge::class));
});

it('defers both bridge boots into an app->booted callback with a trailing retry', function (): void {
    $app = new class extends Container
    {
        /** @var array<int, callable> */
        public array $bootedCallbacks = [];

        public function booted($callback): void
        {
            $this->bootedCallbacks[] = $callback;
        }
    };

    $webhookSpy = new class extends WebhookManagerBridge
    {
        public int $bootCalls = 0;

        public function boot(Dispatcher $events): void
        {
            $this->bootCalls++;
        }
    };

    $automationsSpy = new class extends AutomationsBridge
    {
        public int $bootCalls = 0;

        public function boot(Dispatcher $events): void
        {
            $this->bootCalls++;
        }
    };

    $app->instance(WebhookManagerBridge::class, $webhookSpy);
    $app->instance(AutomationsBridge::class, $automationsSpy);
    $app->instance('events', app('events'));

    $provider = new ServiceProvider($app);
    (new ReflectionMethod($provider, 'registerSiblingBridges'))->invoke($provider);

    // Nothing runs inline — the work is queued for after all providers booted.
    expect($webhookSpy->bootCalls)->toBe(0)
        ->and($automationsSpy->bootCalls)->toBe(0)
        ->and($app->bootedCallbacks)->toHaveCount(1);

    // Firing the booted callback boots both bridges AND queues a retry at the
    // end of the booted queue (covers the case where the first run fires
    // before sibling addons finished booting — the bridges' own guards make
    // redundant invocations no-ops).
    ($app->bootedCallbacks[0])();
    expect($webhookSpy->bootCalls)->toBe(1)
        ->and($automationsSpy->bootCalls)->toBe(1)
        ->and($app->bootedCallbacks)->toHaveCount(2);

    ($app->bootedCallbacks[1])();
    expect($webhookSpy->bootCalls)->toBe(2)
        ->and($automationsSpy->bootCalls)->toBe(2);
});

it('does not mark the webhook-manager bridge booted while the binding is absent, so a retry can succeed', function (): void {
    Log::spy();

    // Force availability so boot() reaches the binding check even though the
    // webhook-manager addon is absent in this test environment.
    $bridge = new class extends WebhookManagerBridge
    {
        public static function available(): bool
        {
            return true;
        }
    };

    $events = app('events');

    // 'webhook-manager' is not bound → boot must be a silent no-op (no
    // half-registration attempts, no warnings) and must NOT set the guard.
    $bridge->boot($events);
    Log::shouldNotHaveReceived('warning');

    // Once the binding appears (as it does after webhook-manager's provider
    // ran bootAddon()), a retry proceeds into the registration loop. Each
    // attempt fails here (facade class missing) and logs a warning — one per
    // trigger plus one for the ESP inbound action.
    app()->instance('webhook-manager', new stdClass());
    $bridge->boot($events);
    Log::shouldHaveReceived('warning')->times(count(WebhookManagerBridge::TRIGGERS) + 1);
});

it('boots the webhook-manager bridge idempotently: a second boot never re-registers', function (): void {
    Log::spy();

    app()->instance('webhook-manager', new stdClass());

    $bridge = new class extends WebhookManagerBridge
    {
        public static function available(): bool
        {
            return true;
        }
    };

    $events = app('events');

    $bridge->boot($events);
    $bridge->boot($events);

    // The warnings are a precise probe for registration attempts: a second
    // boot must add zero new ones.
    Log::shouldHaveReceived('warning')->times(count(WebhookManagerBridge::TRIGGERS) + 1);
    expect($events->hasListeners(\Goldnead\Marketing\Events\MarketingSubscribed::class))->toBeFalse();
});

it('does not mark the automations bridge booted while the binding is absent, so a retry can succeed', function (): void {
    Log::spy();

    $bridge = new class extends AutomationsBridge
    {
        public static function available(): bool
        {
            return true;
        }
    };

    $events = app('events');

    // 'automations' is not bound → silent no-op, guard NOT set.
    $bridge->boot($events);
    Log::shouldNotHaveReceived('warning');

    // Binding appears → the retry proceeds into template registration, which
    // fails here (facade class missing) and logs exactly one warning.
    app()->instance('automations', new stdClass());
    $bridge->boot($events);
    Log::shouldHaveReceived('warning')->once();
});

it('boots the automations bridge idempotently: a second boot never re-registers templates', function (): void {
    Log::spy();

    app()->instance('automations', new stdClass());

    $bridge = new class extends AutomationsBridge
    {
        public static function available(): bool
        {
            return true;
        }
    };

    $events = app('events');

    $bridge->boot($events);
    $bridge->boot($events);

    Log::shouldHaveReceived('warning')->once();
});
