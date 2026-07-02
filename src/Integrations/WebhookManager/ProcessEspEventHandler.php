<?php

namespace Goldnead\Marketing\Integrations\WebhookManager;

use Goldnead\Marketing\Services\EspEventProcessor;
use Goldnead\WebhookManager\Contracts\InboundActionHandlerInterface;
use Goldnead\WebhookManager\Domain\InboundEndpoint\Models\InboundEndpoint;

/**
 * Webhook-manager inbound action: feed ESP feedback webhooks (bounces,
 * complaints, unsubscribes) into the marketing addon. Configure an inbound
 * endpoint per ESP, optionally map the payload to the normalized keys
 * (type/email/message_uuid/hard) or pass the raw body with a `provider`
 * of mailgun/postmark for automatic normalization.
 */
class ProcessEspEventHandler implements InboundActionHandlerInterface
{
    public function __construct(protected EspEventProcessor $processor)
    {
    }

    public function handle(): string
    {
        return 'marketing.process_esp_event';
    }

    public function label(): string
    {
        return 'Marketing: process ESP event (bounce/complaint/unsubscribe)';
    }

    public function handleAction(InboundEndpoint $endpoint, array $mappedPayload, array $rawPayload): array
    {
        $payload = $mappedPayload ?: $rawPayload;
        $provider = $payload['provider'] ?? null;

        $result = $this->processor->process($payload, $provider);

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'data' => $result,
        ];
    }
}
