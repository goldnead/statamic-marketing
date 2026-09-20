<?php

namespace Goldnead\Marketing\Support;

use Statamic\Facades\Data;

/**
 * Turns Statamic's internal `statamic://` references into URLs a mail client can open.
 *
 * THE DEFECT THIS EXISTS FOR
 *
 * Bard stores an image inserted in the Control Panel as
 * `statamic://asset::<container>::<path>`. In an application that reference is
 * resolved when the field is augmented; the campaign renderer takes the stored
 * HTML string directly and never augments anything. So the reference went into
 * the mail verbatim, and `src="statamic://asset::assets::logo.png"` is a broken
 * image in every mailbox that receives it.
 *
 * It surfaced only on 20.09.2026 because until 2.23.3 a campaign with an image
 * could not be saved at all. Fixing that opened the path that leads here.
 *
 * WHY NOT STATAMIC'S OWN RESOLVER
 *
 * `Statamic\Fieldtypes\Concerns\ResolvesStatamicUrls` does the same lookup but
 * writes `$data->url()` — a path with no host. On a web page that is exactly
 * right; in a mail there is no current page for a relative path to resolve
 * against, so it is as dead as the reference it replaced. Hence `absoluteUrl()`,
 * with `app.url` as the fallback for anything that has no absolute form.
 *
 * WHAT HAPPENS TO A REFERENCE THAT NO LONGER RESOLVES
 *
 * Statamic's resolver leaves `src=""` behind, which draws the same broken icon
 * as the reference did. An image that cannot load is not content, so the whole
 * `<img>` goes. A link keeps its text and loses its href: the sentence it sits
 * in is still worth reading.
 */
final class StatamicReferences
{
    /** Matches a whole `<img>` whose `src` is a Statamic reference. */
    private const BILD = '~<img\b[^>]*\bsrc\s*=\s*(["\'])statamic://(?<ref>[^"\'?#]*)(?<rest>[^"\']*)\1[^>]*/?>~i';

    /** Matches the reference inside any other attribute (`href`, a second `src`). */
    private const VERWEIS = '~(?<vor>["\'])statamic://(?<ref>[^"\'?#]*)(?<rest>[^"\']*)(?<nach>["\'])~i';

    public static function toAbsoluteUrls(string $html): string
    {
        // Bilder zuerst: ein Bild, das nicht aufloest, faellt als Ganzes weg,
        // und danach gibt es in seinem Tag nichts mehr zu ersetzen.
        $html = (string) preg_replace_callback(self::BILD, function (array $treffer): string {
            $asset = Data::find($treffer['ref']);

            if (! $asset) {
                return '';
            }

            $url = self::mailfassung($asset, $treffer['rest']);

            if ($url === null) {
                return '';
            }

            return self::bildtag($treffer[0], $url, $asset);
        }, $html);

        return (string) preg_replace_callback(self::VERWEIS, function (array $treffer): string {
            $url = self::url(Data::find($treffer['ref']), $treffer['rest']);

            return $treffer['vor'].($url ?? '').$treffer['nach'];
        }, $html);
    }

