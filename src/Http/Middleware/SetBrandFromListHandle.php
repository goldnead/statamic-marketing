<?php

namespace Goldnead\Marketing\Http\Middleware;

use Closure;
use Goldnead\Marketing\Support\HandleOwnership;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public-route middleware: derives the brand from the list handle the request
 * names.
 *
 * brand-context's own SetBrandFromRouteValue does this from an Eloquent model,
 * which works for subscriptions and messages — those are always database rows
 * — but not for lists: under the flat driver a list is a file, and there is no
 * model to query. The subscribe endpoint would then run with no brand under
 * multi-brand, the fail-closed store would hide the very list the form names,
 * and every public sign-up on a flat multi-brand install would 404.
 *
 * So the lookup goes through HandleOwnership, which answers for both drivers.
 * The guarantees are the ones brand-context established and are deliberately
 * unchanged here:
 *
 * 1. The handle belongs to exactly one brand. Two claimants throw instead of
 *    being guessed between; the storage refuses to create the second claim in
 *    the first place.
 * 2. Nothing is aborted. No owner means no brand set, the store stays closed,
 *    and the controller produces exactly the response it produced before.
 * 3. The brand is always set explicitly, never inherited. The manager is a
 *    singleton, so in a long-lived process an unknown handle would otherwise
 *    run under the previous visitor's brand.
 *
 * Single-brand installs pass straight through.
 */
class SetBrandFromListHandle
{
    /**
     * @param  string  $parameter  the route parameter (or input field) holding the handle
     * @param  string  $type  which kind of definition the handle names — one of
     *                        the HandleOwnership constants. Campaign handles are
     *                        unique across brands for the same reason list
     *                        handles are, and the newsletter archive addresses a
     *                        campaign by handle from a request with no session,
     *                        so it needs the identical derivation. Defaults to
     *                        lists, which is what every existing use of this
     *                        middleware means.
     */
    public function handle(
        Request $request,
        Closure $next,
        string $parameter = 'list',
        string $type = HandleOwnership::LISTS,
    ): Response {
        $manager = app('brand-context');

        if (! $manager->multiBrandEnabled()) {
            return $next($request);
        }

        // Route parameter first, then input, so a posted form field naming its
        // list works exactly like a segment in the URL would.
        $value = $request->route($parameter) ?? $request->input($parameter);

        $brand = app(HandleOwnership::class)->brandFor(
            $type,
            is_string($value) ? $value : null,
        );

        $brand ? $manager->setCurrent($brand) : $manager->forget();

        return $next($request);
    }
}
