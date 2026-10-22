<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\TagLinks;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The landing of a tag link, in two halves.
 *
 * The GET shows a page and changes nothing. Mail gateways, virus scanners and
 * link previews fetch every URL in an incoming message, and a link that tagged
 * on GET would tag everybody whose mail passed through one. The POST sets the
 * tags and moves the reader on; it is sent by the page itself (script) or by
 * its button.
 *
 * Both halves are behind the signature middleware, so the key and the
 * subscription are the ones the mail was rendered with. They are checked again
 * here anyway: the signature says the URL was ours, not that what it names
 * still exists.
 */
class TagLinkController extends Controller
{
    public function show(Request $request, string $marketingTagKey, TagLinks $links)
    {
        $this->resolve($request, $marketingTagKey, $links);

        return response()->view('marketing::tag-link', [
            // The address this page was opened on, signature included. The
            // route is the same for both verbs, so the same signature holds.
            'action' => $request->fullUrl(),
        ]);
    }

    public function apply(Request $request, string $marketingTagKey, TagLinks $links)
    {
        [$definition, $subscription] = $this->resolve($request, $marketingTagKey, $links);

        $links->apply($subscription, $definition['tags']);

        if ($definition['thanks'] !== null) {
            return response()->view('marketing::tag-link-thanks', ['thanks' => $definition['thanks']]);
        }

        return redirect()->away($this->withAutoLogin((string) $definition['redirect'], $subscription, $request));
    }

    /**
     * The redirect, through the login link when the subscriber has an account
     * and the target is a page behind the login. A tag link is a link in a
     * mail, and the click on it must land where the other links of that mail
     * land.
     */
    protected function withAutoLogin(string $target, Subscription $subscription, Request $request): string
    {
        $class = '\\Goldnead\\IdentityContracts\\AutoLogin\\AutoLogin';

        if (! config('marketing.auto_login', true) || ! class_exists($class)) {
            return $target;
        }

        try {
            $links = app($class);

            if (! $links->enabled() || ! $this->isYoung($request)) {
                return $target;
            }

            return $links->linkFor($subscription->email, $target) ?? $target;
        } catch (\Throwable $e) {
            report($e);

            return $target;
        }
    }

    /**
     * A tag link never expires, and every click would hand out a login link
     * with a lifetime of its own. So the login is only offered while the tag
     * link is as young as a login link in the same mail would be: the signed
     * `iat` of the URL says when it was printed. An older link (a mail dug out of
     * an archive, a forwarded copy) still tags and redirects, it just does not
     * log anybody in; a link without `t` (printed before this existed) the same.
     *
     * Known limit: inside that window a tag link is a login for its person
     * like the other links of its mail, and unlike them a password change
     * does not end it (it carries no account state). The window is the
     * lifetime of the login links of that mail, 14 days by default.
     */
    protected function isYoung(Request $request): bool
    {
        $printed = (int) $request->query(TagLinks::ISSUED, 0);
        $days = max(1, (int) config('identity-contracts.autologin.ttl_days', 14));
        $now = now()->getTimestamp();

        return $printed > 0 && $printed <= $now + 300 && $printed >= $now - $days * 86400;
    }

    /**
     * @return array{0: array{tags: list<string>, redirect: ?string, thanks: ?string, list: ?string}, 1: Subscription}
     */
    protected function resolve(Request $request, string $key, TagLinks $links): array
    {
        $definition = $links->definition($key);

        abort_unless($definition !== null, 404);

        $uuid = (string) $request->query(TagLinks::SUBSCRIPTION, '');

        $subscription = $uuid !== '' ? Subscription::query()->where('uuid', $uuid)->first() : null;

        abort_unless($subscription !== null, 404);

        // A definition that names its list only serves subscriptions of it.
        abort_if($definition['list'] !== null && $subscription->list_handle !== $definition['list'], 404);

        return [$definition, $subscription];
    }
}
