<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        });

        static::updating(function (self $subscription) {
            if ($subscription->isDirty('email')) {
                $subscription->email_normalized = EmailNormalizer::normalize((string) $subscription->email);
            }
        });
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
