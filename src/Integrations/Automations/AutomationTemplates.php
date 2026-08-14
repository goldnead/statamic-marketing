<?php

namespace Goldnead\Marketing\Integrations\Automations;

/**
 * Ready-made cross-addon automation templates contributed to the
 * statamic-automations template catalog. Each entry uses the same array
 * shape as the catalog's built-ins (handle, name, description, requires[],
 * nodes[], edges[]) and only references node types that exist when the
 * respective addons are installed.
 *
 * **Which mail node a template uses is not a style question here.** Anything
 * addressed to the person a run is about goes on `marketing.send_email`, which
 * asks for consent, suppression, opt-out and the frequency cap and whose mails
 * carry the unsubscribe link and the postal line. The orchestrator's neutral
 * `send_email` appears in exactly two templates below — the unsubscribe alert
 * and the campaign-sent notice — and in both it writes to the site's own team
 * at an admin address. That is what it is for, and since 2.4.0 of
 * `statamic-automations` it refuses the other case outright.
 */
class AutomationTemplates
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::welcomeSeries(),
            self::formToNewsletter(),
            self::qualifiedLeadToNewsletter(),
            self::campaignSentNotification(),
            self::unsubscribeAlert(),
        ];
    }

    /**
     * The re-entry key a trigger node carries, and the value a welcome series
     * needs. Spelled out rather than imported from
     * `Goldnead\StatamicAutomations\Support\RestartPolicy` because this file is
     * data: it is read in tests and by anything that lists the catalog, on
     * installs where the orchestrator may not be present at all.
     *
     * `ignore` = "if this person has ever been in this automation, do nothing".
     * The default is `always`, which enrolls again in parallel — for a welcome
     * series that means somebody who unsubscribes and subscribes again is
     * welcomed twice, with both passes still ticking.
     */
    protected const REENTRY_KEY = '_restart_policy';

    protected const REENTRY_ONCE = 'ignore';

    /**
     * New subscriber → welcome mail → 3 days → follow-up mail.
     *
     * **On `marketing.send_email`, not on `send_email`.** Until 2.6.2 the two
     * mails here sat on the orchestrator's domain-neutral node: an address, a
     * subject, a body, and no question asked about consent, suppression,
     * opt-out or the frequency cap — the same node a password reset goes out
     * on, and one that carries neither an unsubscribe link nor a postal line.
     * That is the exact defect this addon's own `docs/sequences.md` warns
     * about two directories away, shipped as the one-click way to start. Two
     * real sequences were built that way before anyone read the warning, which
     * is the point: what the template does is what the next person copies.
     *
     * **Campaign mode, with the campaign left empty.** The catalog cannot name
     * a campaign that does not exist yet in the site installing it, so the
     * field is blank and the automation arrives disabled (the installer
     * creates every template that way) with one thing to fill in per mail. A
     * node without a campaign says so when Test is pressed rather than sending
     * something unchecked, which is the honest end of that trade.
     *
     * Campaign mode rather than template mode for a second reason, measured on
     * a live hub: in template mode the renderer is handed a campaign with an
     * empty `content`, and the `text/plain` part is built from exactly that
     * field — every mail would go out with a text part that is nothing but the
     * unsubscribe line, and its HTML without the postal address. See
     * `Sending\SingleSend::templateCampaign()`.
     *
     * The copy that used to live in these nodes is gone with them. A campaign
     * carries its own subject and content, written by whoever installs this;
     * shipping two English placeholder mails inside a graph only produced
     * mails nobody edited. One warning survives from them: write greetings so
     * they hold for a subscriber with no first name. `{{ first_name }}` renders
     * **empty** on a real send — the neutral word in `archiveVariables()` is a
     * courtesy for the public archive page, not for the send path — and
     * `| default:` does not save it. Measured against the running renderer on
     * 14.08.2026: `{{ leer | default:'du' }}` renders empty, quoted or not,
     * while `{{ vorhanden | default:'x' }}` returns the value, so the modifier
     * runs and simply does not treat an empty string as missing. The two
     * spellings that do hold:
     *
     *     Hallo {{ first_name or "du" }},
     *     Hallo {{ if first_name }}{{ first_name }}{{ else }}du{{ /if }},
     */
    protected static function welcomeSeries(): array
    {
        return [
            'handle' => 'marketing_welcome_series',
            'name' => 'Newsletter Welcome Series',
            'description' => 'Greet confirmed subscribers, then follow up three days later — through the marketing send path, '
                .'so consent, suppression, opt-out and the frequency cap are asked before every mail. '
                .'Write each mail as a campaign in Marketing → Campaigns and leave it in draft, then pick it on the two mail nodes. '
                .'Enrollment is set to once per person.',
            'requires' => ['marketing'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'marketing.subscribed', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'list' => null,
                    self::REENTRY_KEY => self::REENTRY_ONCE,
                ]],
                ['node_key' => 'welcome', 'type' => 'marketing.send_email', 'label' => 'Welcome mail', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    // Campaign mode. Empty until the site picks one of its own
                    // drafts; the campaign carries subject, content, layout,
                    // list and classification, so nothing else has to be set.
                    'campaign' => null,
                    // Empty `to` = the address this run is already about. Empty
                    // `list` = the campaign's own list, which is where the
                    // consent for this mail comes from.
                    'to' => null,
                    'list' => null,
                ]],
                ['node_key' => 'wait', 'type' => 'delay', 'position_x' => 500, 'position_y' => 0, 'config' => [
                    'amount' => 3,
                    'unit' => 'days',
                ]],
                ['node_key' => 'followup', 'type' => 'marketing.send_email', 'label' => 'Follow-up mail', 'position_x' => 750, 'position_y' => 0, 'config' => [
                    'campaign' => null,
                    'to' => null,
                    'list' => null,
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'welcome'],
                ['from_node_key' => 'welcome', 'to_node_key' => 'wait'],
                ['from_node_key' => 'wait', 'to_node_key' => 'followup'],
            ],
        ];
    }

    /**
     * Statamic form submission → subscribe to a list (double opt-in applies).
     */
    protected static function formToNewsletter(): array
    {
        return [
            'handle' => 'marketing_form_to_newsletter',
            'name' => 'Form Submission to Newsletter',
            'description' => 'Subscribe form submitters to a mailing list — the list\'s double opt-in still applies.',
            'requires' => ['marketing'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'form_submitted', 'position_x' => 0, 'position_y' => 0, 'config' => ['form_handle' => null]],
                ['node_key' => 'subscribe', 'type' => 'marketing.subscribe', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'list' => 'newsletter',
                    'email' => '{{ submission.data.email }}',
                    'first_name' => '{{ submission.data.first_name }}',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'subscribe'],
            ],
        ];
    }

    /**
     * LeadHub lead turns "qualified" → add them to the newsletter.
     */
    protected static function qualifiedLeadToNewsletter(): array
    {
        return [
            'handle' => 'marketing_qualified_lead_to_newsletter',
            'name' => 'Qualified Lead to Newsletter',
            'description' => 'When a LeadHub lead becomes qualified, subscribe them to your newsletter.',
            'requires' => ['marketing', 'leadhub'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'leadhub.lead_status_changed', 'position_x' => 0, 'position_y' => 0, 'config' => [
                    'new_status' => 'qualified',
                ]],
                ['node_key' => 'subscribe', 'type' => 'marketing.subscribe', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'list' => 'newsletter',
                    'email' => '{{ lead.email }}',
                    'first_name' => '{{ lead.first_name }}',
                    'last_name' => '{{ lead.last_name }}',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'subscribe'],
            ],
        ];
    }

    /**
     * Campaign finished sending → notify the team.
     */
    protected static function campaignSentNotification(): array
    {
        return [
            'handle' => 'marketing_campaign_sent_notification',
            'name' => 'Campaign Sent Notification',
            'description' => 'Email the team when a campaign has finished sending.',
            'requires' => ['marketing'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'marketing.campaign_sent', 'position_x' => 0, 'position_y' => 0, 'config' => ['campaign' => null]],
                ['node_key' => 'notify', 'type' => 'send_email', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Campaign sent: {{ campaign.name }}',
                    'body' => "The campaign \"{{ campaign.name }}\" ({{ campaign.subject }}) finished sending to list {{ campaign.list }}.\n\nCheck the report in the Control Panel under Marketing → Campaigns.",
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'notify'],
            ],
        ];
    }

    /**
     * Unsubscribe → log it and alert the team (useful while tuning content).
     */
    protected static function unsubscribeAlert(): array
    {
        return [
            'handle' => 'marketing_unsubscribe_alert',
            'name' => 'Unsubscribe Alert',
            'description' => 'Log every unsubscribe and email the team about it.',
            'requires' => ['marketing'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'marketing.unsubscribed', 'position_x' => 0, 'position_y' => 0, 'config' => ['list' => null]],
                ['node_key' => 'log', 'type' => 'add_log_entry', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'message' => 'Unsubscribed: {{ subscriber.email }} from {{ subscriber.list }}',
                ]],
                ['node_key' => 'notify', 'type' => 'send_email', 'position_x' => 500, 'position_y' => 0, 'config' => [
                    'to' => 'admin@example.com',
                    'subject' => 'Unsubscribe: {{ subscriber.email }}',
                    'body' => '{{ subscriber.email }} unsubscribed from {{ subscriber.list }}.',
                ]],
            ],
            'edges' => [
                ['from_node_key' => 'trigger', 'to_node_key' => 'log'],
                ['from_node_key' => 'log', 'to_node_key' => 'notify'],
            ],
        ];
    }
}
