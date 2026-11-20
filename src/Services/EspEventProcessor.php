<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Events\MessageBounced;
use Goldnead\Marketing\Events\MessageComplained;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\MessageEvent;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Suppression\Facades\Suppression;
use Goldnead\Suppression\Reasons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
            // Across brands on purpose: a webhook has no current brand, and in
            // multi-brand mode the brand scope would hide every message. The
            // uuid is unique, and the message then tells which brand the mail
            // came from (needed to scope a complaint).
            ? Message::query()->acrossBrands()->where('uuid', $event['message_uuid'])->first()
            : null;

        $subscriptions = $this->resolveSubscriptions($message, $event['email']);

        // Whether the suppression list took the event. It can, even when no
        // subscription matched: a bounce for a buyer or a login address is
        // exactly what the list is for.
        $listed = false;

        match ($event['type']) {
            'bounce' => $listed = $this->applyBounce($subscriptions, $message, $event, $provider),
            'complaint' => $listed = $this->applyComplaint($subscriptions, $message, $event, $provider),
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

        // Handled means something was applied. An event for an address with no
        // subscription and no matching message changed nothing, and reporting
        // it as handled made a lost unsubscribe look like a processed one.
        $handled = in_array($event['type'], ['bounce', 'complaint', 'unsubscribe'], true)
            && ($message !== null || $subscriptions->isNotEmpty() || $listed);

        if (! $handled) {
            Log::warning('Marketing received a '.$event['type'].' event that matched no subscription or message; nothing was applied.', [
                'type' => $event['type'],
                'provider' => $provider,
                'email_hash' => $event['email'] ? substr(hash('sha256', (string) EmailNormalizer::normalize($event['email'])), 0, 16) : null,
                'message_uuid' => $event['message_uuid'],
            ]);
        }

        return [
            'ok' => true,
            'handled' => $handled,
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
            // `id` is the event id of Mailgun's webhook payload and the
            // message id sits in the stored message headers (field names as
            // Mailgun documents them; unverified against a live delivery).
            // Both only feed the suppression list's dedupe, absent is fine.
            'meta' => [
                'provider' => 'mailgun',
                'reason' => $data['reason'] ?? null,
                'event_id' => $data['id'] ?? null,
                'message_id' => $data['message']['headers']['message-id'] ?? null,
            ],
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
            // `ID` is Postmark's bounce or complaint id, `MessageID` the
            // message it belongs to (as Postmark documents them; unverified
            // against a live delivery). Absent is fine.
            'meta' => [
                'provider' => 'postmark',
                'description' => $payload['Description'] ?? null,
                'event_id' => $payload['ID'] ?? null,
                'message_id' => $payload['MessageID'] ?? null,
            ],
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
            'meta' => [
                'provider' => 'lettermint',
                'event' => $event,
                'event_id' => $payload['id'] ?? null,
                'message_id' => $data['message_id'] ?? null,
            ],
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

    /**
     * Writes the fact to the suppression list, the one list every send asks
     * (campaigns, sequences, single sends). The subscription status only
     * protects the newsletter; a hard bounce is a property of the mailbox and
     * has to stop mail to a buyer or a login address as well.
     *
     * A hard bounce suppresses, a soft bounce is only counted (the list
     * promotes it when the threshold trips), a complaint suppresses for the
     * brand the mail came from. A repeated provider event id changes nothing.
     *
     * Runs last, after the subscription, the message and the LeadHub contact
     * have been updated, so a problem here never undoes them.
     *
     * Failure policy, decided deliberately: nothing here answers 5xx.
     * - Without the suppression package or its table the event is logged as a
     *   warning and skipped: a host that has not migrated yet keeps working.
     * - Any other failure is reported and logged, then swallowed. A retry
     *   would not be harmless: the steps above are not idempotent (a second
     *   MessageEvent row, a second MessageBounced event), and the newsletter
     *   is already protected by the subscription status.
     *
     * @return bool whether the list took the event
     */
    protected function recordInSuppressionList($subscriptions, ?Message $message, array $event, ?string $provider, string $kind): bool
    {
        $email = $event['email'] ?: $subscriptions->first()?->email;
        $email = is_string($email) ? trim($email) : '';

        if ($email === '') {
            return false;
        }

        if (! class_exists(Suppression::class) || ! Schema::hasTable('suppressions')) {
            Log::warning('Marketing could not write a '.$kind.' to the suppression list: the package or its table is missing. Run the migrations of goldnead/statamic-suppression.', [
                'provider' => $provider ?? ($event['meta']['provider'] ?? null),
            ]);

            return false;
        }

        $eventId = $event['meta']['event_id'] ?? null;
        $messageId = $event['meta']['message_id'] ?? null;

        $attributes = array_filter([
            'source' => 'esp_webhook',
            'provider' => $provider ?? ($event['meta']['provider'] ?? null),
            'provider_event_id' => is_scalar($eventId) ? (string) $eventId : null,
            'provider_message_id' => is_scalar($messageId) ? (string) $messageId : null,
            'message_uuid' => $event['message_uuid'] ?? ($message !== null ? $message->uuid : null),
            // A complaint is scoped to a brand. Without this the list falls
            // back to the brand current in the request, which in a webhook is
            // the default brand, not the one that sent the mail.
            'brand_id' => $kind === 'complaint'
                ? ($message !== null && $message->brand_id !== null ? $message->brand_id : $this->brandIdOf($subscriptions->first()))
                : null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            match ($kind) {
                'hard_bounce' => Suppression::suppress($email, Reasons::HARD_BOUNCE, $attributes),
                'soft_bounce' => Suppression::recordSoftBounce($email, $attributes),
                'complaint' => Suppression::suppress($email, Reasons::COMPLAINT, $attributes),
            };
        } catch (Throwable $e) {
            report($e);
            Log::error('Marketing could not write a '.$kind.' to the suppression list; the subscription was updated anyway.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'email_hash' => substr(hash('sha256', (string) EmailNormalizer::normalize($email)), 0, 16),
            ]);

            return false;
        }

        return true;
    }

    protected function brandIdOf(mixed $subscription): ?int
    {
        return $subscription instanceof Subscription && $subscription->brand_id !== null
            ? (int) $subscription->brand_id
            : null;
    }

    protected function applyBounce($subscriptions, ?Message $message, array $event, ?string $provider = null): bool
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
            return $this->recordInSuppressionList($subscriptions, $message, $event, $provider, 'soft_bounce');
        }

        $subscriptions->each(function (Subscription $subscription) {
            $subscription->update(['status' => Subscription::STATUS_BOUNCED]);

            if (config('marketing.leadhub.hard_bounce_opt_out', true) && $subscription->contact_uuid) {
                LeadHub::optOut($subscription->contact_uuid);
            }
        });

        return $this->recordInSuppressionList($subscriptions, $message, $event, $provider, 'hard_bounce');
    }

    protected function applyComplaint($subscriptions, ?Message $message, array $event, ?string $provider = null): bool
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

        return $this->recordInSuppressionList($subscriptions, $message, $event, $provider, 'complaint');
    }
}
