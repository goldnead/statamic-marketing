<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string|null $variant The A/B bucket this message was assigned to
 *                                ('a' / 'b'), decided once at the audience
 *                                snapshot. NULL means the message took no part
 *                                in an A/B test.
 * @property string $uuid The message's public identity — what a tracking URL
 *                        carries and what a log line names.
 * @property string $email The address this message was addressed to, kept even
 *                         if the subscription is later corrected or deleted.
 * @property string|null $campaign_handle The campaign this delivery belongs to.
 * @property int $opens How often the tracking pixel was fetched for this
 *                      message — by anybody, person or prefetcher. The report
 *                      compares this against every earlier campaign, so it
 *                      counts everything and always has;
 *                      `marketing_message_events.machine` is where the
 *                      distinction lives.
 * @property int $clicks How often a tracked link in this message was followed.
 * @property Carbon|null $sent_at When this message left, or null while it has not.
 * @property-read Subscription|null $subscription The
 *               sign-up this delivery was made against.
 *                                        NULL means it belongs to none — a
 *                                        template send, which names its mail in
 *                                        `template_handle` instead. Exactly one
 *                                        of the two is ever set.
 * @property string|null $template_handle The managed email template this mail
 *                                        was, for a send that had no campaign.
 * @property int|null $brand_id Denormalised from the subscription at the
 *                              audience snapshot. It is what a queue worker
 *                              hands the frequency cap, because a worker has no
 *                              brand context of its own.
 * @property int $cap_deferrals How often the frequency cap has pushed this
 *                              message back. Bounded by config; when it runs
 *                              out the message becomes STATUS_CAPPED.
 * @property Carbon|null $cap_deferred_until When the next
 *                                           attempt is due.
 */
class Message extends Model
{
    use HasBrand;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BOUNCED = 'bounced';

    /**
     * Held back by the frequency cap until the deferral budget ran out, then
     * discarded. Distinct from `skipped` on purpose: skipped means the
     * recipient may not be mailed, capped means they may and were not. The two
     * need different answers when somebody asks why a mail never arrived.
     */
    public const STATUS_CAPPED = 'capped';

    protected $table = 'marketing_messages';

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'first_clicked_at' => 'datetime',
        'last_clicked_at' => 'datetime',
        'cap_deferred_until' => 'datetime',
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

    /**
     * The messages of one campaign.
     *
     * Unchanged, and deliberately blind to template sends: `campaign_handle` is
     * NULL on those, and `where campaign_handle = ?` never matches NULL on any
     * engine. A template send is not part of a campaign's numbers, so every
     * report built on this scope stayed correct without being touched.
     */
    public function scopeForCampaign($query, string $campaignHandle)
    {
        return $query->where('campaign_handle', $campaignHandle);
    }

    /** The messages sent from one managed email template. */
    public function scopeForTemplate($query, string $templateHandle)
    {
        return $query->where('template_handle', $templateHandle);
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
