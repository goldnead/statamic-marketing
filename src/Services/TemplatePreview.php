<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Statamic\Facades\Antlers;

/**
 * What a layout looks like with something in it, and what is wrong with it.
 *
 * A template is the envelope, not the letter: header, footer, colours, and a
 * `{{ content }}` hole in the middle. Editing one as a wall of HTML in a
 * textarea meant the only way to see the result was to save it, write a
 * campaign, and send yourself a test — three steps away from the thing being
 * changed. This renders the same way {@see CampaignRenderer} does, with stand-in
 * copy in the hole, so the editor sees the envelope.
 *
 * The findings are the other half. A layout without `{{ content }}` sends an
 * empty mail: the campaign is written, the send succeeds, and every recipient
 * gets a frame around nothing. That is not something to discover from a
 * subscriber's reply, so it is said while the layout is being written.
 */
class TemplatePreview
{
    public function __construct(protected CampaignRenderer $renderer) {}

    /**
     * The variables a layout may use, with stand-in values.
     *
     * Asked of {@see CampaignRenderer}, never listed here. A hand-written list
     * beside the renderer is a second answer to "which variables exist", and it
     * was already wrong on the day it was written: it offered `list_name`,
     * which no send has ever provided, and left out `preheader`, `campaign.*`
     * and `list.*`, which every send does. Both directions cost the same
     * afternoon — a placeholder that looks dead in the preview and works in
     * production, or one that looks fine and renders empty in the inbox.
     *
     * `archiveVariables()` and not `variables()`: it is the renderer's own
     * depersonalised set, built for the public archive page, which is exactly
     * what a preview needs — every variable present, no recipient in any of them.
     *
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->renderer->archiveVariables(
            new Campaign(
                handle: 'beispiel-kampagne',
                name: __('marketing::templates.sample_campaign'),
                subject: __('marketing::templates.sample_subject'),
                preheader: __('marketing::templates.sample_preheader'),
            ),
            new MailingList(
                handle: 'beispiel-verteiler',
                name: __('marketing::templates.sample_list'),
            ),
        );
    }

    /**
     * Every variable a layout may print, as the dotted names it prints them by.
     *
     * Flattened one level, because that is how they are written: the renderer
     * hands `campaign` as an array and a template says `{{ campaign.name }}`.
     *
     * @return array<int, string>
     */
    public function availableVariables(): array
    {
        $names = ['content'];

        foreach ($this->variables() as $key => $value) {
            if (is_array($value)) {
                foreach (array_keys($value) as $sub) {
                    $names[] = $key.'.'.$sub;
                }

                continue;
            }

            $names[] = $key;
        }

        sort($names);

        return $names;
    }

    /**
     * Placeholders the layout prints that no send will fill in.
     *
     * The failure this catches is the quiet one: Antlers resolves an unknown
     * variable to the empty string, so `{{ list_name }}` — which looks entirely
     * plausible and does not exist — renders as nothing, in the preview and in
     * the inbox alike, and the only symptom is a gap where a word should be.
     *
     * Deliberately conservative. Anything that is not a plain `{{ name }}` or
     * `{{ name.sub }}` is left alone: Antlers conditionals, tags, modifiers and
     * `noparse` all live in the same braces, and a warning that fires on
     * correct markup is how warnings stop being read.
     *
     * @return array<int, string>
     */
    protected function unknownVariables(string $html): array
    {
        $known = $this->availableVariables();
        $unknown = [];

        preg_match_all('/\{\{\s*([a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?)\s*\}\}/i', $html, $matches);

        foreach ($matches[1] as $name) {
            // A parent whose children are offered is itself a legitimate write
            // in Antlers (`{{ campaign }}`…), and the control words are not
            // variables at all.
            if (in_array($name, $known, true) || in_array(strtolower($name), self::CONTROL_WORDS, true)) {
                continue;
            }

            if (in_array($name.'.', array_map(fn ($k) => substr($k, 0, strpos($k, '.') + 1) ?: $k, $known), true)) {
                continue;
            }

            $unknown[$name] = true;
        }

        return array_keys($unknown);
    }

    /** Antlers' own words, which are not variables and must never be flagged. */
    protected const CONTROL_WORDS = [
        'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
        'noparse', 'endnoparse', 'foreach', 'endforeach', 'loop', 'endloop',
        'slot', 'endslot', 'partial', 'endpartial', 'now', 'current_date',
    ];

    /**
     * Render the layout with sample content in the hole.
     *
     * Antlers, the same parser the real send uses, so a layout that renders here
     * renders there — a preview through a different engine would be a second
     * implementation to keep in step, and the first divergence would show up in
     * somebody's inbox.
     *
     * A layout that throws is not an error page: half-typed Antlers is the
     * normal state of a template being edited, and the preview says so and
     * keeps the editor open.
     *
     * @return array{html: string, error: string|null}
     */
    public function render(string $html): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'error' => null];
        }

        try {
            return [
                'html' => (string) Antlers::parse($html, array_merge($this->variables(), [
                    'content' => $this->sampleContent(),
                ])),
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return ['html' => '', 'error' => $e->getMessage()];
        }
    }

    /**
     * What is wrong with this layout, in the order it matters.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public function findings(string $html): array
    {
        $findings = [];

        if (! $this->mentions($html, 'content')) {
            $findings[] = [
                'level' => 'error',
                'message' => __('marketing::templates.finding_no_content'),
            ];
        }

        if (! $this->mentions($html, 'unsubscribe_url') && ! $this->mentions($html, 'one_click_unsubscribe_url')) {
            // A warning and not an error: a layout may legitimately be used for
            // transactional mail, where there is nothing to unsubscribe from.
            // For a campaign it is a legal requirement, and the person writing
            // the layout is the one who can still fix it cheaply.
            $findings[] = [
                'level' => 'warning',
                'message' => __('marketing::templates.finding_no_unsubscribe'),
            ];
        }

        $unknown = $this->unknownVariables($html);

        if ($unknown !== []) {
            $findings[] = [
                'level' => 'warning',
                'message' => __('marketing::templates.finding_unknown_variables', [
                    'names' => implode(', ', array_map(fn ($n) => '{{ '.$n.' }}', $unknown)),
                ]),
            ];
        }

        return $findings;
    }

    /**
     * Does the layout print this variable?
     *
     * Matched on `{{ name }}` with any spacing rather than on the bare word, so
     * the word "content" in a paragraph is not mistaken for the placeholder —
     * which is the false negative that would tell an author their correct
     * layout is broken.
     */
    protected function mentions(string $html, string $variable): bool
    {
        return (bool) preg_match('/\{\{\s*'.preg_quote($variable, '/').'\s*\}\}/', $html);
    }

    /**
     * Stand-in copy for the hole. Deliberately a few blocks rather than one
     * line: a layout's spacing, link colour and paragraph rhythm are what the
     * author is looking at, and none of them show on a single sentence.
     */
    protected function sampleContent(): string
    {
        return implode("\n", [
            '<h1>'.__('marketing::templates.sample_heading').'</h1>',
            '<p>'.__('marketing::templates.sample_paragraph').'</p>',
            '<p><a href="#">'.__('marketing::templates.sample_link').'</a></p>',
            '<ul><li>'.__('marketing::templates.sample_item_one').'</li>'
                .'<li>'.__('marketing::templates.sample_item_two').'</li></ul>',
        ]);
    }
}
