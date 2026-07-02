<?php

namespace Goldnead\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignRecord extends Model
{
    protected $table = 'marketing_campaigns';

    protected $guarded = [];

    protected $casts = [
        'scheduled_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
    ];
}
