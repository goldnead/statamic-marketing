<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $uuid The subscription's stable public identity. Written
 *                        once on create (see booted()) and never rewritten —
 *                        which is why the A/B assignment seeds on it rather
 *                        than on the correctable email or the row id.
 * @property int|null $brand_id Nullable in the schema, stamped by HasBrand on
 *                              create. Rows that predate brand-context were
 *                              backfilled, but the column never became NOT NULL.
 * @property string $token The subscriber's own key to every page they can reach
 *                         without a session: confirm, unsubscribe, and the
 *                         preference centre. NOT NULL and unique in the schema,
 *                         filled on create like $uuid. Declared here rather than
 *                         baselined per call site, because six files read it and
 *                         Support\PreferenceLink hands it to two addons.
 */
class Subscription extends Model
{
    use HasBrand;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_COMPLAINED = 'complained';

    protected $table = 'marketing_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'subscribed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscription) {
            $subscription->uuid ??= (string) Str::uuid();
            $subscription->token ??= Str::random(48);
            $subscription->email_normalized = EmailNormalizer::normalize((string) $subscription->email);
            $subscription->uniqueness_key = static::uniquenessKeyFor(
                (string) $subscription->list_handle,
                (string) $subscription->email,
            );
        });

        static::updating(function (self $subscription) {
            if ($subscription->isDirty('email')) {
                $subscription->email_normalized = EmailNormalizer::normalize((string) $subscription->email);
            }

            if ($subscription->isDirty(['email', 'list_handle'])) {
                $subscription->uniqueness_key = static::uniquenessKeyFor(
                    (string) $subscription->list_handle,
                    (string) $subscription->email,
                );
            }
        });
    }

    /**
     * The consent identity of a subscription: one address, on one list.
     *
     * The unique index is built on this rather than on the two columns it is
     * derived from. `(brand_id, list_handle, email_normalized)` needed 2048 of
     * InnoDB's 3072 bytes — two `varchar(255)` at four bytes per character
     * under utf8mb4 — so the index MySQL would have built was two thirds spent
     * before anything was added to it. A hash is 256 bytes and covers every
     * character of both values; a prefix index would have fit just as well and
     * would have declared two lists whose handles share a prefix to be one.
     *
     * `brand_id` deliberately stays out of the hash and remains a column of
     * the index, so the tenant boundary is still legible in the schema.
     */
    public static function uniquenessKeyFor(string $listHandle, string $email): string
    {
        return hash('sha256', implode("\0", [
            $listHandle,
            EmailNormalizer::normalize($email),
        ]));
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function isSubscribed(): bool
    {
        return $this->status === self::STATUS_SUBSCRIBED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopeSubscribed($query)
    {
        return $query->where('status', self::STATUS_SUBSCRIBED);
    }

    public function scopeForList($query, string $listHandle)
    {
        return $query->where('list_handle', $listHandle);
    }

    public function displayName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: $this->email;
    }
}
