<?php

namespace Goldnead\Marketing\Http\Controllers\Cp;

use Goldnead\Marketing\Support\HandleOwnership;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * The other brand already holding this handle, or null.
     *
     * Marketing handles are unique across all brands — the public subscribe
     * endpoint derives the brand from the list handle it is given, and that
     * only holds while a handle has one owner. The storage refuses a duplicate
     * either way (a unique index, or the flat store's own guard), but a
     * refusal that reaches an editor as a 500 explains nothing. Asked here,
     * it comes back as a message at the handle field.
     *
     * Always null in single-brand mode: there is no other brand.
     */
    protected function handleOwnedElsewhere(string $type, string $handle): ?string
    {
        return app(HandleOwnership::class)
            ->ownerOtherThanCurrent($type, $handle)
            ?->name;
    }

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
