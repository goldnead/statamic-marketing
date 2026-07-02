<?php

namespace Goldnead\Marketing\Integrations\Automations;

use Goldnead\Marketing\Events\CampaignSent;
use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\Marketing\Events\MarketingUnsubscribed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Optional integration with goldnead/statamic-automations.
 *
 * Registers marketing triggers (subscribed / unsubscribed / campaign sent)
 * and actions (subscribe / unsubscribe / send campaign) in the visual
 * workflow builder, and forwards the marketing events to the automations
 * TriggerDispatcher. No-op when the addon is absent.
 */
class AutomationsBridge
{
    /**
     * Marketing event class => trigger handle.
     *
     * @var array<class-string, string>
     */
    public const EVENT_TRIGGERS = [
        MarketingSubscribed::class => 'marketing.subscribed',
        MarketingUnsubscribed::class => 'marketing.unsubscribed',
        CampaignSent::class => 'marketing.campaign_sent',
    ];

    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('marketing.integrations.automations', true)
            && class_exists(\Goldnead\StatamicAutomations\Facades\Automations::class);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        if (! app()->bound('automations')) {
            return;
        }

        $this->booted = true;

        try {
            \Goldnead\StatamicAutomations\Facades\Automations::trigger(Triggers\SubscribedTrigger::handle(), Triggers\SubscribedTrigger::class);
            \Goldnead\StatamicAutomations\Facades\Automations::trigger(Triggers\UnsubscribedTrigger::handle(), Triggers\UnsubscribedTrigger::class);
            \Goldnead\StatamicAutomations\Facades\Automations::trigger(Triggers\CampaignSentTrigger::handle(), Triggers\CampaignSentTrigger::class);

            \Goldnead\StatamicAutomations\Facades\Automations::action(Actions\SubscribeAction::handle(), Actions\SubscribeAction::class);
            \Goldnead\StatamicAutomations\Facades\Automations::action(Actions\UnsubscribeAction::handle(), Actions\UnsubscribeAction::class);
            \Goldnead\StatamicAutomations\Facades\Automations::action(Actions\SendCampaignAction::handle(), Actions\SendCampaignAction::class);
        } catch (\Throwable $e) {
            // Registration can be license-gated (custom nodes are a Pro
            // feature); never let that break the marketing addon's boot.
            Log::warning('Marketing → Automations bridge could not register nodes: '.$e->getMessage());

            return;
        }

        foreach (static::EVENT_TRIGGERS as $eventClass => $handle) {
            $events->listen($eventClass, function ($event) use ($handle): void {
                try {
                    app(\Goldnead\StatamicAutomations\Engine\TriggerDispatcher::class)
                        ->dispatch($handle, $event->toPayload());
                } catch (\Throwable $e) {
                    Log::warning('Marketing → Automations bridge failed for ['.$handle.']: '.$e->getMessage());
                }
            });
        }
    }
}
