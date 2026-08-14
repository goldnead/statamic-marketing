<?php

namespace Goldnead\Marketing\Services;

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
    /** Rendered with these, so nothing in the preview is a real person's data. */
    public const SAMPLE = [
        'subject' => 'Ein Beispiel-Betreff',
        'list_name' => 'Beispiel-Verteiler',
        'first_name' => 'Maria',
        'last_name' => 'Beispiel',
        'name' => 'Maria Beispiel',
        'email' => 'maria@example.com',
        'unsubscribe_url' => '#unsubscribe',
        'one_click_unsubscribe_url' => '#unsubscribe',
        'preferences_url' => '#preferences',
        'archive_url' => '#archive',
        'web_version_url' => '#archive',
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
                'html' => (string) Antlers::parse($html, array_merge(self::SAMPLE, [
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
