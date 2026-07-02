<?php

namespace Goldnead\Marketing\Integrations\Automations\Triggers;

use Goldnead\StatamicAutomations\Context\AutomationContext;
use Goldnead\StatamicAutomations\Contracts\AutomationTrigger;

class SubscribedTrigger implements AutomationTrigger
{
    public static function handle(): string
    {
        return 'marketing.subscribed';
    }

    public static function label(): string
    {
        return 'Subscriber Confirmed';
    }

    public static function description(): ?string
    {
        return 'Triggered when someone (double-)opts in to a mailing list.';
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
                'handle' => 'list',
                'label' => 'List filter',
                'type' => 'text',
                'required' => false,
                'help' => 'Optional — only run for this mailing list handle.',
            ],
        ];
    }

    public static function outputSchema(): array
    {
        return [
            'subscriber' => [
                'subscription_uuid' => 'string',
                'list' => 'string',
                'email' => 'string',
                'first_name' => 'string',
                'last_name' => 'string',
                'status' => 'string',
                'contact_uuid' => 'string',
                'source' => 'string',
            ],
        ];
    }

    public function matches(object|array $event, array $config): bool
    {
        $list = $config['list'] ?? null;

        if (! $list) {
            return true;
        }

        return (static::payload($event)['list'] ?? null) === $list;
    }

    public function buildContext(object|array $event, array $config): AutomationContext
    {
        return AutomationContext::make([
            'subscriber' => static::payload($event),
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
