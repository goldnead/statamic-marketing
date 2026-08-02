<?php

namespace Goldnead\Marketing\Services;

/**
 * Decides which A/B variant a recipient belongs to.
 *
 * The hard requirement is not randomness — it is that the answer never
 * changes. A campaign is snapshotted once, sent over hours, retried on
 * failure, and read back weeks later by a report. If the same recipient could
 * land in A on one pass and B on the next, every number the report produces is
 * noise dressed as a result.
 *
 * So the assignment is a **pure function** of facts that do not move:
 *
 *   sha256( brand_id \0 campaign_handle \0 recipient_key )
 *
 * No `rand()`, no `mt_rand()`, no clock, no auto-increment id, no request or
 * process state. Two PHP workers on two machines, a year apart, computing this
 * for the same three inputs get the same bucket — which is what makes it
 * survive a retried job, a re-queued message, and a row that was deleted and
 * rebuilt. The stored value on the message row is therefore a cache of a fact,
 * not the fact itself; losing it loses nothing.
 *
 * `campaign_handle` is in the seed on purpose. Without it, the same half of the
 * list would be the B group in every campaign forever, and the test would
 * measure that cohort rather than the subject line. With it, each campaign
 * reshuffles while staying stable within itself.
 *
 * `brand_id` is in the seed because everything in this suite is brand-scoped
 * since statamic-brand-context. Recipient keys are already unique per brand, so
 * this is belt and braces — but a bucket is exactly the kind of derived value
 * that must not be able to cross a tenant boundary even in principle.
 */
class VariantAssigner
{
    public const VARIANT_A = 'a';

    public const VARIANT_B = 'b';

    /**
     * The variant set an A/B campaign splits across.
     *
     * Two, evenly. More variants, uneven weights and a winner rule are all
     * deliberate omissions rather than oversights — see the ticket.
     *
     * @return list<string>
     */
    public static function variants(): array
    {
        return [self::VARIANT_A, self::VARIANT_B];
    }

    /**
     * The variant this recipient belongs to in this campaign.
     *
     * @param  string  $campaignHandle  the campaign being split
     * @param  string  $recipientKey  a stable, immutable identifier for the
     *                                recipient. `Subscription.uuid` — never the
     *                                email (people change it) and never the
     *                                auto-increment id (it is not stable across
     *                                a restore).
     * @param  int|null  $brandId  the tenant the send belongs to
     */
    public function assign(string $campaignHandle, string $recipientKey, ?int $brandId = null): string
    {
        $variants = static::variants();

        $digest = hash('sha256', implode("\0", [
            (string) $brandId,
            $campaignHandle,
            $recipientKey,
        ]));

        // The first 32 bits of the digest, as an integer in [0, 2^32).
        //
        // 2^32 is divisible by 2, so the modulo below is exactly uniform for
        // the two-variant set — no modulo bias to correct for. Adding a third
        // variant later would introduce a (vanishingly small) bias here, and
        // that is the line to revisit if it ever happens.
        $bucket = (int) hexdec(substr($digest, 0, 8));

        return $variants[$bucket % count($variants)];
    }
}
