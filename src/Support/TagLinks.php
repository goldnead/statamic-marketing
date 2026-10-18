<?php

namespace Goldnead\Marketing\Support;

use Goldnead\Leadhub\Facades\LeadHub;
use Goldnead\Leadhub\Support\EmailNormalizer;
use Goldnead\Marketing\Models\Subscription;
use Illuminate\Support\Facades\URL;

/**
 * Named links that put tags on the person who clicks them.
 *
 * The definitions live in `marketing.tag_links`, keyed by a name the mail
 * author types: `{{ marketing_tag_link:wants-freebie }}`. This class is the one
 * place that knows what such a definition is, what the link looks like and what
 * pressing it does, so that the Antlers tag, the two routes and the tests all
 * ask the same questions.
 *
 * **What a link carries.** The key (in the path) and the subscription's uuid
 * (`s`), both under one signature. No address: a mail that is forwarded must
 * not hand its recipient's address to whoever reads the URL, and the uuid is
 * an identity only this addon can resolve.
 *
 * **Where the redirect comes from.** From the definition, never from the
 * request. A `redirect` taken from the URL would make a signed link on the
 * sender's own domain into an open redirect for anyone who can obtain one
 * signature.
 */
class TagLinks
{
    /** The path parameter. Not `key`: parameter names are application-wide. */
    public const PARAMETER = 'marketingTagKey';

    /** The query parameter that holds the subscription uuid. */
    public const SUBSCRIPTION = 's';

    /**
     * One definition, normalised, or null when there is none worth honouring.
     *
     * A definition without a tag or without a usable redirect is treated as
     * absent rather than half-working: a link that answers 200 and does
     * nothing is worse than one that answers 404.
     *
     * @return array{tags: list<string>, redirect: ?string, thanks: ?string, list: ?string}|null
     */
    public function definition(string $key): ?array
    {
        if ($key === '' || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            return null;
        }

        $all = config('marketing.tag_links', []);
        $raw = is_array($all) ? ($all[$key] ?? null) : null;

        if (! is_array($raw)) {
            return null;
        }

        $tags = collect((array) ($raw['tags'] ?? []))
            ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
            ->map(fn (string $tag): string => trim($tag))
            ->unique()
            ->values()
            ->all();

        $redirect = $this->target($raw['redirect'] ?? null);

        // A thank-you text instead of a redirect: shown on the page the POST
        // answers with. Plain text, escaped when rendered. It wins over a redirect.
        $thanks = isset($raw['thanks']) && is_string($raw['thanks']) && trim($raw['thanks']) !== ''
            ? trim($raw['thanks'])
            : null;

        if ($tags === [] || ($redirect === null && $thanks === null)) {
            return null;
        }

        $list = isset($raw['list']) && is_string($raw['list']) && trim($raw['list']) !== ''
            ? trim($raw['list'])
            : null;

        return ['tags' => $tags, 'redirect' => $redirect, 'thanks' => $thanks, 'list' => $list];
    }

    /**
     * The signed link for this subscription, or `#`.
     *
     * `#` for an unknown key and for a subscription that does not exist, which
     * is what a preview in the Control Panel has: the preview has a sample
     * address and nobody behind it.
     */
    public function url(string $key, ?Subscription $subscription): string
    {
        if ($this->definition($key) === null || $subscription === null || ! $subscription->uuid) {
            return '#';
        }

        return URL::signedRoute('marketing.tag-link.show', [
            self::PARAMETER => $key,
            self::SUBSCRIPTION => $subscription->uuid,
        ]);
    }

    /**
     * The subscription a mail to this address stands on.
     *
     * @param  string|null  $fallbackList  the list the mail is sent from; used
     *                                     when the definition names none.
     */
    public function subscriptionFor(string $key, string $email, ?string $fallbackList): ?Subscription
    {
        $definition = $this->definition($key);
        $list = $definition['list'] ?? $fallbackList;
        $normalized = EmailNormalizer::normalize($email);

        if ($definition === null || $list === null || $list === '' || $normalized === '') {
            return null;
        }

        return Subscription::query()
            ->forList($list)
            ->where('email_normalized', $normalized)
            ->first();
    }

    /**
     * Put the tags on the person behind this subscription.
     *
     * Idempotent: a second call finds everything in place and changes nothing.
     *
     * @param  list<string>  $tags
     */
    public function apply(Subscription $subscription, array $tags): void
    {
        if ($this->leadhubAvailable()) {
            $this->applyToContact($subscription, $tags);

            return;
        }

        $meta = (array) ($subscription->meta ?? []);
        $have = array_values((array) ($meta['tags'] ?? []));
        $merged = array_values(array_unique([...$have, ...$tags]));

        if ($merged !== $have) {
            $meta['tags'] = $merged;
            $subscription->forceFill(['meta' => $meta])->save();
        }
    }

    protected function leadhubAvailable(): bool
    {
        return class_exists(LeadHub::class);
    }

    /**
     * @param  list<string>  $tags
     */
    protected function applyToContact(Subscription $subscription, array $tags): void
    {
        $contact = $subscription->contact_uuid ? LeadHub::find((string) $subscription->contact_uuid) : null;
        $contact ??= LeadHub::findByEmail((string) $subscription->email);
        $contact ??= LeadHub::create(array_filter([
            'email' => (string) $subscription->email,
            'first_name' => $subscription->getAttribute('first_name'),
            'source' => 'marketing-tag-link',
        ], fn ($value): bool => $value !== null && $value !== ''));

        if (! $subscription->contact_uuid && isset($contact['uuid'])) {
            $subscription->forceFill(['contact_uuid' => $contact['uuid']])->save();
        }

        foreach ($tags as $tag) {
            LeadHub::addTag($contact['uuid'], $tag);
        }
    }

    /**
     * A path gets the application URL, an absolute http(s) URL stays as it is,
     * anything else is no target at all.
     */
    protected function target(mixed $redirect): ?string
    {
        if (! is_string($redirect) || trim($redirect) === '') {
            return null;
        }

        $redirect = trim($redirect);

        if (preg_match('#^https?://[^\s/]+#i', $redirect) === 1) {
            return $redirect;
        }

        // `//host/path` is a scheme-relative URL, not a path; and anything with
        // a scheme of its own (`javascript:`, `data:`) is not one either.
        if (! str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || str_contains($redirect, '\\')) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').$redirect;
    }
}
