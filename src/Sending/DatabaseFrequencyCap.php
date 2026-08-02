<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Contracts\FrequencyCap;
use Goldnead\Marketing\Contracts\MailClass;
use Goldnead\Marketing\Models\MailLogEntry;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The frequency cap, counted out of `marketing_mail_log`.
 *
 * Three decisions are worth reading before changing anything here.
 *
 * **The address is the identity, not the subscription.** Somebody on four lists
 * is one person with one inbox, and counting per subscription would hand them
 * four times the cap while the config still said three. So the count is keyed
 * on the normalized address, through LeadHub's normalizer — the same one the
 * consent uniqueness and the suppression gate use, so "the same person" means
 * the same thing in all three.
 *
 * **The window is rolling and measured on one clock.** `now()` writes the log
 * row and `now()->subHours()` reads it back; no value ever crosses a timezone
 * on the way in or out. That is not fussiness — Laravel's `datetime` cast
 * serialises a zoned Carbon without converting it, so a value handed in from
 * another zone lands in the table off by that offset, and a window built on it
 * is wrong at both edges by the same amount. Staying on `now()` makes the
 * arithmetic correct for any `app.timezone`, which is what the Europe/Berlin
 * test proves.
 *
 * **It falls open.** A cap that cannot count says yes and logs it. Compare
 * `Goldnead\Suppression\Contracts\Gate`, which falls closed and aborts the
 * campaign — and the difference is not an inconsistency. Suppression is the
 * only thing between a send and somebody who said no; the cap is between a send
 * and somebody who has been hearing from us a lot. Refusing to send because a
 * count failed would turn a database hiccup into an undelivered campaign.
 */
class DatabaseFrequencyCap implements FrequencyCap
{
    public function enabled(): bool
    {
        return (bool) config('marketing.frequency_cap.enabled', false);
    }

    public function limit(): int
    {
        return max(1, (int) config('marketing.frequency_cap.max', 3));
    }

    public function windowHours(): int
    {
        return max(1, (int) config('marketing.frequency_cap.window_hours', 168));
    }

    public function allows(string $email, MailClass $class, ?int $brandId = null): bool
    {
        if (! $this->enabled() || ! $class->subjectToCap()) {
            return true;
        }

        return $this->countInWindow($email, $brandId) < $this->limit();
    }

    public function record(string $email, MailClass $class, ?int $brandId = null, ?string $reference = null): void
    {
        $normalized = $this->normalize($email);

        if ($normalized === '') {
            return;
        }

        try {
            MailLogEntry::query()->create([
                'brand_id' => $brandId ?? $this->currentBrandId(),
                'email_normalized' => $normalized,
                'mail_class' => $class->value,
                'reference' => $reference,
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            // A mail that was delivered must not be reported as failed because
            // its bookkeeping row would not write. The consequence of losing
            // one row is that one send is uncounted, which loosens the cap by
            // one for one person for one window — strictly smaller than
            // marking a delivered message failed and retrying it.
            report($e);
        }
    }

    public function countInWindow(string $email, ?int $brandId = null): int
    {
        $normalized = $this->normalize($email);

        if ($normalized === '') {
            return 0;
        }

        try {
            return MailLogEntry::query()
                ->where('brand_id', $brandId ?? $this->currentBrandId())
                ->where('email_normalized', $normalized)
                ->where('mail_class', MailClass::Marketing->value)
                ->where('sent_at', '>=', now()->subHours($this->windowHours()))
                ->count();
        } catch (Throwable $e) {
            Log::warning(
                'Marketing could not count the frequency cap window for a recipient, so the '
                .'send was allowed through. Reason: '.$e->getMessage()
            );

            return 0;
        }
    }

    protected function normalize(string $email): string
    {
        return (string) EmailNormalizer::normalize($email);
    }

    /**
     * The brand to count under when the caller did not name one.
     *
     * Callers inside a queue worker always name one, because the worker has no
     * brand and the message row does. This is the fallback for a caller running
     * inside a request, where the current brand is the right answer.
     */
    protected function currentBrandId(): ?int
    {
        try {
            return app('brand-context')->currentId();
        } catch (Throwable) {
            return null;
        }
    }
}
