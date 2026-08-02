<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string|null $variant The A/B bucket this message was assigned to
 *                                ('a' / 'b'), decided once at the audience
 *                                snapshot. NULL means the message took no part
 *                                in an A/B test.
 */
class Message extends Model
{
    use HasBrand;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BOUNCED = 'bounced';

    protected $table = 'marketing_messages';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'first_clicked_at' => 'datetime',
        'last_clicked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function events()
    {
        return $this->hasMany(MessageEvent::class);
    }

    public function scopeForCampaign($query, string $campaignHandle)
    {
        return $query->where('campaign_handle', $campaignHandle);
    }

    /**
     * The messages of one A/B variant.
     *
     * This is the join the whole feature rests on: opens, clicks, bounces and
     * unsubscribes all hang off a message, so narrowing messages to a variant
     * narrows every one of those with them.
     */
    public function scopeForVariant($query, string $variant)
    {
        return $query->where('variant', $variant);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
