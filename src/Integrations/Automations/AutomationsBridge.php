<?php

namespace Goldnead\Marketing\Integrations\Automations;

use Goldnead\Marketing\Integrations\Automations\Actions\CheckReceivedAction;
use Goldnead\Marketing\Integrations\Automations\Actions\CheckTagAction;
use Goldnead\Marketing\Integrations\Automations\Actions\MessageClickedAction;
use Goldnead\Marketing\Integrations\Automations\Actions\NewsletterDayGateAction;
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

    /**
     * Which of these addresses are inside a running sequence that pauses the
     * newsletter ("one track at a time")?
     *
     * Asked of automations, which owns the answer: marketing does not know what
     * a sequence is, only that somebody who is inside one should not also get
     * the broadcast. Addresses go in and come out trimmed and lower-cased.
     *
     * `null` means "not asked": automations is absent, too old to know the
     * question, or failed. The caller sends as before in all three cases. The
     * failure case is logged and deliberately fails OPEN, the opposite of the
     * suppression gate: this check only ever holds mail back, so losing it can
     * cost one doubled mail but never mails somebody who said no.
     *
     * @param  iterable<int, string|null>  $emails
     * @return list<string>|null
     */
    public function subjectsInPausingRuns(iterable $emails): ?array
    {
        $manager = $this->manager();

        if (! is_object($manager) || ! method_exists($manager, 'subjectsInPausingRuns')) {
            return null;
        }

        try {
            return array_values(array_map(
                fn ($email) => mb_strtolower(trim((string) $email)),
                (array) $manager->subjectsInPausingRuns($emails),
            ));
        } catch (\Throwable $e) {
            Log::warning('Marketing could not ask automations who is inside a sequence ('.$e->getMessage().'); the campaign goes out as if nobody was.');

            return null;
        }
    }

    /** The automations manager, or null when it is not installed, disabled or not booted. */
    protected function manager(): mixed
    {
        if (! static::available() || ! app()->bound('automations')) {
            return null;
        }

        return Automations::getFacadeRoot();
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
            $manager->registerBuiltIn(NewsletterDayGateAction::handle());
            $manager->registerBuiltIn(MessageClickedAction::handle());
            $manager->registerBuiltIn(CheckReceivedAction::handle());
            $manager->registerBuiltIn(CheckTagAction::handle());
        }

        $manager->action(SendMarketingEmailAction::handle(), SendMarketingEmailAction::class);

        // The day gate: a drip mail waits out the days a campaign goes out.
        // Same handle a site that built this node itself used, so a stored
        // graph keeps running; a site registration of that handle made later
        // simply replaces this one.
        $manager->action(NewsletterDayGateAction::handle(), NewsletterDayGateAction::class);

        // The click check: did anybody follow a link in one delivery.
        $manager->action(MessageClickedAction::handle(), MessageClickedAction::class);

        // The two checks on the address: has it been mailed, does it carry a tag.
        $manager->action(CheckReceivedAction::handle(), CheckReceivedAction::class);
        $manager->action(CheckTagAction::handle(), CheckTagAction::class);

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
