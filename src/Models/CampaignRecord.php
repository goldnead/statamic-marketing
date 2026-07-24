<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

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
