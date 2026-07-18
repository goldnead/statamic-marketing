<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
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
    public function __construct(protected EmailTemplateRepository $templates)
    {
    }

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
        $variables = $this->variables($campaign, $list, $subscription);

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

        $subject = $this->parse($campaign->subject, $variables);

        return new RenderedMail(
            subject: $subject,
            html: $html,
            text: $this->toText($content, $variables['unsubscribe_url']),
            unsubscribeUrl: $variables['unsubscribe_url'],
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
            && class_exists(\Goldnead\EmailTemplates\Facades\EmailTemplates::class)) {
            $resolved = \Goldnead\EmailTemplates\Facades\EmailTemplates::resolve(
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
     * @return array<string, mixed>
     */
    public function variables(Campaign $campaign, MailingList $list, ?Subscription $subscription): array
    {
        $unsubscribeUrl = $subscription
            ? route('marketing.unsubscribe', ['token' => $subscription->token])
            : '#';

        $firstName = $subscription?->first_name;
        $lastName = $subscription?->last_name;

        return [
            'email' => $subscription?->email ?? 'preview@example.com',
            'first_name' => $firstName ?? '',
            'last_name' => $lastName ?? '',
            'name' => trim(($firstName ?? '').' '.($lastName ?? '')) ?: ($subscription?->email ?? ''),
            'unsubscribe_url' => $unsubscribeUrl,
            'subject' => $campaign->subject,
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
