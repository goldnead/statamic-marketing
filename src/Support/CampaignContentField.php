<?php

namespace Goldnead\Marketing\Support;

use Statamic\Facades\Blueprint;

/**
 * The campaign's text, as a Bard field rather than a box of HTML.
 *
 * Writing a newsletter in a textarea full of `<p>` tags is not writing, and it
 * is not what the rest of this Control Panel asks of anybody: an email template
 * is edited in Bard, and a campaign is the same kind of thing written by the
 * same person. The mismatch was the whole of Adrian's complaint.
 *
 * **The column does not change.** `save_html` is on, so the fieldtype hands back
 * an HTML string on the way in and takes one on the way out — `campaigns.content`
 * still holds exactly what it held before, and {@see CampaignRenderer} and every
 * send path are untouched. A campaign written through the API, imported, or
 * created before this release opens in the editor and saves back out without a
 * migration and without a converter of our own.
 *
 * `sets` are deliberately absent. Bard silently ignores `save_html` as soon as a
 * field has sets (see `Bard::shouldSaveHtml`), and the column would start
 * receiving ProseMirror arrays that the renderer parses as Antlers.
 */
class CampaignContentField
{
    public const HANDLE = 'content';

    /**
     * The one-field blueprint the publish form is drawn from.
     *
     * Buttons match the email-template editor, minus the ones an email cannot
     * carry sensibly. Kept in one place so the two screens cannot drift into
     * offering different formatting for the same medium.
     */
    public static function blueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::makeFromFields([
            self::HANDLE => [
                'type' => 'bard',
                'display' => __('marketing::campaigns.content'),
                'instructions' => __('marketing::campaigns.content_instructions'),
                'buttons' => [
                    'h2', 'h3', 'bold', 'italic', 'unorderedlist', 'orderedlist',
                    'quote', 'anchor', 'image', 'horizontalrule', 'removeformat',
                ],
                'save_html' => true,
                'always_show_set_button' => false,
            ],
        ]);
    }

    /**
     * What the publish form needs to render: the field definitions, the value
     * as the editor wants it, and the fieldtype metadata.
     *
     * `preProcess()` is what turns the stored HTML into the ProseMirror document
     * Bard edits. Doing it here rather than in the browser means the conversion
     * is Statamic's own, and a campaign written in another editor still opens.
     *
     * @return array{blueprint: array<string, mixed>, values: array<string, mixed>, meta: array<string, mixed>}
     */
    public function forEditing(?string $html): array
    {
        $fields = self::blueprint()
            ->fields()
            ->addValues([self::HANDLE => $html ?? ''])
            ->preProcess();

        return [
            'blueprint' => self::blueprint()->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
        ];
    }

    /**
     * The HTML to store, from whatever the form submitted.
     *
     * Run through the fieldtype's own `process()`, never read straight off the
     * request: that is where `save_html` turns the document back into HTML, and
     * it is the only place that knows how. A string is passed through untouched
     * so the endpoint keeps accepting plain HTML from the API and from anything
     * that posts to it without a publish form.
     */
    public function fromForm(mixed $submitted): string
    {
        if (is_string($submitted)) {
            return $submitted;
        }

        if (! is_array($submitted)) {
            return '';
        }

        $processed = self::blueprint()
            ->fields()
            ->addValues([self::HANDLE => $submitted])
            ->process()
            ->values()
            ->get(self::HANDLE);

        return is_string($processed) ? $processed : '';
    }
}
