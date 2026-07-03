<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * Abort with 403 unless the current user holds $permission.
     *
     * Permission checks go through Laravel's Gate ($user->can()) instead of
     * Statamic's User::hasPermission(): Statamic registers a Gate::after
     * hook that resolves the Statamic user via User::fromUser() and
     * short-circuits super users, so can() is correct for BOTH the file
     * and the eloquent users repository. Calling hasPermission()/isSuper()
     * on the raw auth user crashes on eloquent-driver sites where the
     * authenticated model is a plain App\Models\User.
     */
    protected function authorizeOrFail(Request $request, string $permission): void
    {
        if (! $this->userCan($request, $permission)) {
            abort(403);
        }
    }

    protected function userCan(Request $request, string $permission): bool
    {
        return (bool) $request->user()?->can($permission);
    }
}
