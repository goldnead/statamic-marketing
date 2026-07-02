<?php

namespace Goldnead\Marketing\Integrations\WebhookManager;

use Goldnead\WebhookManager\Contracts\TriggerInterface;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;

/**
 * Exposes one marketing lifecycle event as a webhook-manager trigger. Only
 * ever loaded when goldnead/statamic-webhook-manager is installed — the
 * bridge guards instantiation behind class_exists().
 */
class MarketingTrigger implements TriggerInterface
{
    public function __construct(
        private readonly string $handle,
        private readonly string $label,
    ) {
    }

    public function handle(): string
    {
        return $this->handle;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function sourceType(): string
    {
        return 'marketing';
    }

    /**
     * @param  mixed  $source  A marketing event exposing toPayload().
     */
    public function build(mixed $source, array $context = []): TriggerEvent
    {
        $payload = method_exists($source, 'toPayload') ? $source->toPayload() : (array) $source;
        $payload['marketing_event'] = $this->handle;

        return new TriggerEvent(
            triggerHandle: $this->handle,
            sourceType: $this->sourceType(),
            sourceReference: $payload['subscription_uuid'] ?? $payload['campaign'] ?? $payload['message_uuid'] ?? null,
            payload: $payload,
            site: null,
            locale: null,
            isReplay: (bool) ($context['replay'] ?? false),
            eventAt: new \DateTimeImmutable(),
        );
    }
}
