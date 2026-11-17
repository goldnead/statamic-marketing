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
            // The provider names the message it saw the unsubscribe on; pass
            // it along so the report counts it there rather than on whichever
            // mail happened to leave last.
            'unsubscribe' => $subscriptions->each(
                fn (Subscription $subscription) => $this->subscriptions->unsubscribe($subscription, array_filter([
                    'reason' => 'esp_unsubscribe',
                    'message_id' => $message?->id,
                ]))
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
        // Brevo posts its raw body without any provider marker, so it is
        // recognized by shape when the endpoint did not name a provider.
        if ($provider === null && $this->looksLikeBrevo($payload)) {
            $provider = 'brevo';
        }

        return match ($provider) {
            'brevo' => $this->normalizeBrevo($payload),
            'mailgun' => $this->normalizeMailgun($payload),
            'postmark' => $this->normalizePostmark($payload),
            'lettermint' => $this->normalizeLettermint($payload),
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

    /**
     * Brevo names the outcome in `event`. Transactional webhooks use
     * `unsubscribed`, marketing (campaign) webhooks `unsubscribe`; both map.
     * `hard_bounce`, `invalid_email` and `blocked` are permanent, `soft_bounce`
     * is not. `deferred`, `error`, `delivered`, `opened`, `click` and the rest
     * map to no type.
     *
     * Docs: https://developers.brevo.com/docs/transactional-webhooks and
     * https://developers.brevo.com/docs/marketing-webhooks
     */
    protected function normalizeBrevo(array $payload): array
    {
        $event = $payload['event'] ?? null;

        $type = match ($event) {
            'hard_bounce', 'invalid_email', 'blocked', 'soft_bounce' => 'bounce',
            'spam' => 'complaint',
            'unsubscribed', 'unsubscribe' => 'unsubscribe',
            default => null,
        };

        $email = $payload['email'] ?? null;

        return [
            'type' => $type,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'message_uuid' => null,
            'hard' => in_array($event, ['hard_bounce', 'invalid_email', 'blocked'], true),
            'meta' => [
                'provider' => 'brevo',
                'event' => $event,
                'reason' => $payload['reason'] ?? null,
                'message_id' => $payload['message-id'] ?? null,
                'tags' => $payload['tags'] ?? $payload['tag'] ?? null,
                'ts_event' => $payload['ts_event'] ?? null,
            ],
        ];
    }

    protected function looksLikeBrevo(array $payload): bool
    {
        if (isset($payload['type']) || isset($payload['event-data']) || isset($payload['RecordType'])) {
            return false;
        }

        return in_array($payload['event'] ?? null, [
            'hard_bounce', 'soft_bounce', 'invalid_email', 'blocked', 'spam', 'unsubscribed', 'unsubscribe',
        ], true) && (isset($payload['message-id']) || isset($payload['camp_id']) || isset($payload['ts_event']));
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

    /**
     * Lettermint names a bounce by its event (`message.hard_bounced` is
     * permanent, `message.soft_bounced` is not) and mirrors every address it
     * suppresses as `suppression.added` with a `reason`. Both are mapped: the
     * suppression event is the one that arrives even when the message event
     * carried nothing we could match. Repeating the same outcome is harmless,
     * the processor only ever moves a subscription to a final state.
     *
     * Only an address suppression counts. A domain or extension entry, a
     * `manual` reason and `suppression.removed` (a release is not a
     * resubscription, that is the person's decision) map to no type.
     */
    protected function normalizeLettermint(array $payload): array
    {
        $event = $payload['event'] ?? null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $email = $data['recipient'] ?? null;
        $hard = false;

        $type = match ($event) {
            'message.hard_bounced' => 'bounce',
            'message.soft_bounced' => 'bounce',
            'message.spam_complaint' => 'complaint',
            'message.unsubscribed' => 'unsubscribe',
            default => null,
        };
        $hard = $event === 'message.hard_bounced';

        if ($event === 'suppression.added' && ($data['type'] ?? null) === 'email') {
            $email = $data['value'] ?? null;
            $type = match ($data['reason'] ?? null) {
                'hard_bounce' => 'bounce',
                'spam_complaint' => 'complaint',
                'unsubscribe' => 'unsubscribe',
                default => null,
            };
            $hard = ($data['reason'] ?? null) === 'hard_bounce';
        }

        return [
            'type' => $type,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'message_uuid' => null,
            'hard' => $hard,
            'meta' => ['provider' => 'lettermint', 'event' => $event, 'event_id' => $payload['id'] ?? null],
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
