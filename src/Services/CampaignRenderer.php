<?php

namespace Goldnead\Marketing\Services;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\PreferenceLink;
use Goldnead\Marketing\Support\RenderedMail;
use Illuminate\Support\Facades\URL;
use Statamic\Facades\Antlers;

/**
 * Renders a campaign for one recipient: Antlers-parses the campaign content,
 * wraps it in the template layout, injects the unsubscribe URL, rewrites
 * links to signed click-tracking redirects, and appends the open pixel.
 */
class CampaignRenderer
{
    public function __construct(
        protected EmailTemplateRepository $templates,
        protected PreferenceLink $links,
    ) {}

    /**
     * @param  Subscription|null  $subscription  null renders a preview with sample data.
     * @param  Message|null  $message  when given (a real send), tracking is applied.
     */
    public function render(
        Campaign $campaign,
        MailingList $list,
        ?Subscription $subscription = null,
        ?Message $message = null,
    ): RenderedMail {
        return $this->build($campaign, $list, $subscription, $message, archive: false);
    }

    /**
     * The public web version of a campaign: the same mail, with nobody in it.
     *
     * It goes through the same pipeline as a send rather than beside it. A
     * second renderer would be a second answer to "what does this campaign look
     * like", and the two would drift on the first template change — the web
     * version would then stop being the newsletter it claims to archive.
     *
     * Two things are true of this call and of nothing else:
     *
     *  - **there is no message**, so `rewriteLinks()` and `appendOpenPixel()`
     *    are never reached. That is not a convenience, it is the point. An
     *    open in the archive is not an open of the e-mail, and a click counted
     *    without a recipient behind it would be added to a campaign's rate as
     *    if somebody who received the mail had clicked. The number would go up
     *    and mean less.
     *  - **there is no subscriber**, so every personalisation variable resolves
     *    to something neutral rather than to a stranger's data or to a blank.
     *    See {@see archiveVariables()}.
     */
    public function renderForArchive(Campaign $campaign, MailingList $list): RenderedMail
    {
        return $this->build($campaign, $list, null, null, archive: true);
    }

    protected function build(
        Campaign $campaign,
        MailingList $list,
        ?Subscription $subscription,
        ?Message $message,
        bool $archive,
    ): RenderedMail {
        // Resolved before anything is parsed, because the subject is also a
        // template variable — a body that prints `{{ subject }}` must show the
        // line this recipient actually received, not the campaign's default.
        $subjectTemplate = $this->subjectFor($campaign, $message);

        $variables = $archive
            ? $this->archiveVariables($campaign, $list, $subjectTemplate)
            : $this->variables($campaign, $list, $subscription, $subjectTemplate);

        $content = $this->parse($campaign->content, $variables);

        $templateHtml = $this->resolveTemplateHtml($campaign->templateHandle);

        $html = $this->parse($templateHtml, array_merge($variables, ['content' => $content]));

        if ($message) {
            if (config('marketing.tracking.clicks', true)) {
                $html = $this->rewriteLinks($html, $message, $this->selfServiceUrls($subscription));
            }

            if (config('marketing.tracking.opens', true)) {
                $html = $this->appendOpenPixel($html, $message);
            }
        }

        $subject = $this->parse($subjectTemplate, $variables);

        return new RenderedMail(
            subject: $subject,
            html: $html,
            text: $this->toText($content, $variables['unsubscribe_url']),
            unsubscribeUrl: $variables['unsubscribe_url'],
            oneClickUnsubscribeUrl: $variables['one_click_unsubscribe_url'],
        );
    }

    /**
     * Resolve the layout HTML for a campaign's template reference.
     *
     * A campaign whose template reference answers to nothing still goes out, in
     * the built-in layout. That is right for a broadcast: the content is the
     * mail, the template is the frame around it, and refusing to send a written
     * campaign because a layout handle was renamed would be the more damaging
     * failure. {@see findTemplateHtml()} is the same lookup without that
     * fallback, for the caller where the template *is* the mail.
     */
    protected function resolveTemplateHtml(?string $handle): string
    {
        $html = ($handle !== null && $handle !== '') ? $this->findTemplateHtml($handle) : null;

        return $html ?? EmailTemplate::fallback()->html;
    }

