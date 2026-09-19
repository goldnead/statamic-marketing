<?php

namespace Goldnead\Marketing\Repositories\Eloquent;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes the brand onto a new row instead of hoping an event does it.
 *
 * `HasBrand` from statamic-brand-context fills `brand_id` in a `creating`
 * hook. `brand_id` is NOT NULL. A muted event dispatcher is therefore not an
 * edge case here, it is a failed insert — and muting the dispatcher is
 * ordinary: Laravel's own `WithoutModelEvents` on a seeder does exactly that,
 * which is why a host seeding its shipped lists through `DatabaseSeeder` hit
 * `NOT NULL constraint failed: marketing_lists.brand_id` on 19.09.2026 and
 * took every seeder after it down with the aborted run.
 *
 * So the repositories stamp the brand themselves. The hook stays — it still
 * serves every model created outside a repository — and this is simply not
 * left to it.
 *
 * Only on create. An update must never move an existing row to the brand that
 * happens to be current: on a multi-brand host that would hand one brand's
 * campaign to another, which is the one thing brand scoping exists to prevent.
 */
trait StampsTheBrandItself
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $values
     */
    protected function speichereMitMarke(string $modelClass, string $handle, array $values): Model
    {
        /** @var Model $record */
        $record = $modelClass::query()->firstOrNew(['handle' => $handle]);

        if (! $record->exists && method_exists($record, 'getBrandColumn')) {
            $spalte = $record->getBrandColumn();

            if (empty($record->{$spalte}) && app()->bound('brand-context')) {
                $record->{$spalte} = app('brand-context')->currentId();
            }
        }

        $record->fill($values)->save();

        return $record;
    }
}
