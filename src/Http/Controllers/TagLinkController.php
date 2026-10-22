<?php

namespace Goldnead\Marketing\Http\Controllers;

use Goldnead\Marketing\Models\Message;
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

        return redirect()->away($this->withAutoLogin((string) $definition['redirect'], $subscription));
    }

    /**
     * The redirect, through the login link when the subscriber has an account
     * and the target is a page behind the login. A tag link is a link in a
     * mail, and the click on it must land where the other links of that mail
     * land.
     */
    protected function withAutoLogin(string $target, Subscription $subscription): string
    {
        $class = '\\Goldnead\\IdentityContracts\\AutoLogin\\AutoLogin';

        if (! config('marketing.auto_login', true) || ! class_exists($class)) {
            return $target;
        }

        try {
            $links = app($class);

            if (! $links->enabled() || ! $this->mailedRecently($subscription)) {
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
     * with a lifetime of its own. So the login is only offered while the link
     * is as young as a login link in the mail would be: the person was mailed
     * within the login link's lifetime. A mail dug out of an old archive still
     * tags and redirects, it just does not log anybody in.
     */
    protected function mailedRecently(Subscription $subscription): bool
    {
        $days = max(1, (int) config('identity-contracts.autologin.ttl_days', 14));

        return Message::query()
            ->where('subscription_id', $subscription->id)
            ->where('sent_at', '>=', now()->subDays($days))
            ->exists();
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
