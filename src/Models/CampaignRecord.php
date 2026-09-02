<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Marketing\Contracts\MailClass;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $variant_subject Subject line for variant B. NULL is
 *                                        the meaningful default: a campaign
 *                                        without one is not an A/B test.
 * @property bool $in_archive Released to the public web archive. FALSE is the
 *                            meaningful default — see the migration.
 * @property int $ab_share Share of the audience an A/B test runs on, in
 *                         percent. 0 = the whole audience is split; see
 *                         Data\Campaign::$abShare.
 * @property string $mail_class The frequency-cap classification, one of
 *                              {@see MailClass}.
 *                              Defaults to `marketing`, which is the capped one.
 */
class CampaignRecord extends Model
{
    use HasBrand;

    protected $table = 'marketing_campaigns';

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
        'in_archive' => 'boolean',
    ];
}
