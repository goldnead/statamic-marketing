<?php

namespace Goldnead\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Message extends Model
{
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

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
