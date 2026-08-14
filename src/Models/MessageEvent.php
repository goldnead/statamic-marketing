<?php

namespace Goldnead\Marketing\Models;

use Goldnead\BrandContext\Concerns\HasBrand;
use Goldnead\Marketing\Support\MachineOpen;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing that happened to one message.
 *
 * @property int $id
 * @property int $message_id
 * @property string $type One of the TYPE_* constants below.
 * @property bool $machine Was this open a machine loading the image, rather
 *                         than a person reading? Written for opens only, and
 *                         only ever as a verdict — the user agent it was
 *                         derived from is not stored, and neither is the IP.
 *                         {@see MachineOpen} says
 *                         how far this can be known, which is not very. The
 *                         counters on the message deliberately ignore it: they
 *                         are what every earlier campaign was measured with.
 * @property string|null $url The link that was followed, on a click event. The
 *                            destination as it stood in the campaign HTML, so
 *                            it is the same string for every recipient and the
 *                            report can group by it.
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at When it happened. On an unsubscribe or a
 *                                   click this IS the timestamp the report
 *                                   shows — there is no other column for it.
 * @property-read Message|null $message The delivery this happened to. Null only
 *                                      where the message has been deleted out
 *                                      from under it.
 */
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
        'machine' => 'boolean',
        'meta' => 'array',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
