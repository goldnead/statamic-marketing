<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\Marketing\Models\Message;

/**
 * What happened when one campaign was sent to one person.
 *
 * Three outcomes, and they are not three shades of failure. `sent` and
 * `blocked` are both final; `deferred` is the only one that says "ask again",
 * and a caller that treats it as a failure turns a frequency cap into a lost
 * mail.
 *
 * The reason string is a machine key rather than a sentence, because the two
 * callers of this class need different sentences from the same fact: an
 * automation writes it into a run log an editor reads, and a test asserts on
 * it. Both would break on a translated message.
 */
class SingleSendResult
{
    public const SENT = 'sent';

    /** A hard no. Consent, suppression or opt-out — nothing to retry. */
    public const BLOCKED = 'blocked';

    /** A later. The frequency cap holds this address for now. */
    public const DEFERRED = 'deferred';

    /** The send itself threw. Distinct from blocked: nobody said no. */
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly ?Message $message = null,
        public readonly ?int $retryAfterMinutes = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(Message $message): self
    {
        return new self(self::SENT, 'sent', $message);
    }

    public static function blocked(string $reason): self
    {
        return new self(self::BLOCKED, $reason);
    }

    public static function deferred(string $reason, int $retryAfterMinutes): self
    {
        return new self(self::DEFERRED, $reason, retryAfterMinutes: $retryAfterMinutes);
    }

    public static function failed(string $reason, string $error): self
    {
        return new self(self::FAILED, $reason, error: $error);
    }

    public function wasSent(): bool
    {
        return $this->status === self::SENT;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::BLOCKED;
    }

    public function isDeferred(): bool
    {
        return $this->status === self::DEFERRED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