    /**
     * The layout HTML a template reference actually resolves to, or null when
     * nothing answers to it.
     *
     * When the optional email-templates addon is installed, a managed
     * `email_templates` entry with a matching slug wins; the marketing template
     * repository is the caller-supplied fallback (entry wins, file fallback).
     * When the addon is absent, or the slug resolves to neither an entry nor a
     * repository template, only the repository is consulted. Raw legacy handles
     * therefore keep resolving exactly as before — existing campaigns never
     * break.
     *
     * Null is the answer this method exists for. A template-mode send names its
     * template and has no content of its own, so quietly substituting the
     * built-in layout would deliver an empty mail under a subject a reader
     * recognises. The caller has to be able to tell the difference between "the
     * template says this" and "there is no such template", and only an
     * unfalsified lookup can tell it.
     */
    public function findTemplateHtml(string $handle): ?string
    {
        if ($handle === '') {
            return null;
        }

        if (static::emailTemplatesInstalled()) {
            $resolved = EmailTemplates::resolve(
                $handle,
                function (string $slug): ?array {
                    $template = $this->templates->find($slug);

                    return $template ? ['html' => $template->html, 'name' => $template->name] : null;
                },
            );

            if ($resolved !== null && $resolved->body !== '') {
                return $resolved->body;
            }
        }

        return $this->templates->find($handle)?->html;
    }

    /**
     * Is `goldnead/statamic-email-templates` installed?
     *
     * `class_exists` on the facade class itself, which is an ordinary class and
     * answers honestly. Note that `method_exists()` on a facade does not: every
     * call it is asked about goes through `__callStatic` and is not declared,
     * so it reports false for methods that work perfectly well.
     */
    public static function emailTemplatesInstalled(): bool
    {
        return class_exists(EmailTemplates::class);
    }

    /**
     * @param  string|null  $subjectTemplate  the subject this particular
     *                                        recipient is getting, which for an
     *                                        A/B campaign is not necessarily
     *                                        `$campaign->subject`. Optional so
     *                                        existing callers of this public
     *                                        method keep their meaning.
     * @return array<string, mixed>
     */
    public function variables(
        Campaign $campaign,
        MailingList $list,
        ?Subscription $subscription,
        ?string $subjectTemplate = null,
    ): array {
        // Two links, one resolver. `unsubscribe_url` is what a person clicks,
        // so it goes wherever the preference page currently lives — the
        // preference-centre addon when installed, marketing's own unsubscribe
        // otherwise. The one-click URL is what a mail provider POSTs for the
        // RFC 8058 header and stays on marketing unconditionally. See
        // Support/PreferenceLink.
        $unsubscribeUrl = $subscription ? $this->links->manage($subscription->token) : '#';
        $oneClickUrl = $subscription ? $this->links->oneClick($subscription->token) : '#';

        $firstName = $subscription?->first_name;
        $lastName = $subscription?->last_name;

        return $this->withSubscriberAlias([
            'email' => $subscription?->email ?? 'preview@example.com',
            'first_name' => $firstName ?? '',
            'last_name' => $lastName ?? '',
            'name' => trim(($firstName ?? '').' '.($lastName ?? '')) ?: ($subscription?->email ?? ''),
            'unsubscribe_url' => $unsubscribeUrl,
            'one_click_unsubscribe_url' => $oneClickUrl,
            'subject' => $subjectTemplate ?? $campaign->subject,
            'preheader' => $campaign->preheader ?? '',
            'campaign' => [
                'handle' => $campaign->handle,
                'name' => $campaign->name,
            ],
            'list' => [
                'handle' => $list->handle,
                'name' => $list->name,
            ],
        ]);
    }

