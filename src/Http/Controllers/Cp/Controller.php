<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * Abort with 403 unless the current user holds $permission. Super users
     * short-circuit to true — Statamic's file-driver hasPermission() does not
     * include named permissions for supers.
     */
    protected function authorizeOrFail(Request $request, string $permission): void
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if (method_exists($user, 'isSuper') && $user->isSuper()) {
            return;
        }

        if (! $user->hasPermission($permission)) {
            abort(403);
        }
    }

    protected function userCan(Request $request, string $permission): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuper') && $user->isSuper()) {
            return true;
        }

        return $user->hasPermission($permission);
    }
}
