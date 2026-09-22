<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $handle
 * @property string $name
 * @property string $type `html` or `blocks` — how the layout was written, and
 *                        fixed once the row exists.
 * @property string|null $html The one thing the renderer, the send, the
 *                             snapshot and the archive ever read. **Derived
 *                             for a block layout**: the translator writes it
 *                             from `blocks` on every save, so a hand edit to
 *                             this column is gone at the next one.
 * @property string|null $blocks The building blocks as JSON, and the source of
 *                               truth where `type` is `blocks`. Null otherwise.
 * @property int|null $brand_id
 */
class EmailTemplateRecord extends Model
{
    use HasBrand;

    protected $table = 'marketing_templates';

    protected $guarded = [];
}