    /**
     * The flat person variables, offered a second time under `subscriber.*`.
     *
     * Which variables a template may use depended on *which node sent it*. An
     * automation's own nodes resolve their config against the run, where the
     * person lives under `subscriber.*`; a `marketing.send_email` body is
     * parsed against this flat array, where no `subscriber` key existed. Antlers
     * resolves an unknown variable to the empty string, so a template written
     * for one context rendered `Hallo ,` in the other — **silently**, with no
     * error anywhere. That is what made it expensive: the mail went out, it just
     * went out wrong. `subscriber` is marketing's own domain word, not an
     * application placeholder, so offering it here is naming the same thing the
     * way the surrounding system already names it.
     *
     * Derived from the array rather than rebuilt beside it, and applied last in
     * both {@see variables()} and {@see archiveVariables()}. The two spellings
     * therefore cannot drift: `archiveVariables()` overrides the flat person
     * keys after the fact, and re-deriving is what keeps `subscriber.first_name`
     * from still holding the recipient's name on a public archive page.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    protected function withSubscriberAlias(array $variables): array
    {
        $variables['subscriber'] = [
            'email' => $variables['email'],
            'first_name' => $variables['first_name'],
            'last_name' => $variables['last_name'],
            'name' => $variables['name'],
            'unsubscribe_url' => $variables['unsubscribe_url'],
        ];

        return $variables;
    }

    /**
     * The same variables as a send, with the recipient taken out of them.
     *
     * Built on top of {@see variables()} rather than beside it, so a variable
     * added there is available in the archive too. Only the four that describe
     * a person are overridden.
     *
     * `first_name` and `name` become a neutral word instead of an empty string.
     * A greeting is the most common use of either, and an empty value turns
     * `Hallo {{ first_name }},` into `Hallo ,` — visibly broken, on a page
     * indexed by search engines. The word is configurable
     * (`marketing.archive.neutral_name`) because who a reader is addressed as
     * is a matter of voice, and translated by default.
     *
     * `email` stays empty, deliberately. There is no address this copy went to
     * and there is no honest placeholder for one — a made-up address in
     * "this mail was sent to …" is worse than a gap.
     *
     * `unsubscribe_url` and its one-click twin come back from `variables()` as
     * `#` for a call with no subscription. That is the right answer here: there
     * is no subscription to end from a public page, the link is inert rather
     * than broken, and `toText()` already leaves the unsubscribe line out when
     * it sees `#`.
     *
     * @return array<string, mixed>
     */
    public function archiveVariables(
        Campaign $campaign,
        MailingList $list,
        ?string $subjectTemplate = null,
    ): array {
        $neutral = $this->neutralName();

        return $this->withSubscriberAlias(array_merge(
            $this->variables($campaign, $list, null, $subjectTemplate),
            [
                'email' => '',
                'first_name' => $neutral,
                'last_name' => '',
                'name' => $neutral,
            ]
        ));
    }

