<?php

namespace Goldnead\Marketing\Support;

use Carbon\CarbonInterface;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which marketing mails an address has received.
 *
 * Every node that asks this question asks it here, so "already mailed" means
 * the same thing in the check node, the check on a tag, and the day gate.
 *
 * A mail counts when it left or is on its way out: status `sent`, `bounced`
 * (a bounce is reported after the hand-over and overwrites the status of that
 * message) or `sending` once `sent_at` is stamped, which the job does before the
 * hand-over, so a parallel run does not send the same person a second mail.
 * Pending, failed, skipped and capped rows are not counted, and neither is
 * anything that was never a marketing mail.
 *
 * The address is found through its subscriptions (`email_normalized`, indexed),
 * and the messages through their `subscription_id`. Every message belongs to a
 * subscription, so this is the same set as comparing the message address, without
 * a scan over the messages table.
 *
 * Templates and campaigns are matched by handle; when both are given, a message
 * matching either counts.
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
        $subscriptions = Subscription::query()
            ->select('id')
            ->where('email_normalized', (string) EmailNormalizer::normalize($email));

        $query = Message::query()
            ->whereIn('subscription_id', $subscriptions)
            ->whereIn('status', [Message::STATUS_SENT, Message::STATUS_BOUNCED, Message::STATUS_SENDING])
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
