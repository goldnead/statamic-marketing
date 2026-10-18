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

        return redirect()->away((string) $definition['redirect']);
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
