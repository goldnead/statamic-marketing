<?php

namespace Goldnead\Marketing\Sending;

use Goldnead\Marketing\Support\DeliveryIdentity;
use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * How much double-opt-in mail one mailbox may be made to receive.
 *
 * The sign-up endpoint is anonymous, public, and backed by a verified sending
 * domain — which is the exact shape of a mail cannon aimed at somebody else.
 * Nothing in front of it bounds the damage to a single victim: the websites
 * throttle per client IP, and the hub throttles per brand at 120 requests a
 * minute. Both are limits on the SENDER. Neither has ever asked how much of
 * that lands on one person, and the answer was "all of it".
 *
 * This is the only limit in the stack keyed on the RECIPIENT, and it is
 * deliberately placed at the send itself rather than at the endpoint, so it
 * covers every route that reaches a confirmation mail — the hub API, the
 * Statamic form endpoint, and anything added later.
 *
 * Two tiers, because one number cannot do both jobs:
 *
 *   per list, per hour   The tight one. A person needs exactly one
 *                        confirmation mail per list, and an hour is a
 *                        reasonable wait before re-requesting it.
 *
 *   per mailbox, per day The bound on everything at once. Without it, an
 *                        attacker walks the tight limit across every list and
 *                        every brand on the hub and multiplies their way back
 *                        up. It is set loose enough that a real person
 *                        subscribing to two brands' newsletters in one
 *                        afternoon never meets it.
 *
 * Ordering matters: the tight tier is charged first and answers alone when it
 * is exhausted, so a stream of attempts at one list cannot burn the whole
 * day's budget and lock the mailbox out of every OTHER list.
 */
class ConfirmationThrottle
{
    public const ALLOWED = null;

    public const WITHHELD_PER_LIST = 'per_list';

    public const WITHHELD_PER_MAILBOX = 'per_mailbox';

    public const WITHHELD_NO_COUNTER = 'no_counter';

    /**
     * Stores whose `increment()` is one atomic operation.
     *
     * This list is the difference between a limit and a decoration. Two
     * sign-ups arriving together both read the counter and both write it back;
     * only a store that increments in one indivisible step gives them 1 and 2
     * instead of 1 and 1. Redis and Memcached do it in the server, DynamoDB in
     * an UpdateItem, the database driver under a row lock, APCu in shared
     * memory, and ArrayStore trivially because it never leaves the process.
     *
     * `FileStore` is deliberately absent, and it is the framework's DEFAULT.
     * It reads a file, adds one and writes it back — three steps, no lock —
     * and it reports success whether or not the write landed, so a cache
     * directory that is full or unwritable returns 1 forever and permits
     * everything. That is the shape of the bug this addon family has now
     * shipped twice, and guessing that production is configured for Redis is
     * not the same as knowing it.
     */
    protected const ATOMIC_STORES = [
        RedisStore::class,
        MemcachedStore::class,
        DynamoDbStore::class,
        DatabaseStore::class,
        ApcStore::class,
        ArrayStore::class,
    ];

    /**
     * Charge this send against the recipient's budget.
     *
     * @return string|null one of the WITHHELD_* reasons, or null when the mail
     *                     may be sent
     */
    public function charge(?string $email, string $listHandle): ?string
    {
        if (! config('marketing.subscriptions.confirmation_throttle.enabled', true)) {
            return self::ALLOWED;
        }

        $limiter = $this->limiter();

        /*
         * No counter that can be trusted, so nothing is sent.
         *
         * The endpoint behind this is public and anonymous, and a limit that
         * cannot count is not a limit — it is a comment. Same sentence the
         * suppression gate above already lives by: not knowing is not
         * permission. The pending row survives, so everything is recoverable
         * once the cache is configured or reachable again.
         */
        if (! $limiter) {
            return self::WITHHELD_NO_COUNTER;
        }

        $mailbox = DeliveryIdentity::keyFor($email);

        $listMax = (int) config('marketing.subscriptions.confirmation_throttle.per_list', 1);
        $listWindow = (int) config('marketing.subscriptions.confirmation_throttle.per_list_window_minutes', 60) * 60;

        /*
         * Incremented BEFORE the decision, and the decision is made on the
         * value the increment returned. Checking first and incrementing after
         * is two round trips with a gap between them, and two sign-ups for the
         * same address arriving in that gap both read "0 so far" and both
         * send. `hit()` is one atomic increment on every store this class
         * accepts (see ATOMIC_STORES), so concurrent callers get 1 and 2 and
         * exactly one of them is over the limit.
         *
         * A blocked attempt still increments. That does not extend the window:
         * `hit()` sets the expiry with `add()`, so the clock keeps running from
         * the first attempt and the mailbox is free again an hour after the
         * first mail, however hard anybody hammered it in between.
         */
        $listHits = $limiter->hit($this->key('list', $listHandle, $mailbox), $listWindow);

        // Belt and braces behind the store check above: a store that answers
        // an increment with 0 stored nothing, whatever class it claims to be.
        if ($listHits < 1) {
            return self::WITHHELD_NO_COUNTER;
        }

        if ($listHits > $listMax) {
            return self::WITHHELD_PER_LIST;
        }

        $mailboxMax = (int) config('marketing.subscriptions.confirmation_throttle.per_mailbox', 5);
        $mailboxWindow = (int) config('marketing.subscriptions.confirmation_throttle.per_mailbox_window_minutes', 1440) * 60;

        $mailboxHits = $limiter->hit($this->key('mailbox', '', $mailbox), $mailboxWindow);

        if ($mailboxHits < 1) {
            return self::WITHHELD_NO_COUNTER;
        }

        if ($mailboxHits > $mailboxMax) {
            return self::WITHHELD_PER_MAILBOX;
        }

        return self::ALLOWED;
    }

    /**
     * A limiter on a store that can actually count, or null.
     *
     * Built here rather than taken from the `RateLimiter` facade, because that
     * facade is wired to `cache.default` — a value this addon does not choose
     * and cannot assert. `subscriptions.confirmation_throttle.store` names the
     * store explicitly; left null it uses the application default and then
     * checks it, which is the part that matters.
     */
    protected function limiter(): ?RateLimiter
    {
        $name = config('marketing.subscriptions.confirmation_throttle.store');

        try {
            $repository = Cache::store($name);
            $store = $repository->getStore();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        foreach (static::ATOMIC_STORES as $atomic) {
            if ($store instanceof $atomic) {
                return new RateLimiter($repository);
            }
        }

        Log::warning(
            'Marketing withholds every double-opt-in mail: the cache store ['.$store::class.'] cannot '
            .'count atomically, so the per-recipient limit would not be one. Point '
            .'marketing.subscriptions.confirmation_throttle.store at redis, memcached or database.',
            ['store' => $store::class]
        );

        return null;
    }

    protected function key(string $tier, string $scope, string $mailbox): string
    {
        return implode(':', array_filter(['marketing', 'doi', $tier, $scope, $mailbox]));
    }
}
