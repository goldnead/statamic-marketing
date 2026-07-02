<?php

namespace Goldnead\Marketing\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A plain Laravel auth user, exactly like a stock App\Models\User on a
 * Statamic site running the *eloquent* users repository. It deliberately
 * has NONE of Statamic's user methods (hasPermission, isSuper, id(), ...) —
 * only what Laravel's Authenticatable/Authorizable contracts provide.
 */
class PlainAuthUser extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];
}
