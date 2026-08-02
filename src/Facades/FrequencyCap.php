<?php

namespace Goldnead\Marketing\Facades;

use Goldnead\Marketing\Contracts\FrequencyCap as FrequencyCapContract;
use Goldnead\Marketing\Contracts\MailClass;
use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static int limit()
 * @method static int windowHours()
 * @method static bool allows(string $email, MailClass $class, ?int $brandId = null)
 * @method static void record(string $email, MailClass $class, ?int $brandId = null, ?string $reference = null)
 * @method static int countInWindow(string $email, ?int $brandId = null)
 *
 * The accessor is the contract's own class name, not a short string key. A
 * container binding under this addon's slug would sit next to the framework's
 * own aliases, and a sibling in this family has already overwritten Laravel's
 * event dispatcher that way.
 *
 * @see FrequencyCapContract
 */
class FrequencyCap extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FrequencyCapContract::class;
    }
}