    protected function neutralName(): string
    {
        $configured = config('marketing.archive.neutral_name');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) __('marketing::public.archive_neutral_name');
    }

    /**
     * The subject template for this send: variant B's line when this message
     * was assigned to B and the campaign actually defines one, otherwise the
     * campaign subject.
     *
     * The message is the authority on which variant this is. It was decided
     * once, at the audience snapshot, and stored — nothing is re-rolled here.
     * A preview or a test send has no message and therefore always shows A,
     * which is the honest answer: there is no recipient to bucket.
     */
    protected function subjectFor(Campaign $campaign, ?Message $message): string
    {
        if ($message?->variant === VariantAssigner::VARIANT_B && $campaign->hasVariants()) {
            return (string) $campaign->variantSubject;
        }

        return $campaign->subject;
    }

    protected function parse(string $template, array $variables): string
    {
        if ($template === '') {
            return '';
        }

        return (string) Antlers::parse($template, $variables);
    }

    /**
     * The start of every URL that leads this reader to their own self-service
     * pages, as prefixes to compare a rendered `href` against.
     *
     * Why this is asked rather than written down. Until v1.9.0 the renderer
     * matched the literal path `/preferences/`, which was marketing's own
     * preference page. That page is gone — it belongs to `goldnead/statamic-
     * preference-center` now — and the literal has been matching nothing ever
     * since, so every preference link in a campaign was going through the
     * signed redirect and inheriting the 403 this release exists to remove.
     * A second hardcoded path would fail the same way the moment the sibling
     * moves its route. Where a subscriber's links live is decided in exactly
     * one place, {@see PreferenceLink}, so that is who gets asked.
     *
     * The token is cut off the end of each answer on purpose. What comes back
     * is the area, not the one URL: a footer that appends `?utm_source=` to
     * `{{ unsubscribe_url }}` still has to survive, and so does a link to the
     * same page carrying somebody else's token.
     *
     * @return list<string>
     */
    protected function selfServiceUrls(?Subscription $subscription): array
    {
        $token = $subscription?->token;

        if (! is_string($token) || $token === '') {
            return [];
        }

        $prefixes = [];

        foreach ([$this->links->manage($token), $this->links->oneClick($token)] as $url) {
            $prefix = strstr($url, $token, true);

            if (is_string($prefix) && $prefix !== '') {
                $prefixes[$prefix] = true;
            }
        }

        return array_keys($prefixes);
    }

    /**
     * Rewrite every absolute http(s) link to a signed tracking redirect. The
     * self-service links and anchors/mailto/tel are left untouched.
     *
     * Unsubscribe, confirm and the preference page all carry their token in
     * the path and are the routes a reader has to be able to reach when
     * everything else has failed. Sending them through the signed redirect
     * would hand a sending platform the chance to break them too — a rewritten
     * link plus an appended parameter is a 403, which would leave someone
     * unable to unsubscribe or to change what they receive.
     *
     * @param  list<string>  $selfService  prefixes from {@see selfServiceUrls()}
     */
    protected function rewriteLinks(string $html, Message $message, array $selfService = []): string
    {
        return (string) preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function (array $matches) use ($message, $selfService) {
                $url = html_entity_decode($matches[1]);

                if ($this->isSelfServiceLink($url, $selfService)) {
                    return $matches[0];
                }

                $tracked = URL::signedRoute('marketing.track.click', [
                    'uuid' => $message->uuid,
                    'url' => $url,
                ]);

                return 'href="'.e($tracked).'"';
            },
            $html,
        );
    }

    /**
     * @param  list<string>  $selfService
     */
    protected function isSelfServiceLink(string $url, array $selfService): bool
    {
        /*
         * Marketing's own two routes, by path. They are checked literally and
         * not only through the resolver because they are the floor: a preview,
         * or a send whose subscription could not be resolved, produces no
         * prefixes at all, and "stopping mail" may not depend on anything
         * having gone right. `/confirm/` is here for the same reason and has
         * no resolver entry of its own — nothing renders it from a variable.
         */
        if (str_contains($url, '/unsubscribe/') || str_contains($url, '/confirm/')) {
            return true;
        }

        foreach ($selfService as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }

    protected function appendOpenPixel(string $html, Message $message): string
    {
        $pixel = '<img src="'.e(route('marketing.track.open', ['uuid' => $message->uuid])).'" width="1" height="1" alt="" style="display:none;" />';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $pixel.'</body>', $html, 1);
        }

        return $html.$pixel;
    }

    /** Plain-text alternative derived from the rendered content. */
    protected function toText(string $contentHtml, string $unsubscribeUrl): string
    {
        $text = preg_replace('/<(br|\/p|\/h[1-6]|\/div|\/li)>/i', "\n", $contentHtml);
        $text = trim(html_entity_decode(strip_tags((string) $text)));

        if ($unsubscribeUrl && $unsubscribeUrl !== '#') {
            $text .= "\n\n".__('Unsubscribe').': '.$unsubscribeUrl;
        }

        return $text;
    }
}
