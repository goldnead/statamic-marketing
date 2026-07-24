<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

class EmailTemplateRecord extends Model
{
    use HasBrand;

    protected $table = 'marketing_templates';

    protected $guarded = [];
}
