<?php

namespace Goldnead\Marketing\Integrations\Automations\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class CampaignSentTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'marketing.campaign_sent';
    }

    public static function label(): string
    {
        return 'Campaign Sent';
    }

    public static function description(): ?string
    {
        return 'Triggered when a campaign has finished sending to all recipients.';
    }

    public static function group(): string
    {
        return 'Marketing';
    }

    public static function supportsTestMode(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return [
            [
                'handle' => 'campaign',
                'label' => 'Campaign filter',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional — only run for this campaign handle.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'campaign' => [
                'campaign' => 'string',
                'name' => 'string',
                'subject' => 'string',
                'list' => 'string',
                'status' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $campaign = $config['campaign'] ?? null;

        if (! $campaign) {
            return true;
        }

        return (static::payload($event)['campaign'] ?? null) === $campaign;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'campaign' => static::payload($event),
        ]);
    }

    /** @return array<string, mixed> */
    protected static function payload(object|array $event): array
    {
        if (is_array($event)) {
            return $event;
        }

        return method_exists($event, 'toPayload') ? $event->toPayload() : (array) $event;
    }
}