    /**
     * Der fertige `<img>`-Tag: neue Adresse, Breite fuer Outlook, Alternativtext.
     *
     * **`width` statt CSS**, weil Outlook das Attribut liest und die Regel
     * nicht. Ohne es zeigt es das Bild in seiner echten Pixelbreite, bei einem
     * Original aus der Kamera also weit ueber den Rand der Mail hinaus. Das
     * `max-width:100%` daneben ist fuer alle anderen, damit das Bild am Handy
     * mitschrumpft.
     *
     * **Der Alternativtext kommt aus dem Asset**, so wie Statamic ihn beim
     * Augmentieren holt (`Bard\ImageNode::getAlt()`). Unser Weg umgeht das
     * Augmentieren, also wird er hier geholt. Steht am Tag schon einer, gilt
     * der — er ist die naehere Angabe.
     *
     * Ist gar keiner da, wird `alt=""` gesetzt statt gar nichts. Das ist die
     * richtige Auszeichnung fuer ein Bild ohne Alternativtext und nimmt
     * Pruefwerkzeugen den Befund „Bild ohne alt-Attribut"; erfunden wird nichts.
     */
    private static function bildtag(string $tag, string $url, mixed $asset): string
    {
        $tag = str_replace('statamic://', '', $tag);
        $tag = (string) preg_replace('~\bsrc\s*=\s*(["\'])[^"\']*\1~i', 'src="'.htmlspecialchars($url, ENT_QUOTES).'"', $tag, 1);

        if (preg_match('~\balt\s*=~i', $tag) !== 1) {
            $alt = '';

            if (method_exists($asset, 'data')) {
                $alt = (string) ($asset->data()->get('alt') ?? '');
            }

            $tag = self::attributAnhaengen($tag, 'alt', $alt);
        }

        if (preg_match('~\bwidth\s*=~i', $tag) !== 1) {
            $tag = self::attributAnhaengen($tag, 'width', (string) (int) config('marketing.editor.image_width', 600));
        }

        if (preg_match('~\bstyle\s*=~i', $tag) !== 1) {
            $tag = self::attributAnhaengen($tag, 'style', 'max-width:100%; height:auto;');
        }

        return $tag;
    }

    private static function attributAnhaengen(string $tag, string $name, string $wert): string
    {
        $neu = ' '.$name.'="'.htmlspecialchars($wert, ENT_QUOTES).'"';

        return (string) preg_replace('~\s*/?>$~', $neu.'>', $tag, 1);
    }

    /**
     * Die Fassung, die in die Mail geht — nicht das Original.
     *
     * Was im CP hochgeladen wird, ist die Datei aus der Kamera: beim
     * Versandtest am 18.09.2026 lag im Inhalt ein Bild mit 1114x2429 px.
     * Gewicht ist in einer Mail teurer als auf einer Seite, weil es fuer jeden
     * einzelnen Empfaenger anfaellt, und niemand laedt in einem Postfach ein
     * Bild nach. Statamic erzeugt deshalb eine Fassung in der doppelten
     * Anzeigebreite — doppelt, damit sie auf scharfen Bildschirmen nicht weich
     * wird.
     *
     * Nur fuer Bilder. Eine PDF-Datei am selben Weg behaelt ihre Adresse.
     */
    private static function mailfassung(mixed $asset, string $rest): ?string
    {
        $istBild = method_exists($asset, 'isImage') && $asset->isImage();

        if ($istBild && method_exists($asset, 'manipulate')) {
            $breite = (int) config('marketing.editor.image_width', 600);

            // Schlaegt die Bildstrecke fehl (fehlende GD-Erweiterung, eine
            // Datei, die kein Bild ist), bleibt die normale Adresse. Ein
            // groesseres Bild ist besser als keins.
            try {
                $erzeugt = $asset->manipulate(['w' => $breite * 2, 'fit' => 'max']);

                if (is_string($erzeugt) && $erzeugt !== '') {
                    return self::absolut($erzeugt);
                }
            } catch (\Throwable) {
                // absichtlich weiter unten mit der normalen Adresse
            }
        }

        return self::url($asset, $rest);
    }

    /** Macht eine Adresse absolut, falls sie es nicht schon ist. */
    private static function absolut(string $url): string
    {
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /** The absolute URL behind a reference, or null when it resolves to nothing. */
    private static function url(mixed $data, string $rest): ?string
    {
        if (! $data) {
            return null;
        }

        $url = method_exists($data, 'absoluteUrl') ? $data->absoluteUrl() : null;

        // Ein Eintrag ohne eigene URL (Inertia-Route, Entwurf) hat keine
        // absolute Form. Dann bleibt der Pfad, an `app.url` gehaengt.
        if (! $url) {
            $pfad = method_exists($data, 'url') ? $data->url() : null;

            if (! $pfad) {
                return null;
            }

            $url = rtrim((string) config('app.url'), '/').'/'.ltrim((string) $pfad, '/');
        }

        return $url.$rest;
    }
}
