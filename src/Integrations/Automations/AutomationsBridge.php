<?php

namespace Goldnead\Marketing\Integrations\Automations;

use Goldnead\Marketing\Integrations\Automations\Actions\SendMarketingEmailAction;
use Goldnead\StatamicAutomations\Facades\Automations;
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
 * This bridge contributes two things of its own:
 *
 *  - the ready-made cross-addon automation TEMPLATES (welcome series,
 *    form-to-newsletter, …) to the automations template catalog;
 *  - the `marketing.send_email` ACTION NODE, which is the one node in the
 *    family that has to live on this side of the boundary. It goes through
 *    marketing's send path — consent, suppression, opt-out, frequency cap —
 *    and every one of those is marketing's own domain. `automations` sends
 *    mail with `send_email`, deliberately knowing nothing about any of it.
 *
 * No-op when the addon is absent or too old to accept either.
 */
class AutomationsBridge
{
    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('marketing.integrations.automations', true)
            && class_exists(Automations::class);
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
            $manager = Automations::getFacadeRoot();

            $this->registerSendNode($manager);

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

    /**
     * Contribute the marketing send node, plus the two option sources its
     * config form reads.
     *
     * Registered as BUILT-IN. The node is not a customer's custom action — it
     * is this addon's own surface in the builder, and gating it behind the
     * orchestrator's Pro licence would make a marketing feature depend on an
     * automations edition. Same treatment `automations` already gives the
     * marketing triggers and actions it registers from its own side.
     *
     * Guarded on the method existing, like the templates below: an older
     * automations release without the public registration API must degrade to
     * "no node" rather than to a fatal.
     */
    protected function registerSendNode(mixed $manager): void
    {
        if (! is_object($manager) || ! method_exists($manager, 'action')) {
            return;
        }

        if (method_exists($manager, 'registerBuiltIn')) {
            $manager->registerBuiltIn(SendMarketingEmailAction::handle());
        }

        $manager->action(SendMarketingEmailAction::handle(), SendMarketingEmailAction::class);

        if (! method_exists($manager, 'registerOptionSource')) {
            return;
        }

        $manager->registerOptionSource(
            'marketing.campaigns',
            fn () => SendMarketingEmailAction::campaignOptions(),
        );

        $manager->registerOptionSource(
            'marketing.lists',
            fn () => SendMarketingEmailAction::listOptions(),
        );
    }
}
