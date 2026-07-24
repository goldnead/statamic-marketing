<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

class MailingListRecord extends Model
{
    use HasBrand;

    protected $table = 'marketing_lists';

    protected $guarded = [];

    protected $casts = [
        'double_opt_in' => 'boolean',
    ];
}
