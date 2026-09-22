<?php

namespace Goldnead\Marketing\Support;

use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Exceptions\BlockValuesRejected;
use Statamic\Facades\Blueprint;

/**
 * Der Satz Bloecke, aus denen ein Layout gebaut wird — und nur der Satz.
 *
 * Das Werkzeug ist **Statamics eigener Replicator**, kein selbstgebauter
 * Editor. Der kann Zeilen anlegen, sortieren, klappen, deaktivieren und
 * duplizieren, er hat Tastaturbedienung und er sieht aus wie der Rest des
 * Control Panels — alles Dinge, die ein Eigenbau erst nachholen müsste und
 * dann ein zweites Mal pflegen. Was hier steht, ist deshalb nur die
 * Aufzählung der Sets; die Mechanik gehört dem Feldtyp.
 *
 * **Sieben Bausteine und das Loch.** Absichtlich wenige: Kopf, Text, Bild,
 * Knopf, Trenner, Abstand, Fuss — und der Inhalts-Block, der kein Baustein
 * ist, sondern die Stelle, an der die Kampagne einläuft. Zwanzig halbe Blöcke
 * wären leichter zu bauen und schwerer zu benutzen; dieser Satz deckt jede
 * Mail ab, die dieses Addon verschickt, und jeder einzelne davon kommt in
 * jedem Postfach an.
 *
 * Farben und Schrift stehen absichtlich **nicht** an den Blöcken, sondern an
 * einer Stelle im Übersetzer ({@see BlockLayoutCompiler::THEME}). Sonst hätte
 * jede Marke so viele Wahrheiten über ihr Rot, wie es Blöcke gibt, und das
 * Theme-Ticket hätte nichts, woran es andocken könnte.
 */
class LayoutBlocks
{
    public const HANDLE = 'blocks';

    public const SET_HEADER = 'header';

    public const SET_TEXT = 'text';

    public const SET_IMAGE = 'image';

    public const SET_BUTTON = 'button';

    public const SET_DIVIDER = 'divider';

    public const SET_SPACER = 'spacer';

    public const SET_CONTENT = 'content';

    public const SET_FOOTER = 'footer';

