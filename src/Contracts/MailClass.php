<?php

namespace Goldnead\Marketing\Contracts;

/**
 * What kind of mail this is.
 *
 * This is the contract, not a marketing internal. A frequency cap is only ever
 * as good as its exceptions — "three mails a week" is a rule nobody wants
 * applied to a password reset — and the exceptions cannot be decided by the
 * addon that happens to be sending. They have to be declared by whoever knows
 * what the mail is for. So every outgoing mail carries one of these four
 * values, and any addon in the family can name one:
 *
 *     use Goldnead\Marketing\Contracts\MailClass;
 *
 *     app(FrequencyCap::class)->allows($email, MailClass::Reminder);
 *
 * Four values, and the boundaries between them are the useful part:
 *
 *  - **marketing** — sent because there is something to say to an audience.
 *    Campaigns, announcements, offers, the newsletter. This is the only class
 *    the cap acts on, in either direction: it is the only one that consumes
 *    budget and the only one that can be held back by it.
 *  - **transactional** — sent because the recipient did something and is
 *    waiting for the result. Password reset, order confirmation, double
 *    opt-in. Never counted, never held: a person who cannot get back into
 *    their account because they read three newsletters this week is a bug
 *    with a support ticket behind it.
 *  - **digest** — a periodic roll-up somebody subscribed to as a rhythm, like
 *    a community digest. It does not count towards the cap, and that is the
 *    whole point of naming it separately: the cap exists to stop *additional*
 *    mail crowding a reader, and the digest is the baseline they already
 *    agreed to. Counting it would mean the digest itself ate the budget and
 *    silenced everything else.
 *  - **reminder** — time-bound and tied to a thing the recipient signed up
 *    for: the event is tomorrow. Explicitly allowed to exceed the cap. A
 *    reminder that arrives late is not a quieter inbox, it is a missed event.
 *
 * Unknown or absent reads as `marketing`. That is the conservative direction:
 * a mail nobody classified is capped rather than exempted, so forgetting to
 * declare a class costs a delay and never costs an exemption somebody did not
 * ask for.
 */
enum MailClass: string
{
    case Marketing = 'marketing';

    case Transactional = 'transactional';

    case Digest = 'digest';

    case Reminder = 'reminder';

    /**
     * Read a stored or configured value.
     *
     * Anything unrecognised — null, an empty string, a value from a newer
     * release, a typo in a YAML file — becomes `Marketing`. See the class
     * note: the default has to be the capped one.
     */
    public static function fromValue(?string $value): self
    {
        return $value === null ? self::Marketing : (self::tryFrom($value) ?? self::Marketing);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * Does sending this consume a contact's marketing budget?
     *
     * Only marketing does. A digest, a receipt and an event reminder all reach
     * the same inbox, and none of them is the thing the cap was put there to
     * limit.
     */
    public function countsTowardCap(): bool
    {
        return $this === self::Marketing;
    }

    /**
     * Can this mail be held back by the cap?
     *
     * The same single answer as {@see countsTowardCap()}, stated separately
     * because they are two different questions and a later release could
     * answer them differently — a class that counts but is never held, say.
     * Call sites that mean "may I hold this" should not have to know that the
     * two happen to coincide today.
     */
    public function subjectToCap(): bool
    {
        return $this === self::Marketing;
    }

    public function label(): string
    {
        return (string) __('marketing::campaigns.mail_class_options.'.$this->value);
    }
}
