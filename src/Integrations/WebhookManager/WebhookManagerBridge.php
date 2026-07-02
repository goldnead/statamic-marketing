<?php

namespace Goldnead\Marketing\Integrations\WebhookManager;

use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\Marketing\Events\MarketingUnsubscribed;
use Goldnead\Marketing\Events\MessageBounced;
use Goldnead\Marketing\Events\MessageComplained;
use Goldnead\Marketing\Events\SubscriptionPending;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Optional integration with goldnead/statamic-webhook-manager.
 *
 * When that addon is installed this bridge (a) registers the marketing
 * lifecycle events as webhook-manager triggers so they can fire outbound
 * webhooks, and (b) registers the ESP-feedback inbound action so bounce and
 * complaint webhooks from Mailgun/Postmark/… can be mapped straight onto
 * subscriptions. No-op when the addon is absent — nothing here touches its
 * classes before the class_exists() guard passes.
 */
class WebhookManagerBridge
{
    /**
     * Marketing event class => [trigger handle, CP label].
     *
     * @var array<class-string, array{0: string, 1: string}>
     */
    public const TRIGGERS = [
        MarketingSubscribed::class => ['marketing.subscriber.subscribed', 'Marketing — subscriber confirmed'],
        SubscriptionPending::class => ['marketing.subscriber.pending', 'Marketing — subscriber pending (double opt-in)'],
        MarketingUnsubscribed::class => ['marketing.subscriber.unsubscribed', 'Marketing — subscriber unsubscribed'],
        CampaignSent::class => ['marketing.campaign.sent', 'Marketing — campaign sent'],
        MessageBounced::class => ['marketing.message.bounced', 'Marketing — message bounced'],
        MessageComplained::class => ['marketing.message.complained', 'Marketing — spam complaint'],
    ];

    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('marketing.integrations.webhook_manager', true)
            && class_exists(\Goldnead\WebhookManager\Facades\WebhookManager::class);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // The binding appears once the sibling's provider booted; bail without
        // marking booted so a later attempt can still succeed.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        foreach (static::TRIGGERS as $eventClass => [$handle, $label]) {
            try {
                \Goldnead\WebhookManager\Facades\WebhookManager::registerTrigger(
                    new MarketingTrigger($handle, $label)
                );
            } catch (\Throwable $e) {
                Log::warning('Marketing → Webhook Manager bridge could not register trigger ['.$handle.']: '.$e->getMessage());

                continue;
            }

            $events->listen($eventClass, function ($event) use ($handle): void {
                $this->dispatch($handle, $event);
            });
        }

        try {
            \Goldnead\WebhookManager\Facades\WebhookManager::registerInboundActionHandler(
                app(ProcessEspEventHandler::class)
            );
        } catch (\Throwable $e) {
            Log::warning('Marketing → Webhook Manager bridge could not register the ESP inbound action: '.$e->getMessage());
        }
    }

    /** Fail-safe: a webhook-manager error must never break the marketing pipeline. */
    protected function dispatch(string $handle, object $event): void
    {
        try {
            $trigger = \Goldnead\WebhookManager\Facades\WebhookManager::triggers()->get($handle);

            if (! $trigger) {
                return;
            }

            event(new \Goldnead\WebhookManager\Events\TriggerDetected(
                $trigger->build($event)
            ));
        } catch (\Throwable $e) {
            Log::warning('Marketing → Webhook Manager bridge failed for ['.$handle.']: '.$e->getMessage());
        }
    }
}
