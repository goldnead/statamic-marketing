<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Die Bewertung einer Ausgabe durch eine Empfaengerin.
 *
 * @property int $id
 * @property int|null $brand_id Von der Zustellung uebernommen, damit die Stimme
 *                              in derselben Marke liegt wie die Mail.
 * @property int $message_id Die Zustellung. Eindeutig: eine Stimme je Mail, die
 *                           letzte Wahl gilt.
 * @property string $campaign_handle
 * @property string $choice Eine der CHOICES.
 * @property string|null $reason Der freiwillige Satz dazu. Null, solange
 *                               niemand etwas dazu geschrieben hat.
 * @property Carbon|null $created_at Wann zum ersten Mal gewaehlt wurde.
 * @property Carbon|null $updated_at Wann zuletzt gewaehlt oder begruendet wurde.
 * @property-read Message|null $message
 */
class Rating extends Model
{
    use HasBrand;

    public const GOOD = 'good';

    public const OK = 'ok';

    public const BAD = 'bad';

    /**
     * Die drei Stufen, in der Reihenfolge, in der sie in der Mail stehen und im
     * Bericht gezaehlt werden. Feste Woerter: sie stehen in signierten Links,
     * und eine vierte Stufe waere ein Umbau der Mail, kein Konfigurationswert.
     *
     * @var list<string>
     */
    public const CHOICES = [self::GOOD, self::OK, self::BAD];

    /** Laenger muss eine Rueckmeldung nicht sein; mehr waere ein Brief. */
    public const REASON_MAX = 2000;

    protected $table = 'marketing_ratings';

    protected $guarded = [];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @param  Builder<Rating>  $query
     * @return Builder<Rating>
     */
    public function scopeForCampaign(Builder $query, string $handle): Builder
    {
        return $query->where('campaign_handle', $handle);
    }
}
