<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Sending\ConfirmationResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $uuid The subscription's stable public identity. Written
 *                        once on create (see booted()) and never rewritten —
 *                        which is why the A/B assignment seeds on it rather
 *                        than on the correctable email or the row id.
 * @property int|null $brand_id Nullable in the schema, stamped by HasBrand on
 *                              create. Rows that predate brand-context were
 *                              backfilled, but the column never became NOT NULL.
 * @property string $email_normalized The address reduced to its comparable
 *                                    form, written on every save (see
 *                                    booted()). This — not the row id and not
 *                                    the list — is what "the same person" means
 *                                    to the consent unique, the suppression
 *                                    gate and the frequency cap alike.
 * @property string $token The subscriber's LONG-LIVED key to the pages they can
 *                         reach without a session: unsubscribe and the
 *                         preference centre. NOT NULL and unique in the schema,
 *                         filled on create like $uuid. Declared here rather than
 *                         baselined per call site, because six files read it and
 *                         Support\PreferenceLink hands it to two addons. It is
 *                         printed in the footer of every campaign, which is why
 *                         it may not also be the thing that grants consent —
 *                         see $confirmation_token.
 * @property string $confirmation_token The double-opt-in key, and nothing
 *                                      else. Rotated with every confirmation mail and spent
 *                                      on first use. Separate from $token because the two
 *                                      have opposite lifetimes: one has to keep working for
 *                                      years so a reader can always get out, the other has
 *                                      to stop working the moment it has been used or the
 *                                      subscription has ended. NOT NULL and unique, so a
 *                                      row always carries one — which is not the same as
 *                                      having a live link; see $confirmation_sent_at.
 * @property Carbon|null $confirmation_sent_at When the
 *                                             current confirmation link was actually MAILED, and
 *                                             therefore the only proof that it exists outside this
 *                                             database. NULL means no link was ever issued for
 *                                             this row and nothing about it is redeemable. Also
 *                                             the clock for its expiry.
 * @property Carbon|null $confirmation_used_at When it was
 *                                             spent. Set exactly once, by a conditional update, so
 *                                             that "single use" survives two clicks arriving
 *                                             together.
 * @property string $email The address as the subscriber wrote it. What a mail
 *                         is addressed to; $email_normalized is what it is
 *                         compared by, and the two are not interchangeable.
 * @property string $status One of the STATUS_* constants below. Declared here
 *                          because four files branch on it and the analyser
 *                          was carrying an ignore entry for each one.
 * @property string $list_handle The list this subscription belongs to, which is
 *                               where its consent comes from.
 * @property Carbon|null $subscribed_at When this subscription started. Set on
 *                                      sign-up, kept through a later
 *                                      unsubscribe — it is when consent was
 *                                      given, not when it was last true.
 * @property Carbon|null $unsubscribed_at When it ended, or null while it has
 *                                        not. The pair is the consent record,
 *                                        which is why neither is cleared.
 * @property string|null $contact_uuid The LeadHub contact, once the
 *                                     subscription has been confirmed and
 *                                     synced. Null on a pending sign-up — a
 *                                     shortcut to the person, never the
 *                                     identity itself.
 */
class Subscription extends Model
{
    use HasBrand;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_COMPLAINED = 'complained';

    /**
     * What happened to the confirmation mail for THIS request, set by
     * `SubscriptionService::subscribe()` and never persisted.
     *
     * A declared property rather than an attribute, deliberately: Eloquent's
     * `__set` only fires for undeclared ones, so this can never end up in
     * `getAttributes()`, in `toArray()`, in a mass assignment or in a column
     * that does not exist. It belongs to the request, not to the row — the same
     * address subscribing again tomorrow gets a different answer, and a row
     * read back out of the database has no answer at all.
     *
     * Null on any subscription that did not come from a sign-up call.
     */
    public ?ConfirmationResult $confirmation = null;

    protected $table = 'marketing_subscriptions';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'subscribed_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'confirmation_sent_at' => 'datetime',
        'confirmation_used_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscription) {
            $subscription->uuid ??= (string) Str::uuid();
            $subscription->token ??= Str::random(48);
            // Always present, like $token, so that the column can be NOT NULL
            // and its unique can mean something. A value here is not a live
            // link: `confirmation_sent_at` is what says one was ever issued,
            // and until then this string has never left the database.
            $subscription->confirmation_token ??= Str::random(48);
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

    /**
     * Arm a fresh double-opt-in link, invalidating any previous one.
     *
     * Called only where a confirmation mail is actually about to go out, and
     * never merely because somebody submitted the form. The difference
     * matters: rotating on every submission would let a stranger type a
     * victim's address into the public form and kill the valid link the victim
     * received five minutes ago, turning an abuse limit into a way to break
     * the very sign-up it protects. The newest link that was actually
     * DELIVERED is the live one.
     */
    public function issueConfirmationToken(): string
    {
        $this->forceFill([
            'confirmation_token' => Str::random(48),
            'confirmation_sent_at' => now(),
            'confirmation_used_at' => null,
        ])->save();

        return (string) $this->confirmation_token;
    }

    /**
     * A confirmation link that was never used goes stale.
     *
     * The unused pending row is the one case the rest of the design does not
     * reach: it is not spent, and the subscription has not ended, so nothing
     * else would ever retire its link. Without a clock it stays valid forever,
     * which is the "the token never rotates" complaint in its last remaining
     * form. Consent that was requested and left unanswered for a week is not
     * consent that should be grantable by a link scanner next year.
     */
    public function confirmationHasExpired(): bool
    {
        $hours = (int) config('marketing.subscriptions.confirmation_ttl_hours', 168);

        if ($hours <= 0) {
            return false;
        }

        if (! $this->confirmation_sent_at) {
            return false;
        }

        return $this->confirmation_sent_at->addHours($hours)->isPast();
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
