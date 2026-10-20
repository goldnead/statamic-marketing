<?php

namespace Goldnead\Marketing\Support;

use Carbon\CarbonInterface;
use Goldnead\Marketing\Models\Message;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which marketing mails an address has received.
 *
 * Every node that asks this question asks it here, so "already mailed" means
 * the same thing in the check node, the check on a tag, and the day gate.
 *
 * A mail counts when it left: status `sent`, or `bounced`, because a bounce
 * is reported after the hand-over and overwrites the status of that message.
 * Pending, claimed, failed, skipped and capped rows are not counted, and
 * neither is anything that was never a marketing mail.
 *
 * The address is compared without regard to case. Templates and campaigns
 * are matched by handle; when both are given, a message matching either counts.
 */
final class MailReceipts
{
    /**
     * A comma-separated list of handles, trimmed, empties dropped.
     *
     * @return list<string>
     */
    public static function handles(mixed $raw): array
    {
        return collect(explode(',', (string) $raw))
            ->map(fn (string $handle): string => trim($handle))
            ->filter(fn (string $handle): bool => $handle !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The sent messages of one address, optionally narrowed.
     *
     * `$since` and `$until` bound `sent_at` inclusively. They are converted to
     * UTC here, because the database compares UTC and a Carbon in another zone
     * would otherwise be written in its own.
     *
     * @param  list<string>  $templates
     * @param  list<string>  $campaigns
     * @return Builder<Message>
     */
    public static function query(
        string $email,
        array $templates = [],
        array $campaigns = [],
        ?CarbonInterface $since = null,
        ?CarbonInterface $until = null,
    ): Builder {
        $query = Message::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->whereIn('status', [Message::STATUS_SENT, Message::STATUS_BOUNCED])
            ->whereNotNull('sent_at');

        if ($templates !== [] || $campaigns !== []) {
            $query->where(function (Builder $either) use ($templates, $campaigns): void {
                if ($templates !== []) {
                    $either->whereIn('template_handle', $templates);
                }

                if ($campaigns !== []) {
                    $either->orWhereIn('campaign_handle', $campaigns);
                }
            });
        }

        if ($since !== null) {
            $query->where('sent_at', '>=', $since->copy()->utc());
        }

        if ($until !== null) {
            $query->where('sent_at', '<=', $until->copy()->utc());
        }

        return $query;
    }
}
