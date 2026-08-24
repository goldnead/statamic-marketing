<?php

namespace Goldnead\Marketing\Services;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Support\PreferenceLink;
use Goldnead\Marketing\Support\RenderedMail;
use Illuminate\Support\Facades\Log;
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
    /**
     * @param  string|null  $sequenceUuid  Die Automation, aus der diese Mail
     *                                     kommt. Nur gesetzt, wenn ein
     *                                     Automations-Knoten sendet — nur dann
     *                                     gibt es eine Serie, aus der man
     *                                     aussteigen koennte.
     */
    public function render(
        Campaign $campaign,
        MailingList $list,
        ?Subscription $subscription = null,
        ?Message $message = null,
        ?string $sequenceUuid = null,
    ): RenderedMail {
        return $this->build($campaign, $list, $subscription, $message, archive: false, sequenceUuid: $sequenceUuid);
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
        ?string $sequenceUuid = null,
    ): RenderedMail {
        // Resolved before anything is parsed, because the subject is also a
        // template variable — a body that prints `{{ subject }}` must show the
        // line this recipient actually received, not the campaign's default.
        $subjectTemplate = $this->subjectFor($campaign, $message);

        $variables = $archive
            ? $this->archiveVariables($campaign, $list, $subjectTemplate)
            : $this->variables($campaign, $list, $subscription, $subjectTemplate, $sequenceUuid);

        $content = $this->parse($campaign->content, $variables);

        $templateHtml = $this->resolveTemplateHtml($campaign->templateHandle);

        $html = $this->parse($templateHtml, array_merge($variables, ['content' => $content]));

        $html = $this->ensureSelfServiceFooter($html, $variables, $subscription);
        $html = $this->ensurePostalLine($html);

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
            text: $this->toText($content),
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

        if ($html === null && $handle !== null && $handle !== '') {
            /*
             * Der Rueckfall ist richtig — aber lautlos war er falsch.
             *
             * Ein Vorlagen-Handle loest aus zwei Gruenden nicht auf: er wurde
             * umbenannt, oder die Zeile ist weg (CP-Loeschung, Sicherung von
             * vor dem Anlegen). Beides sieht am Ergebnis gleich aus, und beides
             * tauscht das Layout einer laufenden Strecke aus, ohne dass jemand
             * es merkt. Die Zeile hier ist der Unterschied zwischen "faellt
             * beim naechsten Blick ins Log auf" und "faellt gar nicht auf".
             */
            Log::warning('[marketing] Campaign template ['.$handle.'] does not resolve; the built-in fallback layout was used. Check that the template still exists.');
        }

        return $html ?? EmailTemplate::fallback()->html;
    }

    /**
     * Das zweite Netz: keine Werbemail ohne Anbieterkennzeichnung.
     *
     * {@see ensureSelfServiceFooter()} haengt nur an, wenn die Vorlage gar
     * keinen Selbstbedienungs-Weg enthaelt. Das mitgelieferte Ersatzlayout hat
     * einen Abmeldelink — und **keine Anschrift**. Damit rutschte genau die
     * Kombination durch, die im deutschen Recht nicht durchrutschen darf:
     * Werbepost mit Ausweg, aber ohne Pflichtangabe.
     *
     * Die beiden Pruefungen sind deshalb getrennt. Eine Vorlage kann den Link
     * haben und die Anschrift nicht.
     *
     * Die `text/plain`-Fassung war nie betroffen: dort kommt die Zeile aus der
     * Mailable. Betroffen war die Darstellung, die fast jeder sieht.
     *
     * Ist keine Zeile konfiguriert, passiert nichts — ein Addon kann die
     * Anschrift seines Betreibers nicht erfinden, und eine erfundene waere
     * schlimmer als keine.
     *
     * Aufgeloest statt gelesen: ein fester Config-Wert waere fuer eine Marke
     * richtig und fuer sechs in einem Prozess falsch — dieselbe Fehlerklasse,
     * die die Absenderidentitaet schon hatte. Siehe {@see PostalLineResolver}.
     */
    protected function ensurePostalLine(string $html): string
    {
        $zeile = app(\Goldnead\Marketing\Contracts\PostalLineResolver::class)->line();

        if ($zeile === '') {
            return $html;
        }

        // Schon da? Dann hat die Vorlage ihre Pflicht getan.
        if (str_contains(strip_tags($html), $zeile)) {
            return $html;
        }

        $block = sprintf(
            '<div style="margin:0;padding:0 32px 24px;font-family:Arial,Helvetica,sans-serif;'
            .'font-size:12px;line-height:1.55;color:#78716C;text-align:center;">%s</div>',
            nl2br(e($zeile)),
        );

        $pos = strripos($html, '</body>');

        return $pos === false
            ? $html.$block
            : substr($html, 0, $pos).$block.substr($html, $pos);
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
        ?string $sequenceUuid = null,
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

            /*
             * Der Ausstieg aus DIESER Serie, ohne alles abzubestellen.
             *
             * Leer, wenn die Mail nicht aus einer Automation kommt — eine
             * Kampagne ist keine Serie, und ein Link, der "diese Serie
             * abbestellen" verspricht und den Newsletter beendet, waere eine
             * Falle. Den Weg kennt das Automations-Addon, nicht dieses hier;
             * fehlt es, bleibt der Wert leer.
             */
            'sequence_unsubscribe_url' => $this->sequenceUnsubscribeUrl($sequenceUuid, $subscription),
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
     * Den Serien-Ausstiegs-Link beim Automations-Addon erfragen.
     *
     * Ueber den Container und mit class_exists davor, weil dieses Addon nicht
     * von den Automationen abhaengt — genauso wie AutomationsBridge es
     * andersherum haelt. Ohne das Paket gibt es keine Serien, also auch keinen
     * Link, und eine leere Zeichenkette ist die ehrliche Antwort darauf.
     */
    protected function sequenceUnsubscribeUrl(?string $sequenceUuid, ?Subscription $subscription): string
    {
        $token = $subscription?->token;

        if ($sequenceUuid === null || $sequenceUuid === '' || ! is_string($token) || $token === '') {
            return '';
        }

        $class = 'Goldnead\\StatamicAutomations\\Support\\SequenceOptOutLink';

        if (! class_exists($class)) {
            return '';
        }

        return (string) (app($class)->url($sequenceUuid, $token) ?? '');
    }

    /**
     * Das Netz unter der Vorlage: keine Werbemail ohne Ausweg.
     *
     * `{{ unsubscribe_url }}` steht jeder Vorlage zur Verfuegung, aber eine
     * Vorlage kann es vergessen — und genau das ist passiert: die fuenf
     * Willkommens-Mails von adriangoldner.com gingen monatelang ohne
     * sichtbaren Abmelde-Link raus. Der `List-Unsubscribe`-Kopfeintrag war da,
     * aber den zeigt nicht jedes Mailprogramm, und wer ihn nicht sieht, hat
     * keinen Weg hinaus.
     *
     * Deshalb liegt die Zusicherung hier statt in den Vorlagen: eine Vorlage,
     * die den Link selbst setzt, bleibt unangetastet — erkannt daran, dass die
     * gerenderte Mail bereits auf einen der Selbstbedienungs-Wege zeigt. Nur
     * wenn keiner darin vorkommt, haengt der Renderer einen schlichten Fuss an.
     *
     * Ohne Subscription (Vorschau, Archiv) passiert nichts: dort gibt es keinen
     * Token, und ein Abmelde-Link ohne Token waere eine Attrappe.
     */
    protected function ensureSelfServiceFooter(string $html, array $variables, ?Subscription $subscription): string
    {
        if (! $subscription) {
            return $html;
        }

        foreach ($this->selfServiceUrls($subscription) as $prefix) {
            if (str_contains($html, $prefix)) {
                return $html;
            }
        }

        $unsubscribe = (string) ($variables['unsubscribe_url'] ?? '');

        if ($unsubscribe === '' || $unsubscribe === '#') {
            return $html;
        }

        $footer = $this->selfServiceFooterHtml(
            $unsubscribe,
            (string) ($variables['sequence_unsubscribe_url'] ?? ''),
        );

        // Vor </body>, damit der Fuss innerhalb des Rahmens landet. Eine
        // Vorlage ohne </body> ist ein Fragment — dann ans Ende.
        $pos = strripos($html, '</body>');

        return $pos === false
            ? $html.$footer
            : substr($html, 0, $pos).$footer.substr($html, $pos);
    }

    /**
     * Der angehaengte Fuss. Bewusst mit Inline-Stilen und ohne Klassen:
     * er muss auch dann lesbar sein, wenn die Vorlage gar kein CSS mitbringt.
     */
    protected function selfServiceFooterHtml(string $unsubscribeUrl, string $sequenceUrl = ''): string
    {
        $stil = 'color:#78716C;text-decoration:underline;';

        $links = [];

        /*
         * Der Serien-Ausstieg zuerst.
         *
         * Er ist der kleinere Schritt und fuer die meisten der gemeinte: wer
         * eine Willkommensstrecke nicht zu Ende lesen will, will selten den
         * Newsletter los. Stuende die vollstaendige Abmeldung vorn, klickt sie
         * jemand, weil sie die erste ist — und ist dann ganz weg.
         */
        if ($sequenceUrl !== '') {
            $links[] = sprintf(
                '<a href="%s" style="%s">%s</a>',
                e($sequenceUrl),
                $stil,
                e(__('statamic-automations::sequence.mail_link')),
            );
        }

        $links[] = sprintf(
            '<a href="%s" style="%s">%s</a>',
            e($unsubscribeUrl),
            $stil,
            e(__('marketing::mail.footer_unsubscribe')),
        );

        $zeile = implode(' &nbsp;·&nbsp; ', $links);

        return <<<HTML
        <div style="margin:0;padding:24px 32px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.55;color:#78716C;text-align:center;">
            {$zeile}
        </div>
        HTML;
    }

    /**
     * Rewrite every absolute http(s) link IN AN ANCHOR to a signed tracking
     * redirect. Self-service links, mailto/tel and every other kind of
     * reference are left untouched.
     *
     * Unsubscribe, confirm and the preference page all carry their token in
     * the path and are the routes a reader has to be able to reach when
     * everything else has failed. Sending them through the signed redirect
     * would hand a sending platform the chance to break them too — a rewritten
     * link plus an appended parameter is a 403, which would leave someone
     * unable to unsubscribe or to change what they receive.
     *
     * `<a …>` and nothing else. Until 2.3.0 this matched any `href` in the
     * document, which in a real layout is not only links: a
     * `<link rel="stylesheet" href="https://fonts.googleapis.com/…">` in the
     * head of Adrian Goldner's newsletter template was rewritten onto the click
     * counter, measured on 13.08.2026. Two consequences, both bad. Every mail
     * client that loads the web font counted a click on a campaign nobody had
     * clicked, so the click rate measured the readers' font settings. And the
     * stylesheet only arrived at all because the redirect happened to work —
     * a signature check that failed, or a provider appending a parameter,
     * would have taken the typography of the mail with it.
     *
     * @param  list<string>  $selfService  prefixes from {@see selfServiceUrls()}
     */
    protected function rewriteLinks(string $html, Message $message, array $selfService = []): string
    {
        return (string) preg_replace_callback(
            '/<a\s([^>]*?)href="(https?:\/\/[^"]+)"/i',
            function (array $matches) use ($message, $selfService) {
                $url = html_entity_decode($matches[2]);

                if ($this->isSelfServiceLink($url, $selfService)) {
                    return $matches[0];
                }

                $tracked = URL::signedRoute('marketing.track.click', [
                    'uuid' => $message->uuid,
                    'url' => $url,
                ]);

                return '<a '.$matches[1].'href="'.e($tracked).'"';
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

    /**
     * Plain-text alternative derived from the rendered content.
     *
     * The unsubscribe line is NOT appended here any more. It is written by the
     * text view of {@see CampaignMail}, and moving it
     * there fixed two things at once that could not be fixed here:
     *
     *  - **The language.** This method runs at render time, before the mailable
     *    exists, so `__()` answered in the application locale — English on a
     *    host whose `APP_LOCALE` is `en`, in a German campaign, every time. A
     *    Mailable renders its views inside the recipient's locale, so the same
     *    sentence in the view comes out in the language the brand writes in.
     *  - **The address.** It used the footer link, which resolves to the
     *    preference centre wherever that sibling is installed — a page of
     *    checkboxes on which one click unsubscribes nobody. The text part of a
     *    marketing mail has to carry the link that ends the subscription by
     *    itself, and that is the one-click URL the `List-Unsubscribe` header
     *    already uses.
     */
    protected function toText(string $contentHtml): string
    {
        $text = preg_replace('/<(br|\/p|\/h[1-6]|\/div|\/li)>/i', "\n", $contentHtml);

        return trim(html_entity_decode(strip_tags((string) $text)));
    }
}
