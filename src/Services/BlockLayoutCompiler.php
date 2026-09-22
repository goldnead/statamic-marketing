<?php

namespace Goldnead\Marketing\Services;

use Goldnead\Marketing\Support\CampaignContentField;
use Goldnead\Marketing\Support\LayoutBlocks;
use Statamic\Facades\Asset;
use Statamic\Facades\URL;

/**
 * Bloecke zu Mail-HTML. Der eine Uebersetzer, und der einzige Ausgang.
 *
 * Bloecke sind eine **zweite Eingabe, kein zweiter Ausgang**: beim Speichern
 * laeuft das hier, und das Ergebnis landet in derselben `html`-Spalte, die ein
 * handgeschriebenes Layout auch fuellt. {@see CampaignRenderer}, der Versand,
 * der Snapshot und das Archiv lesen weiter genau einen HTML-String und
 * erfahren nie, dass es Bloecke gibt. Deshalb gibt es auch keine
 * Rueckuebersetzung — HTML zurueck in Bloecke zu raten ginge genau einmal gut.
 *
 * ## Was „Mail-HTML" hier heisst
 *
 * Nicht das HTML, das ein Browser mag. Ein Postfach ist ein zwanzig Jahre
 * alter Renderer mit Hausrecht, und die Regeln, die daraus folgen, stehen
 * nicht zur Debatte:
 *
 * - **Tabellen, keine Boxen.** Jeder Block ist eine `<tr>` in einer Tabelle
 *   fester Breite. Outlook rendert ueber Word, und Word kennt weder Flexbox
 *   noch Grid noch `max-width` auf einem `div`.
 * - **Inline-Styles.** Gmail wirft den `<head>` weg, sobald es die Mail in
 *   einen Thread einklappt. Was nicht am Element steht, gibt es dort nicht.
 * - **Schrift an jedem `<td>`.** Vererbung von `<body>` nach unten ist in
 *   Outlook nicht verlaesslich; jede Textzelle sagt ihre Schrift selbst.
 * - **Kein externes Stylesheet, kein Webfont, kein Javascript.** Das eine
 *   `<style>` im Kopf traegt ausschliesslich Dinge, ohne die die Mail
 *   vollstaendig funktioniert: die Handy-Breite und den Dunkel-Modus. Wer es
 *   verwirft (Outlook, Gmail im Thread), bekommt die helle Fassung, und die
 *   ist vollstaendig.
 *
 * ## Wo B1 einsteigt
 *
 * Die Farben und die Schrift stehen in {@see self::THEME} an genau einer
 * Stelle, nicht in den Bloecken. Wenn das Theme aus `brand-context` kommt
 * (Ticket B1), wird hier ein Wert hineingereicht und jedes Block-Layout der
 * Marke sieht anders aus. Kein Block muss dafuer angefasst werden, und kein
 * HTML-Layout aendert sich — wer rohes HTML schreibt, setzt seine Farben
 * selbst, und das soll so bleiben.
 */
class BlockLayoutCompiler
{
    /**
     * Die eine Stelle, an der ein Block-Layout aussieht, wie es aussieht.
     *
     * @var array<string, string>
     */
    public const THEME = [
        'page_background' => '#f4f4f5',
        'canvas' => '#ffffff',
        'text' => '#18181b',
        'muted' => '#71717a',
        'accent' => '#2563eb',
        'rule' => '#e4e4e7',
        'button_background' => '#18181b',
        'button_text' => '#ffffff',
        'font' => "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
        'width' => '600',
        'radius' => '6px',
    ];

    /** Die Schemata, die in einer Mail als Link etwas zu suchen haben. */
    protected const SAFE_SCHEMES = ['http://', 'https://', 'mailto:', 'tel:', '#'];

    /**
     * Was beim letzten `compile()` nicht in die Mail gekommen ist.
     *
     * @var array<int, string>
     */
    protected array $warnings = [];

