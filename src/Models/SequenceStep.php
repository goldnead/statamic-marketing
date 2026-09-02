<?php

namespace Goldnead\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mail of a sequence, and the gap before it.
 *
 * @property int $id
 * @property int $sequence_id
 * @property int $position 1-based. The node keys of the generated automation
 *                         are derived from it, which is what lets a re-save
 *                         keep the keys a running enrollment is waiting on.
 * @property string $template An `et_templates` slug.
 * @property string|null $subject_override Null = the template's own subject.
 * @property int $delay_amount
 * @property string $delay_unit One of {@see self::UNITS}.
 */
class SequenceStep extends Model
{
    public const UNITS = ['minutes', 'hours', 'days'];

    protected $table = 'marketing_sequence_steps';

    protected $guarded = [];

    protected $casts = [
        'position' => 'integer',
        'delay_amount' => 'integer',
    ];

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class, 'sequence_id');
    }

    public function hasDelay(): bool
    {
        return $this->delay_amount > 0;
    }
}
