<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

class MessageEvent extends Model
{
    use HasBrand;

    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    public const TYPE_BOUNCE = 'bounce';

    public const TYPE_COMPLAINT = 'complaint';

    public const TYPE_UNSUBSCRIBE = 'unsubscribe';

    protected $table = 'marketing_message_events';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