    /**
     * @param  array<int, mixed>  $blocks
     */
    public function compile(array $blocks): string
    {
        $this->warnings = [];
        $rows = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['enabled'] ?? true) === false) {
                continue;
            }

            $row = $this->row($block);

            if ($row !== '') {
                $rows[] = $row;
            }
        }

        return $this->document(implode("\n", $rows));
    }

    /**
     * Die Bausteine, die der letzte Lauf verworfen hat, im Wortlaut.
     *
     * Der stille Fall, den es zu verhindern gilt: jemand tippt Beschriftung
     * und Ziel eines Knopfes ein, schreibt `www.example.com` statt
     * `https://www.example.com`, und der Knopf fällt aus der Mail heraus —
     * ohne Meldung, ohne Befund, ohne irgendetwas. Gespeichert wird ein
     * Layout, in dem der Knopf fehlt, und auffallen kann das erst im Postfach.
     *
     * Also sagt der Übersetzer, was er weggelassen hat, und die Vorschau
     * zeigt es neben dem Editor — dort, wo der Baustein gerade ausgefüllt
     * wurde. Eine Ablehnung wäre zu hart: ein Layout mit einem halb
     * ausgefüllten Knopf muss sich zwischendurch speichern lassen.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Ein Block, eine Tabellenzeile.
     *
     * Ein unbekannter Typ gibt leer zurueck statt zu werfen: ein Layout, das
     * mit einem spaeter entfernten Blocktyp gespeichert wurde, soll sich
     * weiter oeffnen und speichern lassen, nicht das Postfach eines Abonnenten
     * gegen eine Ausnahme tauschen.
     *
     * @param  array<string, mixed>  $block
     */
    protected function row(array $block): string
    {
        return match ($block['type'] ?? null) {
            LayoutBlocks::SET_HEADER => $this->header($block),
            LayoutBlocks::SET_TEXT => $this->text($block),
            LayoutBlocks::SET_IMAGE => $this->image($block),
            LayoutBlocks::SET_BUTTON => $this->button($block),
            LayoutBlocks::SET_DIVIDER => $this->divider($block),
            LayoutBlocks::SET_SPACER => $this->spacer($block),
            LayoutBlocks::SET_CONTENT => $this->content(),
            LayoutBlocks::SET_FOOTER => $this->footer($block),
            default => '',
        };
    }

    /**
     * Kopf: Logo oder Markenzeile, beides optional verlinkt.
     *
     * Entweder, nicht beides: ein Logo *und* der Markenname daneben ist in
     * einer 600 Pixel breiten Mail keine Gestaltung, sondern zweimal dasselbe.
     * Liegt ein Logo vor, gewinnt es, und die Markenzeile ist der Fall für
     * alle, die keines haben.
     *
     * @param  array<string, mixed>  $block
     */
    protected function header(array $block): string
    {
        $align = $this->align($block['align'] ?? 'left');
        $url = $this->url($block['link_url'] ?? null);
        $inner = '';

        if ($src = $this->imageSource($block['logo'] ?? null)) {
            $inner = $this->img(
                $src,
                (string) ($block['logo_alt'] ?? ''),
                $this->pixels($block['logo_width'] ?? null, 160, 20, (int) self::THEME['width']),
                $align,
            );
        } elseif ($name = trim((string) ($block['brand_name'] ?? ''))) {
            $inner = sprintf(
                '<span style="font-family:%s;font-size:20px;line-height:28px;font-weight:700;color:%s;">%s</span>',
                self::THEME['font'],
                self::THEME['text'],
                $this->text_($name),
            );
        }

        if ($inner === '') {
            return '';
        }

        if ($url !== null) {
            $inner = sprintf('<a href="%s" style="text-decoration:none;color:%s;">%s</a>', $url, self::THEME['text'], $inner);
        }

        return $this->cell($inner, $align, '32px 32px 16px 32px');
    }

    /**
     * Text: eine optionale Überschrift und Absätze.
     *
     * Der Fliesstext wird **escaped** und dann an Leerzeilen in Absätze
     * geteilt, einzelne Umbrüche werden `<br>`. Das ist die ehrliche Zusage
     * eines Baukastens: was hier steht, ist Text, kein Markup. Wer HTML
     * schreiben will, legt ein Layout vom Typ `html` an — genau dafür gibt es
     * den zweiten Typ.
     *
     * Antlers-Platzhalter überleben das Escaping: `htmlspecialchars` fasst
     * geschweifte Klammern nicht an, `{{ first_name }}` kommt unverändert
     * durch und wird beim Senden gefüllt.
     *
     * @param  array<string, mixed>  $block
     */
    protected function text(array $block): string
    {
        $align = $this->align($block['align'] ?? 'left');
        $parts = [];

        if ($heading = trim((string) ($block['heading'] ?? ''))) {
            $parts[] = sprintf(
                '<h2 style="margin:0 0 12px 0;font-family:%s;font-size:22px;line-height:30px;font-weight:700;color:%s;">%s</h2>',
                self::THEME['font'],
                self::THEME['text'],
                $this->text_($heading),
            );
        }

        foreach ($this->paragraphs((string) ($block['text'] ?? '')) as $paragraph) {
            $parts[] = sprintf(
                '<p style="margin:0 0 16px 0;font-family:%s;font-size:16px;line-height:26px;color:%s;">%s</p>',
                self::THEME['font'],
                self::THEME['text'],
                $paragraph,
            );
        }

        if ($parts === []) {
            return '';
        }

        return $this->cell(implode("\n", $parts), $align, '8px 32px');
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function image(array $block): string
    {
        $src = $this->imageSource($block['image'] ?? null);

        if ($src === null) {
            // Ein Bild-Baustein ohne auflösbares Bild ist keine leere Zeile in
            // der Mail, sondern eine fehlende. Wer ihn gerade ausgefüllt hat,
            // erfährt es hier und nicht im Postfach.
            if (($block['image'] ?? null) !== null && $block['image'] !== '') {
                $this->warn('warning_image_unresolved', (string) __('marketing::templates.block_image'));
            }

            return '';
        }

        $align = $this->align($block['align'] ?? 'center');

        $img = $this->img(
            $src,
            (string) ($block['alt'] ?? ''),
            $this->pixels($block['width'] ?? null, (int) self::THEME['width'] - 64, 20, (int) self::THEME['width']),
            $align,
            self::THEME['radius'],
        );

        if ($url = $this->url($block['link_url'] ?? null)) {
            $img = sprintf('<a href="%s" style="text-decoration:none;">%s</a>', $url, $img);
        }

        return $this->cell($img, $align, '16px 32px');
    }

    /**
     * Ein Bild, so wie ein Postfach eines haben will.
     *
     * `width` als Attribut **und** im Style: Outlook liest das Attribut, alles
     * andere den Style, und ohne das Attribut zieht Outlook das Bild auf seine
     * native Pixelbreite — bei einem Retina-Logo ist das die doppelte.
     *
     * Ausgerichtet über die Ränder und nicht über `text-align`: ein
     * Blockelement folgt dem nicht, und `margin` ist der einzige Weg, den Word
     * versteht. `display:block` muss bleiben, sonst setzt manches Postfach
     * eine Zeile unter das Bild, wo keine hingehört.
     */
    protected function img(string $src, string $alt, int $width, string $align, string $radius = '0'): string
    {
        $margin = match ($align) {
            'center' => '0 auto',
            'right' => '0 0 0 auto',
            default => '0',
        };

        return sprintf(
            '<img src="%s" alt="%s" width="%d" style="display:block;margin:%s;border:0;outline:none;'
                .'text-decoration:none;width:%dpx;max-width:100%%;height:auto;border-radius:%s;">',
            $src,
            $this->attr($alt),
            $width,
            $margin,
            $width,
            $radius,
        );
    }

    /**
     * Knopf, als Tabelle mit `bgcolor` und nicht als gestyltes `<a>`.
     *
     * Ein `<a>` mit `padding` und `background` ist in Outlook ein farbloser
     * Link: Word rendert weder das eine noch das andere an einem Inline-
     * Element. Die einzige Bauform, die überall ankommt, ist eine
     * einzellige Tabelle, die ihre Farbe über das `bgcolor`-Attribut trägt,
     * mit dem Link als Blockelement darin.
     *
     * Ohne Ziel wird kein Knopf gebaut: ein Knopf, der nirgendwohin führt,
     * ist in einer Mail kein Gestaltungselement, sondern ein toter Klick.
     *
     * @param  array<string, mixed>  $block
     */
    protected function button(array $block): string
    {
        $label = trim((string) ($block['label'] ?? ''));
        $url = $this->url($block['url'] ?? null);

        if ($label === '' || $url === null) {
            // Ein halb ausgefüllter Knopf ist der Normalzustand eines Layouts,
            // an dem gerade gearbeitet wird, und darf nichts melden. Einer mit
            // Beschriftung *und* Ziel, der trotzdem herausfällt, ist der
            // stille Fehler: das Ziel ist unbrauchbar, und ohne diese Zeile
            // stünde nirgends, warum der Knopf fehlt.
            if ($label !== '' && trim((string) ($block['url'] ?? '')) !== '') {
                $this->warn('warning_button_url', $label);
            }

            return '';
        }

        $align = $this->align($block['align'] ?? 'left');

        $button = sprintf(
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;">'
                .'<tr><td class="m-btn" bgcolor="%s" style="background-color:%s;border-radius:%s;">'
                .'<a href="%s" style="display:inline-block;padding:14px 28px;font-family:%s;font-size:16px;line-height:20px;font-weight:600;color:%s;text-decoration:none;border-radius:%s;">%s</a>'
                .'</td></tr></table>',
            self::THEME['button_background'],
            self::THEME['button_background'],
            self::THEME['radius'],
            $url,
            self::THEME['font'],
            self::THEME['button_text'],
            self::THEME['radius'],
            $this->text_($label),
        );

        // Die äussere Zelle richtet aus; `margin:auto` an einer Tabelle ist in
        // Outlook wirkungslos, `align` am `<td>` nicht.
        return $this->cell($button, $align, '16px 32px', true);
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function divider(array $block): string
    {
        $space = match ($block['spacing'] ?? 'medium') {
            'small' => '8px',
            'large' => '32px',
            default => '20px',
        };

        // Eine Zelle mit Höhe 1 und Hintergrundfarbe, kein `<hr>`: `<hr>`
        // rendert in Outlook in einer eigenen, nicht abschaltbaren Farbe.
        return sprintf(
            '<tr><td class="m-cell" style="padding:%s 32px;"><table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">'
                .'<tr><td class="m-rule" height="1" bgcolor="%s" style="height:1px;line-height:1px;font-size:1px;background-color:%s;">&nbsp;</td></tr>'
                .'</table></td></tr>',
            $space,
            self::THEME['rule'],
            self::THEME['rule'],
        );
    }

    /**
     * @param  array<string, mixed>  $block
     */
    protected function spacer(array $block): string
    {
        $height = $this->pixels($block['height'] ?? null, 24, 1, 200);

        // `font-size` und `line-height` mit: eine leere Zelle bekommt in
        // Outlook sonst die Zeilenhöhe des Dokuments dazu und ist höher als
        // bestellt.
        return sprintf(
            '<tr><td class="m-cell" height="%d" style="height:%dpx;line-height:%dpx;font-size:%dpx;">&nbsp;</td></tr>',
            $height,
            $height,
            $height,
            $height,
        );
    }

    /**
     * Das Loch, in das die Kampagne fällt.
     *
     * Antlers und nicht ein Platzhalter eigener Erfindung: der Renderer setzt
     * `{{ content }}`, und das Block-Layout geht durch denselben Renderer wie
     * jedes handgeschriebene. Genau ein solcher Block ist erlaubt und einer
     * ist Pflicht — ein Layout ohne Inhaltsplatz sendet einen Rahmen um
     * nichts, egal womit es gebaut wurde.
     */
    protected function content(): string
    {
        return sprintf(
            '<tr><td class="m-cell" align="left" style="padding:8px 32px;font-family:%s;font-size:16px;line-height:26px;color:%s;">{{ content }}</td></tr>',
            self::THEME['font'],
            self::THEME['text'],
        );
    }

    /**
     * Fuss: Pflichtangaben und der Abmeldelink.
     *
     * Der Abmeldelink ist standardmässig an und sollte es bleiben. Ausschalten
     * lässt er sich, weil dasselbe Layout auch ein transaktionales sein kann,
     * bei dem es nichts abzubestellen gibt — {@see TemplatePreview::findings()}
     * sagt in dem Fall trotzdem Bescheid, als Warnung und nicht als Fehler.
     *
     * @param  array<string, mixed>  $block
     */
    protected function footer(array $block): string
    {
        $parts = [];

        foreach ($this->paragraphs((string) ($block['text'] ?? '')) as $paragraph) {
            $parts[] = sprintf(
                '<p class="m-muted" style="margin:0 0 8px 0;font-family:%s;font-size:12px;line-height:20px;color:%s;">%s</p>',
                self::THEME['font'],
                self::THEME['muted'],
                $paragraph,
            );
        }

        if (($block['show_unsubscribe'] ?? true) !== false) {
            $label = trim((string) ($block['unsubscribe_label'] ?? '')) ?: __('marketing::mail.footer_unsubscribe');

            $parts[] = sprintf(
                '<p class="m-muted" style="margin:0;font-family:%s;font-size:12px;line-height:20px;color:%s;">'
                    .'<a href="{{ unsubscribe_url }}" style="color:%s;text-decoration:underline;">%s</a></p>',
                self::THEME['font'],
                self::THEME['muted'],
                self::THEME['muted'],
                $this->text_($label),
            );
        }

        if ($parts === []) {
            return '';
        }

        return sprintf(
            '<tr><td class="m-cell m-foot" align="center" style="padding:24px 32px 32px 32px;border-top:1px solid %s;">%s</td></tr>',
            self::THEME['rule'],
            implode("\n", $parts),
        );
    }

    /**
     * Eine Blockzeile mit Innenabstand.
     *
     * `$raw` unterscheidet Text von eingebettetem Markup: eine Textzelle setzt
     * ihre Schrift selbst, eine Zelle, die nur eine Knopf-Tabelle hält, darf
     * das nicht, sonst erbt der Knopf die Zeilenhöhe der Zelle.
     *
     * `m-cell` trägt jede Zelle dieser Ebene, und nur diese Ebene — siehe
     * {@see self::document()}, wo die Medienabfragen darauf zeigen.
     */
    protected function cell(string $inner, string $align, string $padding, bool $raw = false): string
    {
        $font = $raw ? '' : sprintf('font-family:%s;color:%s;', self::THEME['font'], self::THEME['text']);

        return sprintf(
            '<tr><td class="m-cell" align="%s" style="padding:%s;%s">%s</td></tr>',
            $align,
            $padding,
            $font,
            $inner,
        );
    }

    /**
     * Das Gerüst um die Blöcke.
     *
     * Die äussere Tabelle färbt die Seite — `background` am `<body>` ignoriert
     * Outlook. Die innere ist auf 600 Pixel festgenagelt, mit `width` als
     * Attribut für Outlook und `max-width` im Style für alles andere.
     *
     * Das eine `<style>` trägt zwei Dinge und beide sind Zugabe: unter 600
     * Pixel Breite schrumpft die innere Tabelle, und im Dunkel-Modus drehen
     * sich die Flächen. Wer den Kopf verwirft, bekommt die helle Fassung in
     * voller Breite, und die ist vollständig. Genau das macht der Hell/Dunkel-
     * Schalter der Vorschau sichtbar, ohne dass irgendjemand sein Telefon
     * umstellen muss.
     *
     * **Beide Medienabfragen zeigen auf `td.m-cell` und nicht auf `td`**, und
     * das ist der Unterschied zwischen "sieht im Entwurf gut aus" und "kommt
     * an". `.m-shell td` ist ein Nachfahrenselektor: er trifft auch die Zellen
     * der verschachtelten Tabellen, und mit `!important` schlägt er sowohl den
     * Inline-Style als auch das `bgcolor`-Attribut. Der Knopf bekäme im
     * Dunkel-Modus die Blattfarbe und verschwände, der Trenner — der *ist*
     * eine 1 Pixel hohe farbige Zelle — ebenfalls, und auf dem Telefon bekäme
     * die Knopffläche 40 Pixel Innenabstand dazu, die niemand gebaut hat.
     *
     * `m-cell` trägt deshalb genau eine Ebene: die Blockzeilen der äusseren
     * Tabelle. Was darunter eine eigene Bedeutung hat, bekommt seine eigene
     * Klasse und seine eigene Zeile:
     *
     * - `m-btn` — helle Fläche, dunkle Schrift. Mit dem Grundton bliebe er ein
     *   fast schwarzer Kasten auf dunkelgrauem Blatt: vorhanden, nicht zu
     *   sehen.
     * - `m-rule` — ein Grau, das auf dem dunklen Blatt noch eine Linie ist.
     * - `m-foot` — die Linie über dem Fuß ist ein Rahmen und keine Fläche; in
     *   Hellgrau auf dunklem Blatt wäre sie der hellste Strich der Mail.
     *
     * Alle im Bild geprüft, nicht überlegt.
     */
    protected function document(string $rows): string
    {
        $t = self::THEME;

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 620px) {
    .m-shell { width: 100% !important; }
    .m-shell td.m-cell { padding-left: 20px !important; padding-right: 20px !important; }
}
@media (prefers-color-scheme: dark) {
    .m-page, .m-page td { background-color: #18181b !important; }
    .m-shell, .m-shell td.m-cell { background-color: #27272a !important; }
    .m-shell td.m-cell, .m-shell p, .m-shell h2, .m-shell span { color: #fafafa !important; }
    .m-shell p.m-muted, .m-shell p.m-muted a { color: #a1a1aa !important; }
    .m-shell td.m-btn { background-color: #fafafa !important; }
    .m-shell td.m-btn a { color: #18181b !important; }
    .m-shell td.m-rule { background-color: #3f3f46 !important; }
    .m-shell td.m-foot { border-top-color: #3f3f46 !important; }
}
</style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:{$t['page_background']};-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
<table role="presentation" class="m-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{$t['page_background']};">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" class="m-shell" width="{$t['width']}" cellpadding="0" cellspacing="0" border="0" style="width:{$t['width']}px;max-width:100%;background-color:{$t['canvas']};border-radius:{$t['radius']};">
{$rows}
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    // ---------- Werte, die von aussen kommen ----------

    /**
     * Ein Bildwert zu einer absoluten URL.
     *
     * Zwei Formen, weil das Feld zwei Formen hat: wo die Site einen
     * Asset-Container hat, steht hier ein Asset-Pfad, sonst eine getippte URL
     * (siehe {@see LayoutBlocks::imageField()}).
     *
     * **Absolut, immer.** Ein Postfach hat keine Site, gegen die es einen
     * relativen Pfad auflösen könnte; `/assets/logo.png` ist in einer Mail ein
     * kaputtes Bild. Deshalb `absoluteUrl()` und nicht `url()`.
     */
    protected function imageSource(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if ($container = CampaignContentField::assetContainer()) {
            if ($asset = Asset::find($container.'::'.$value)) {
                // `makeAbsolute` und nicht `absoluteUrl()`: das steht nicht im
                // Vertrag, den `Asset::find()` zusagt, und ein Container mit
                // eigenem Treiber muss es nicht haben. Über den URL-Helfer
                // bekommt eine bereits absolute Adresse dasselbe Ergebnis.
                return $this->attr(URL::makeAbsolute($asset->url()));
            }
        }

        return $this->url($value);
    }

    /**
     * Eine Ziel-URL, oder nichts.
     *
     * Nur Schemata, die in einer Mail etwas zu suchen haben. Alles andere —
     * `javascript:`, `data:` — fällt heraus statt escaped zu werden: im
     * Postfach ist es inert, in der Vorschau hängt es sonst in einem iframe,
     * und ein Link, der nichts tut, ist ehrlicher als einer, der irgendwo
     * einmal doch etwas tut. Antlers-Platzhalter dürfen durch, das ist die
     * ganze Pointe von `{{ unsubscribe_url }}`.
     *
     * **`www.example.com` bekommt sein `https://` hier**, und das ist keine
     * Bequemlichkeit. Ohne diese Zeile fiele die häufigste echte Eingabe unter
     * "unbekanntes Schema", der Knopf verschwände aus der Mail, und der
     * Benutzer hätte Beschriftung und Ziel eingetippt und bekäme nichts. Ein
     * relativer Pfad bekommt es nicht: `/anmeldung` hat im Postfach keine
     * Site, gegen die es sich auflösen liesse, und wäre ein toter Klick.
     */
    protected function url(mixed $value): ?string
    {
        if (! is_string($value) || ($value = trim($value)) === '') {
            return null;
        }

        if (str_starts_with($value, '{{')) {
            return $this->attr($value);
        }

        foreach (self::SAFE_SCHEMES as $scheme) {
            if (stripos($value, $scheme) === 0) {
                return $this->attr($value);
            }
        }

        // Kein Schema, kein Pfad-Anfang, aber ein Punkt vor dem ersten
        // Schrägstrich: das ist ein Hostname, und gemeint ist https.
        if (preg_match('~^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9-]+)+(:\d+)?([/?#].*)?$~i', $value)) {
            return $this->attr('https://'.$value);
        }

        return null;
    }

    /**
     * Ein Baustein, der nicht in die Mail gekommen ist, mit dem Grund.
     */
    protected function warn(string $key, string $name): void
    {
        $this->warnings[] = __('marketing::templates.'.$key, ['name' => $name]);
    }

    /**
     * Fliesstext zu Absätzen.
     *
     * Leerzeile trennt Absätze, einzelner Umbruch wird `<br>`. Escaped wird
     * vorher, sonst wäre der eigene `<br>` das einzige Markup, das ein Autor
     * nicht mehr schreiben könnte — und jedes andere das einzige, das er
     * einschleusen könnte.
     *
     * @return array<int, string>
     */
    protected function paragraphs(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $chunk) => nl2br($this->text_(trim($chunk)), false),
            preg_split('/\n{2,}/', $text) ?: [],
        ), fn (string $chunk) => $chunk !== ''));
    }

    /**
     * Text für den Dokumentenkörper.
     *
     * `ENT_NOQUOTES`: Anführungszeichen in Fliesstext sind Anführungszeichen,
     * keine Attributgrenzen, und `&quot;` mitten im Satz ist in den Postfächern
     * zu sehen, die den Kopf wegwerfen.
     */
    protected function text_(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8', false);
    }

    /** Text für ein Attribut. Hier zählen die Anführungszeichen sehr wohl. */
    protected function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
    }

    /** Eine Pixelangabe, in den Grenzen, die ein 600er Umschlag hergibt. */
    protected function pixels(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** `left`, `center` oder `right` — und sonst `left`. */
    protected function align(mixed $value): string
    {
        return in_array($value, ['left', 'center', 'right'], true) ? $value : 'left';
    }
}
