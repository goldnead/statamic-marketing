<?php

namespace Goldnead\Marketing\Integrations\Automations;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Optional integration with goldnead/statamic-automations.
 *
 * The marketing triggers (marketing.subscribed / .unsubscribed /
 * .campaign_sent) and actions (marketing.subscribe / .unsubscribe /
 * .send_campaign) are registered by statamic-automations itself when it
 * detects this addon — exactly like its LeadHub integration — so they are
 * built-in nodes and never license-gated.
 *
 * This bridge contributes the ready-made cross-addon automation TEMPLATES
 * (welcome series, form-to-newsletter, …) to the automations template
 * catalog. No-op when the addon is absent or too old to accept templates.
 */
class AutomationsBridge
{
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

        // The binding appears once the sibling's provider booted; bail without
        // marking booted so a later attempt can still succeed.
        if (! app()->bound('automations')) {
            return;
        }

        $this->booted = true;

        try {
            $manager = \Goldnead\StatamicAutomations\Facades\Automations::getFacadeRoot();

            // Template registration shipped after the initial automations
            // release — degrade gracefully on older versions.
            if (! method_exists($manager, 'template')) {
                return;
            }

            foreach (AutomationTemplates::all() as $template) {
                $manager->template($template);
            }
        } catch (\Throwable $e) {
            Log::warning('Marketing → Automations bridge could not register templates: '.$e->getMessage());
        }
    }
}
