<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Events\MessageBounced;
use Goldnead\Marketing\Events\MessageComplained;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Normalizes ESP feedback payloads (bounces, complaints, unsubscribes) and
 * applies them to subscriptions, messages, and the LeadHub contact. Fed by
 * the webhook-manager inbound action or any host-app webhook controller.
 */
class EspEventProcessor
{
    public function __construct(protected SubscriptionService $subscriptions) {}

    /**
     * @param  array  $payload  Either an already-normalized payload
     *                          (type/email/message_uuid/hard) or a raw
     *                          Mailgun/Postmark webhook body.
     * @return array{ok:bool, handled:bool, type:?string, email:?string, message:string}
     */
    public function process(array $payload, ?string $provider = null): array
    {
        $event = $this->normalize($payload, $provider);

        if (! $event['type'] || ! ($event['email'] || $event['message_uuid'])) {
            return ['ok' => true, 'handled' => false, 'type' => $event['type'], 'email' => $event['email'], 'message' => 'Event ignored: no type or recipient.'];
        }

        $message = $event['message_uuid']
            ? Message::query()->where('uuid', $event['message_uuid'])->first()
            : null;

        $subscriptions = $this->resolveSubscriptions($message, $event['email']);

        match ($event['type']) {
            'bounce' => $this->applyBounce($subscriptions, $message, $event),
            'complaint' => $this->applyComplaint($subscriptions, $message, $event),
            'unsubscribe' => $subscriptions->each(
                fn (Subscription $subscription) => $this->subscriptions->unsubscribe($subscription, ['reason' => 'esp_unsubscribe'])
            ),
            default => null,
        };

        return [
            'ok' => true,
            'handled' => in_array($event['type'], ['bounce', 'complaint', 'unsubscribe'], true),
            'type' => $event['type'],
            'email' => $event['email'],
            'message' => 'Processed '.$event['type'].' event.',
        ];
    }

    /**
     * `hard` decides whether a bounce suppresses the address permanently, so
     * unknown severity is always SOFT — the safe default. Only a bounce the
     * provider explicitly reports as hard may kill a subscriber; a transient
     * failure (full mailbox, momentarily unreachable server) or an unmapped
     * provider field must not.
     *
     * @return array{type:?string, email:?string, message_uuid:?string, hard:bool, meta:array}
     */
    public function normalize(array $payload, ?string $provider = null): array
    {
        return match ($provider) {
            'mailgun' => $this->normalizeMailgun($payload),
            'postmark' => $this->normalizePostmark($payload),
            default => [
                'type' => $payload['type'] ?? $payload['event'] ?? null,
                'email' => $payload['email'] ?? $payload['recipient'] ?? null,
                'message_uuid' => $payload['message_uuid'] ?? null,
                // Absent `hard` = severity unknown = soft.
                'hard' => (bool) ($payload['hard'] ?? false),
                'meta' => $payload,
            ],
        };
    }

    protected function normalizeMailgun(array $payload): array
    {
        $data = $payload['event-data'] ?? $payload;
        $event = $data['event'] ?? null;

        $type = match ($event) {
            'failed' => 'bounce',
            'complained' => 'complaint',
            'unsubscribed' => 'unsubscribe',
            default => null,
        };

        return [
            'type' => $type,
            'email' => $data['recipient'] ?? null,
            'message_uuid' => $data['user-variables']['marketing_message'] ?? null,
            // Mailgun reports `severity: permanent|temporary`; anything else,
            // including a missing field, is unknown severity — treat as soft.
            'hard' => ($data['severity'] ?? null) === 'permanent',
            'meta' => ['provider' => 'mailgun', 'reason' => $data['reason'] ?? null],
        ];
    }

    protected function normalizePostmark(array $payload): array
    {
        $recordType = $payload['RecordType'] ?? null;

        $type = match ($recordType) {
            'Bounce' => 'bounce',
            'SpamComplaint' => 'complaint',
            'SubscriptionChange' => ($payload['SuppressSending'] ?? false) ? 'unsubscribe' : null,
            default => null,
        };

        return [
            'type' => $type,
            'email' => $payload['Email'] ?? $payload['Recipient'] ?? null,
            'message_uuid' => $payload['Metadata']['marketing_message'] ?? null,
            // Only Postmark's explicit HardBounce is permanent; a missing or
            // unrecognized `Type` is unknown severity — treat as soft.
            'hard' => ($payload['Type'] ?? null) === 'HardBounce',
            'meta' => ['provider' => 'postmark', 'description' => $payload['Description'] ?? null],
        ];
    }

    /** @return Collection<int, Subscription> */
    protected function resolveSubscriptions(?Message $message, ?string $email)
    {
        if ($message?->subscription) {
            return collect([$message->subscription]);
        }

        if (! $email) {
            return collect();
        }

        return Subscription::query()
            ->where('email_normalized', EmailNormalizer::normalize($email))
            ->get();
    }

    protected function applyBounce($subscriptions, ?Message $message, array $event): void
    {
        if ($message) {
            $message->update(['status' => Message::STATUS_BOUNCED]);

            MessageEvent::create([
                'message_id' => $message->id,
                'type' => MessageEvent::TYPE_BOUNCE,
                'meta' => $event['meta'],
            ]);

            event(new MessageBounced($message, ['hard' => $event['hard']]));
        }

        if (! $event['hard']) {
            return;
        }

        $subscriptions->each(function (Subscription $subscription) {
            $subscription->update(['status' => Subscription::STATUS_BOUNCED]);

            if (config('marketing.leadhub.hard_bounce_opt_out', true) && $subscription->contact_uuid) {
                LeadHub::optOut($subscription->contact_uuid);
            }
        });
    }

    protected function applyComplaint($subscriptions, ?Message $message, array $event): void
    {
        if ($message) {
            MessageEvent::create([
                'message_id' => $message->id,
                'type' => MessageEvent::TYPE_COMPLAINT,
                'meta' => $event['meta'],
            ]);

            event(new MessageComplained($message));
        }

        $subscriptions->each(function (Subscription $subscription) {
            $subscription->update(['status' => Subscription::STATUS_COMPLAINED]);

            if (config('marketing.leadhub.complaint_opt_out', true) && $subscription->contact_uuid) {
                LeadHub::optOut($subscription->contact_uuid);
            }
        });
    }
}
