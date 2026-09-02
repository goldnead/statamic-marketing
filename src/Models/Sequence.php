<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Marketing\Sequences\SequenceSync;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mail series with a trigger: the sequence as an editor sees it.
 *
 * What runs is not this row. Saving a sequence writes (or rewrites) exactly
 * the automation one would otherwise build by hand — trigger, then for every
 * step a delay and a `marketing.send_email` node — and `automation_id` points
 * at it. See {@see SequenceSync}.
 *
 * @property int $id
 * @property string $handle
 * @property string $title
 * @property int|null $brand_id
 * @property string $trigger
 * @property array<string, mixed>|null $trigger_config
 * @property string|null $list_handle
 * @property bool $enabled
 * @property int|null $automation_id
 * @property array<string, mixed>|null $meta
 * @property-read Collection<int, SequenceStep> $steps
 */
class Sequence extends Model
{
    use HasBrand;

    protected $table = 'marketing_sequences';

    protected $guarded = [];

    protected $casts = [
        'trigger_config' => 'array',
        'meta' => 'array',
        'enabled' => 'boolean',
        'automation_id' => 'integer',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class, 'sequence_id')->orderBy('position');
    }

    /**
     * The `created_by` marker the managed automation carries, so the other
     * side can tell a generated automation from a hand-built one.
     */
    public function managedBy(): string
    {
        return 'marketing.sequence:'.$this->handle;
    }
}
