<?php

namespace Goldnead\Marketing\Support;

/**
 * What of a clicked URL may be written down.
 *
 * A link to a page behind the login is rewritten into a signed login link
 * (statamic-identity-contracts, `/!/autologin?…&signature=…`) before the click
 * counter wraps it. The counter must forward the reader to that URL, but it must
 * not keep it: stored in an event row, a webhook payload or a report, a login
 * link that is still valid is a credential lying around in plain sight.
 * For such a URL only the page it leads to is kept, the `to` value.
 * Every other URL is returned as it came.
 */
final class ClickTarget
{
    public static function forStorage(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $parts = parse_url($url);

        if ($parts === false || ! self::isLoginLink((string) ($parts['path'] ?? ''))) {
            return $url;
        }

        parse_str((string) ($parts['query'] ?? ''), $query);

        $to = $query['to'] ?? null;

        if (is_string($to) && str_starts_with($to, '/') && ! str_starts_with($to, '//')) {
            return $to;
        }

        // A login link without a readable target: the path alone, never the query.
        return (string) ($parts['path'] ?? '');
    }

    protected static function isLoginLink(string $path): bool
    {
        $route = trim((string) config('identity-contracts.autologin.route', '!/autologin'), '/');

        // No route configured: nothing is a login link (an empty route would match every URL).
        if ($route === '') {
            return false;
        }

        // Doubled slashes ("//!/autologin") are read as one by the web server.
        // Percent-encoding too: the router decodes the path first ("/%21/auto%6Cogin" is the route).
        $path = trim((string) preg_replace('#/{2,}#', '/', rawurldecode($path)), '/');

        return $path === $route;
    }
}
