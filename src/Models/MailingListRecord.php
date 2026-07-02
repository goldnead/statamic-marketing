<?php

namespace Goldnead\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

class MailingListRecord extends Model
{
    protected $table = 'marketing_lists';

    protected $guarded = [];

    protected $casts = [
        'double_opt_in' => 'boolean',
    ];
}
