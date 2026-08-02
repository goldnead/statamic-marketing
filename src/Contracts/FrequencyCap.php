<?php

namespace Goldnead\Marketing\Contracts;

/**
 * How much marketing mail one contact may receive in a rolling window.
 *
 * Companion to `Goldnead\Suppression\Contracts\Gate`, and deliberately not the
 * same kind of thing. Suppression is a hard no that outlives the request: this
 * address may never be mailed again until somebody releases it. A cap is a
 * *later*: this address has had enough this week. The send path asks both, in
 * that order — suppression first, then whatever the reader has said about what
 * they want, then this — because there is no point deferring a mail to an
 * address that must never receive it.
 *
 * **The cap falls open.** Where the suppression gate must abort a send it
 * cannot answer for, this one must not. It protects a reader's attention, not
 * their consent: an unanswerable cap check that stopped a campaign would trade
 * a real delivery failure for a hypothetical annoyance. So an implementation
 * that cannot count answers "allowed" and says so in the log.
 *
 * **The question is asked at send, not at enqueue.** A campaign snapshots its
 * audience and hands the queue one job per recipient; those jobs can sit for
 * days behind a throttle, a retry or a paused worker. Whether somebody has had
 * their three mails is a fact about the moment the mail actually leaves, and a
 * decision taken at enqueue time would be answering a question about a week
 * that has since passed.
 */
interface FrequencyCap
{
    /** Is a cap configured at all? Off by default. */
    public function enabled(): bool;

    /** How many marketing mails one contact may receive per window. */
    public function limit(): int;

    /** The length of the rolling window, in hours. */
    public function windowHours(): int;

    /**
     * May this address be sent a mail of this class right now?
     *
     * Always true when the cap is off, and always true for a class the cap does
     * not act on — see {@see MailClass}. The caller does not have to know the
     * exceptions; that is what makes this the one question worth asking.
     *
     * @param  int|null  $brandId  null resolves the current brand. Pass it
     *                             explicitly from a queued job: a worker has no
     *                             brand context of its own, and the message row
     *                             does.
     */
    public function allows(string $email, MailClass $class, ?int $brandId = null): bool;

    /**
     * Note that a mail of this class went out to this address.
     *
     * Called after delivery, not before it: a mail that failed to send did not
     * reach anybody and must not consume anybody's budget.
     *
     * Every class is recorded, not just the ones that count. The log is also
     * what the control panel reads to explain a hold to an editor, and
     * "three marketing mails, one digest, one receipt" is an answer, where
     * "three" alone is a number somebody has to take on trust.
     *
     * @param  string|null  $reference  what sent it — a campaign handle, or
     *                                  another addon's own identifier.
     */
    public function record(string $email, MailClass $class, ?int $brandId = null, ?string $reference = null): void;

    /**
     * How many capped mails this address has had inside the current window.
     */
    public function countInWindow(string $email, ?int $brandId = null): int;
}
