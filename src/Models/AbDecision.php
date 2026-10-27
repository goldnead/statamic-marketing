<?php

namespace Goldnead\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The claim on the winner decision of one A/B test. See the migration for why
 * it is a table and not a lock.
 *
 * @property string $claim_key `<brand id>|<campaign handle>`, unique
 * @property string $campaign_handle
 * @property string $winner
 * @property string $reason
 * @property array<string, mixed>|null $results
 * @property Carbon $decided_at
 * @property Carbon|null $dispatched_at When the winner pass was last queued
 */
class AbDecision extends Model
{
    protected $table = 'marketing_ab_decisions';

    protected $guarded = [];

    protected $casts = [
        'results' => 'array',
        'decided_at' => 'datetime',
        'dispatched_at' => 'datetime',
    ];
}
