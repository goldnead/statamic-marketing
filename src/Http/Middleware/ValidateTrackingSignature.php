<?php

namespace Goldnead\Marketing\Http\Middleware;

use Closure;
use Goldnead\Marketing\Support\TrackingParameters;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\ValidateSignature;

/**
 * `signed`, minus the parameters a sending platform adds in transit.
 *
 * Laravel's own way of writing this is
 * `ValidateSignature::absolute($ignored)` in the route file, which bakes the
 * list into the middleware string at registration time. That does not work
 * here, for two reasons that are both about *when* the list is read:
 *
 *   1. This addon merges its config from `bootAddon()`, which Statamic runs
 *      after the route files are loaded. At registration time the list would
 *      still be empty.
 *   2. `route:cache` serialises middleware strings. A list baked in at
 *      registration would survive into the cache, and a host who later edited
 *      the published config would see nothing happen.
 *
 * So the list is read per request instead. The arguments the router may pass
 * are deliberately dropped: the ignore list has exactly one source, the config,
 * and it passes through `TrackingParameters` — which is where `url`, `expires`
 * and `signature` are refused. Nothing between here and the route can widen it.
 */
class ValidateTrackingSignature extends ValidateSignature
{
    /**
     * @param  Request  $request
     * @return Response
     *
     * @throws InvalidSignatureException
     */
    public function handle($request, Closure $next, ...$args)
    {
        /*
         * Handed over on the property rather than spread into the parent call.
         * `parent::handle()` takes its ignore list as variadic middleware
         * arguments but declares them `array|null` — the annotation describes
         * the collected `$args`, not one of them, so passing the parameter
         * names it actually wants is a type error to any analyser reading that
         * signature. The property is the same list at the same moment:
         * `parseArguments()` merges it with the arguments before either is
         * used, and this class has no `except` property to displace it.
         */
        $this->ignore = TrackingParameters::ignored();

        return parent::handle($request, $next);
    }
}
