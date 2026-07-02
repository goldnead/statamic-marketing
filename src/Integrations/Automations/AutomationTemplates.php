<?php

namespace Goldnead\Marketing\Integrations\Automations;

/**
 * Ready-made cross-addon automation templates contributed to the
 * statamic-automations template catalog. Each entry uses the same array
 * shape as the catalog's built-ins (handle, name, description, requires[],
 * nodes[], edges[]) and only references node types that exist when the
 * respective addons are installed.
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
     * New subscriber → welcome mail → 3 days → follow-up mail.
     */
    protected static function welcomeSeries(): array
    {
        return [
            'handle' => 'marketing_welcome_series',
            'name' => 'Newsletter Welcome Series',
            'description' => 'Greet confirmed subscribers immediately, then follow up three days later.',
            'requires' => ['marketing'],
            'nodes' => [
                ['node_key' => 'trigger', 'type' => 'marketing.subscribed', 'position_x' => 0, 'position_y' => 0, 'config' => ['list' => null]],
                ['node_key' => 'welcome', 'type' => 'send_email', 'position_x' => 250, 'position_y' => 0, 'config' => [
                    'to' => '{{ subscriber.email }}',
                    'subject' => 'Welcome aboard!',
                    'body' => "Hi {{ subscriber.first_name }},\n\nthanks for subscribing to {{ subscriber.list }} — great to have you!\n\nTalk soon.",
                ]],
                ['node_key' => 'wait', 'type' => 'delay', 'position_x' => 500, 'position_y' => 0, 'config' => [
                    'amount' => 3,
                    'unit' => 'days',
                ]],
                ['node_key' => 'followup', 'type' => 'send_email', 'position_x' => 750, 'position_y' => 0, 'config' => [
                    'to' => '{{ subscriber.email }}',
                    'subject' => 'Getting the most out of it',
                    'body' => "Hi {{ subscriber.first_name }},\n\nhere are a few favourites to get you started …",
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
