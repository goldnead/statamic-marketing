<?php

namespace Goldnead\Marketing\Sending;

/**
 * What a sign-up may be told about its confirmation mail.
 *
 * Until 2.5.0 the answer to a sign-up was the same three keys whether a mail
 * went out or not, and the caller had no way to find out. That is defensible
 * for the two reasons below and indefensible for the rest, and it shipped as
 * one undifferentiated silence: on 13.08.2026 a real sign-up on gldnr.studio
 * was told "check your inbox" while the recipient throttle held the mail back.
 * The person is still `pending` and never received anything.
 *
 * This class is the line between what may be said and what may not. It carries
 * exactly three values, and which one a caller gets is decided by ONE question:
 * would knowing it tell a stranger something about the PERSON behind the
 * address?
 *
 *   SENT         A confirmation is on its way — and also the cover for the two
 *                cases that must stay silent (see below). "Nothing further will
 *                be said about this address" is the honest reading.
 *
 *   THROTTLED    No mail right now, because this MAILBOX was asked recently.
 *                A statement about a moment in time, not about a person: it
 *                says an attempt happened, not that anybody is subscribed,
 *                confirmed, blocked or even real.
 *
 *   UNAVAILABLE  No mail right now, because this INSTALLATION could not send
 *                one — no usable counter, a broken brand identity, a transport
 *                that refused. Identical for every address, so it discloses
 *                nothing about anyone.
 *
 * The two cases that hide behind SENT, and why:
 *
 *   already subscribed   Saying so turns the form into a membership oracle.
 *   suppressed           Saying so discloses a bounce or a complaint, which is
 *                        a fact about the person's mailbox and their behaviour.
 *
 * Both of them must also reach the SAME verdict an ordinary sign-up reaches,
 * not merely start from the same word. `SubscriptionService::coverForSilentPath()`
 * therefore walks every gate the real send walks: it charges the recipient
 * throttle, asks the suppression gate (and throws away the answer, keeping only
 * whether the gate could be asked), and resolves the brand's sender identity.
 * The cover story has to cost the same as the real thing or it is not a cover
 * story.
 *
 * That lesson was learned twice. The first draft charged the throttle and
 * nothing else, which held for repeated attempts and fell apart in one request
 * as soon as the installation could not send: an ordinary address answered
 * UNAVAILABLE and a subscribed one still answered SENT. The field built the
 * oracle in precisely the state it was invented for.
 *
 * **One gate stays weaker, and it is named rather than hidden.** Whether a
 * relay will accept a message cannot be asked without giving it one, and the
 * silent path has none. `Sending\TransportHealth` closes most of it — a failed
 * send is remembered for a minute and the silent path reads it — but the first
 * request after a quiet window still slips through: probe a subscribed address
 * before any real send has failed, then a control address, and the pair
 * separates them. It closes completely only when the confirmation is queued,
 * because then no response knows a delivery outcome at all. That is the same
 * change that closes the timing channel below.
 *
 * `retryAfterMinutes` is the CONFIGURED WINDOW, never the time remaining.
 * Remaining time would say how long ago the earlier attempt was, down to the
 * second — a far sharper answer to "has anybody touched this mailbox lately"
 * than the one-hour bucket THROTTLED already gives away. The window is a
 * constant and tells a caller everything it needs in order to word its message.
 */
final class ConfirmationResult
{
    public const SENT = 'sent';

    public const THROTTLED = 'throttled';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $outcome,
        public readonly ?int $retryAfterMinutes = null,
    ) {}

    public static function sent(): self
    {
        return new self(self::SENT);
    }

    public static function throttled(?int $retryAfterMinutes = null): self
    {
        return new self(self::THROTTLED, $retryAfterMinutes);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function wasSent(): bool
    {
        return $this->outcome === self::SENT;
    }

    /**
     * The wire shape, defined once.
     *
     * Three endpoints answer sign-ups (this addon's public form, the host's own
     * API, anything a host adds), and every one of them is read by a browser in
     * front of a stranger. A shape invented separately in each place is a shape
     * that drifts apart, and the first field somebody adds "because it is
     * useful here" is how the status leak this class removes got in.
     *
     * @return array{confirmation:string,retry_after_minutes?:int}
     */
    public function toArray(): array
    {
        return array_filter([
            'confirmation' => $this->outcome,
            'retry_after_minutes' => $this->retryAfterMinutes,
        ], fn ($value) => $value !== null);
    }
}
