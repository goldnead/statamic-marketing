<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $variant_subject Subject line for variant B. NULL is
 *                                        the meaningful default: a campaign
 *                                        without one is not an A/B test.
 */
class CampaignRecord extends Model
{
    use HasBrand;

    protected $table = 'marketing_campaigns';

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
    ];
}
