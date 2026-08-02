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
        // Resolved before anything is parsed, because the subject is also a
        // template variable — a body that prints `{{ subject }}` must show the
        // line this recipient actually received, not the campaign's default.
        $subjectTemplate = $this->subjectFor($campaign, $message);

        $variables = $this->variables($campaign, $list, $subscription, $subjectTemplate);

        $content = $this->parse($campaign->content, $variables);

        $templateHtml = $this->resolveTemplateHtml($campaign->templateHandle);

        $html = $this->parse($templateHtml, array_merge($variables, ['content' => $content]));

        if ($message) {
            if (config('marketing.tracking.clicks', true)) {
                $html = $this->rewriteLinks($html, $message);
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
     * When the optional email-templates addon is installed, a managed
     * `email_templates` entry with a matching slug wins; the marketing
     * template repository is the caller-supplied fallback (entry wins, file
     * fallback). When the addon is absent, or the slug resolves to neither an
     * entry nor a repository template, we fall back to the marketing template
     * repository and finally the built-in layout. Raw legacy handles therefore
     * keep resolving exactly as before — existing campaigns never break.
     */
    protected function resolveTemplateHtml(?string $handle): string
    {
        if ($handle !== null && $handle !== ''
            && class_exists(EmailTemplates::class)) {
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

        $template = ($handle !== null && $handle !== '') ? $this->templates->find($handle) : null;

        return ($template ?? EmailTemplate::fallback())->html;
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

        return [
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
        ];
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
     * Rewrite every absolute http(s) link to a signed tracking redirect. The
     * unsubscribe link and anchors/mailto/tel are left untouched.
     */
    protected function rewriteLinks(string $html, Message $message): string
    {
        return (string) preg_replace_callback(
            '/href="(https?:\/\/[^"]+)"/i',
            function (array $matches) use ($message) {
                $url = html_entity_decode($matches[1]);

                if (str_contains($url, '/unsubscribe/') || str_contains($url, '/confirm/')) {
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