    /**
     * Das Ein-Feld-Blueprint, aus dem die Publish-Form gezeichnet wird.
     *
     * Dieselbe Bauform wie {@see CampaignContentField}: ein Blueprint, das
     * niemand auf der Platte pflegt, aus dem der Editor `toPublishArray()`
     * bekommt und durch das die Werte auf dem Rückweg laufen.
     */
    public static function blueprint(): \Statamic\Fields\Blueprint
    {
        return Blueprint::makeFromFields([
            self::HANDLE => [
                'type' => 'replicator',
                'display' => __('marketing::templates.blocks'),
                'instructions' => __('marketing::templates.blocks_instructions'),
                'collapse' => 'accordion',
                'fullscreen' => false,
                'sets' => [
                    'main' => [
                        'display' => __('marketing::templates.blocks'),
                        'sets' => self::sets(),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sets(): array
    {
        return [
            self::SET_HEADER => [
                'display' => __('marketing::templates.block_header'),
                'instructions' => __('marketing::templates.block_header_instructions'),
                'icon' => 'layout-header',
                'fields' => [
                    self::field('logo', self::imageField(__('marketing::templates.block_field_logo'))),
                    self::field('logo_alt', ['type' => 'text', 'display' => __('marketing::templates.block_field_alt'), 'instructions' => __('marketing::templates.block_field_alt_instructions'), 'width' => 50]),
                    self::field('logo_width', ['type' => 'integer', 'display' => __('marketing::templates.block_field_width'), 'default' => 160, 'width' => 50]),
                    self::field('brand_name', ['type' => 'text', 'display' => __('marketing::templates.block_field_brand_name'), 'instructions' => __('marketing::templates.block_field_brand_name_instructions')]),
                    self::field('link_url', self::urlField()),
                    self::field('align', self::alignField('left')),
                ],
            ],

            self::SET_TEXT => [
                'display' => __('marketing::templates.block_text'),
                'instructions' => __('marketing::templates.block_text_instructions'),
                'icon' => 'text',
                'fields' => [
                    self::field('heading', ['type' => 'text', 'display' => __('marketing::templates.block_field_heading')]),
                    self::field('text', [
                        'type' => 'textarea',
                        'display' => __('marketing::templates.block_field_text'),
                        'instructions' => __('marketing::templates.block_field_text_instructions'),
                        'rows' => 6,
                    ]),
                    self::field('align', self::alignField('left')),
                ],
            ],

            self::SET_IMAGE => [
                'display' => __('marketing::templates.block_image'),
                'instructions' => __('marketing::templates.block_image_instructions'),
                'icon' => 'assets',
                'fields' => [
                    self::field('image', self::imageField(__('marketing::templates.block_field_image'))),
                    self::field('alt', ['type' => 'text', 'display' => __('marketing::templates.block_field_alt'), 'instructions' => __('marketing::templates.block_field_alt_instructions')]),
                    self::field('width', ['type' => 'integer', 'display' => __('marketing::templates.block_field_width'), 'default' => 536, 'width' => 50]),
                    self::field('align', self::alignField('center')),
                    self::field('link_url', self::urlField()),
                ],
            ],

            self::SET_BUTTON => [
                'display' => __('marketing::templates.block_button'),
                'instructions' => __('marketing::templates.block_button_instructions'),
                'icon' => 'button',
                'fields' => [
                    self::field('label', ['type' => 'text', 'display' => __('marketing::templates.block_field_label'), 'width' => 50]),
                    self::field('url', self::urlField()),
                    self::field('align', self::alignField('left')),
                ],
            ],

            self::SET_DIVIDER => [
                'display' => __('marketing::templates.block_divider'),
                'instructions' => __('marketing::templates.block_divider_instructions'),
                'icon' => 'horizontal-rule',
                'fields' => [
                    self::field('spacing', [
                        'type' => 'select',
                        'display' => __('marketing::templates.block_field_spacing'),
                        'default' => 'medium',
                        'options' => [
                            'small' => __('marketing::templates.spacing_small'),
                            'medium' => __('marketing::templates.spacing_medium'),
                            'large' => __('marketing::templates.spacing_large'),
                        ],
                    ]),
                ],
            ],

            self::SET_SPACER => [
                'display' => __('marketing::templates.block_spacer'),
                'instructions' => __('marketing::templates.block_spacer_instructions'),
                'icon' => 'arrows-vertical',
                'fields' => [
                    self::field('height', ['type' => 'integer', 'display' => __('marketing::templates.block_field_height'), 'default' => 24, 'width' => 50]),
                ],
            ],

            // Kein Baustein, sondern das Loch. Ohne Felder, weil es nichts zu
            // konfigurieren gibt: die Kampagne bringt ihren eigenen Text mit.
            self::SET_CONTENT => [
                'display' => __('marketing::templates.block_content'),
                'instructions' => __('marketing::templates.block_content_instructions'),
                'icon' => 'replicator',
                'fields' => [],
            ],

            self::SET_FOOTER => [
                'display' => __('marketing::templates.block_footer'),
                'instructions' => __('marketing::templates.block_footer_instructions'),
                'icon' => 'layout-footer',
                'fields' => [
                    self::field('text', [
                        'type' => 'textarea',
                        'display' => __('marketing::templates.block_field_footer_text'),
                        'instructions' => __('marketing::templates.block_field_footer_text_instructions'),
                        'rows' => 4,
                    ]),
                    self::field('show_unsubscribe', [
                        'type' => 'toggle',
                        'display' => __('marketing::templates.block_field_show_unsubscribe'),
                        'instructions' => __('marketing::templates.block_field_show_unsubscribe_instructions'),
                        'default' => true,
                    ]),
                    self::field('unsubscribe_label', ['type' => 'text', 'display' => __('marketing::templates.block_field_unsubscribe_label')]),
                ],
            ],
        ];
    }

    /**
     * Ein Bildfeld — der Asset-Picker, wo die Site einen Container hat, sonst
     * ein Feld für eine URL.
     *
     * Dieselbe Falle wie beim Bard-Bildknopf in {@see CampaignContentField}:
     * ein Asset-Feld ohne Container wirft beim Vorladen, und der Editor wäre
     * gar nicht erst zu öffnen. Ohne Container also ein Textfeld, und der
     * Übersetzer nimmt beides.
     *
     * `max_files: 1` heisst bei diesem Feldtyp, dass der Wert ein einzelner
     * Pfad ist und keine Liste — siehe `Assets::process()`.
     *
     * @return array<string, mixed>
     */
    protected static function imageField(string $display): array
    {
        $container = CampaignContentField::assetContainer();

        if ($container === null) {
            return [
                'type' => 'text',
                'display' => $display,
                'instructions' => __('marketing::templates.block_field_image_url_instructions'),
            ];
        }

        return [
            'type' => 'assets',
            'display' => $display,
            'container' => $container,
            'max_files' => 1,
            'mode' => 'list',
            'restrict' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function urlField(): array
    {
        return [
            'type' => 'text',
            'display' => __('marketing::templates.block_field_url'),
            'instructions' => __('marketing::templates.block_field_url_instructions'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function alignField(string $default): array
    {
        return [
            'type' => 'select',
            'display' => __('marketing::templates.block_field_align'),
            'default' => $default,
            'width' => 50,
            'options' => [
                'left' => __('marketing::templates.align_left'),
                'center' => __('marketing::templates.align_center'),
                'right' => __('marketing::templates.align_right'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected static function field(string $handle, array $config): array
    {
        return ['handle' => $handle, 'field' => $config];
    }

    /**
     * Was die Publish-Form braucht: die Felddefinitionen, die Werte in der
     * Form, die der Editor erwartet, und die Metadaten des Feldtyps.
     *
     * `preProcess()` ist die Hälfte, die man vergisst: der Replicator vergibt
     * dort die Zeilen-IDs, und ein Asset-Feld holt dort die Titel der bereits
     * gewählten Dateien. Ohne das öffnet der Editor mit leeren Zeilen.
     *
     * @param  array<int, mixed>|null  $blocks
     * @return array{blueprint: array<string, mixed>, values: array<string, mixed>, meta: array<string, mixed>}
     */
    public static function forEditing(?array $blocks): array
    {
        $fields = self::blueprint()
            ->fields()
            ->addValues([self::HANDLE => $blocks ?? []])
            ->preProcess();

        return [
            'blueprint' => self::blueprint()->toPublishArray(),
            'values' => $fields->values()->all(),
            'meta' => $fields->meta()->all(),
        ];
    }

    /**
     * Die zu speichernden Blöcke aus dem, was die Form geschickt hat.
     *
     * Durch `process()` des Feldtyps und niemals roh aus dem Request: dort
     * wird aus `_id` die persistierte `id`, dort macht das Asset-Feld aus
     * einer Auswahl einen Pfad, und dort fallen leere Werte heraus. Eine
     * zweite Auslegung dieser Struktur hier wäre eine zweite Wahrheit über
     * ein Format, das uns nicht gehört.
     *
     * Und genau deshalb kann es hier werfen: `Assets::process()` ruft
     * `Asset::findOrFail()`, und ein Bild, das seit der Auswahl aus dem
     * Container verschwunden ist, reisst die ganze Kette ab. Ungefangen wäre
     * das eine 500er-Seite beim Speichern und bei jedem Tastendruck in der
     * Vorschau. Die Ausnahme wird eingepackt, damit die beiden Aufrufer sie
     * dort zeigen können, wo sie hingehört.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws BlockValuesRejected
     */
    public static function fromForm(mixed $submitted): array
    {
        if (! is_array($submitted)) {
            return [];
        }

        try {
            $processed = self::blueprint()
                ->fields()
                ->addValues([self::HANDLE => $submitted])
                ->process()
                ->values()
                ->get(self::HANDLE);
        } catch (\Throwable $e) {
            throw new BlockValuesRejected($e->getMessage(), previous: $e);
        }

        return is_array($processed) ? array_values($processed) : [];
    }

    /**
     * Wie oft das Inhalts-Loch vorkommt.
     *
     * Genau einmal ist richtig. Keinmal sendet einen Rahmen um nichts;
     * zweimal sendet die Kampagne doppelt, was niemand will und was erst im
     * Postfach auffällt. Deaktivierte Zeilen zählen nicht mit — eine
     * abgeschaltete Zeile rendert nicht, also ist ein Layout, dessen einziger
     * Inhalts-Block abgeschaltet ist, genau so kaputt wie eines ohne.
     *
     * @param  array<int, mixed>  $blocks
     */
    public static function countContentBlocks(array $blocks): int
    {
        return count(array_filter(
            $blocks,
            fn ($block) => is_array($block)
                && ($block['type'] ?? null) === self::SET_CONTENT
                && ($block['enabled'] ?? true) !== false,
        ));
    }

    /**
     * Womit ein frisches Block-Layout aufmacht.
     *
     * Nicht leer: ein leerer Replicator ist eine Fläche mit einem Knopf und
     * sagt niemandem, wie ein Layout aussieht. Das hier ist dieselbe Mail wie
     * {@see EmailTemplate::fallback()}, nur in
     * Blöcken — Markenzeile, das Loch, Fuss mit Abmeldelink — und damit sofort
     * ein sendbares Layout.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function starter(): array
    {
        return [
            // Der Site-Name als Markenzeile, damit der Kopf im ersten Moment
            // etwas zeigt: ein Startblock, der leer rendert, sieht aus wie ein
            // kaputter Editor und nicht wie ein Feld, das gefüllt werden will.
            [
                'type' => self::SET_HEADER,
                'enabled' => true,
                'brand_name' => (string) config('app.name'),
                'align' => 'left',
            ],
            ['type' => self::SET_CONTENT, 'enabled' => true],
            ['type' => self::SET_FOOTER, 'enabled' => true, 'show_unsubscribe' => true],
        ];
    }
}
