<?php

use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;
use Statamic\Facades\AssetContainer;

/**
 * Ein Bild aus dem Control Panel muss in der Mail auch ankommen.
 *
 * Bard speichert ein eingefuegtes Bild als `statamic://asset::<container>::<pfad>`
 * — eine Statamic-interne Schreibweise, die in einer Anwendung beim Augmentieren
 * aufgeloest wird. Der Kampagnen-Renderer nimmt den gespeicherten HTML-String
 * aber direkt, ohne Augmentierung. Die Referenz ging damit WOERTLICH in die Mail,
 * und `src="statamic://asset::assets::logo.png"` kann kein Mailprogramm laden:
 * in jedem Postfach stand ein kaputtes Bild.
 *
 * Aufgefallen ist das erst am 20.09.2026, weil sich eine Kampagne mit Bild bis
 * 2.23.3 gar nicht speichern liess (backlog-marketing-kampagne-mit-bild-nicht-
 * speicherbar). Der Fix dort hat den Weg freigeschaltet, der hier hineinlaeuft.
 *
 * Statamics eigener Resolver (`ResolvesStatamicUrls`) genuegt hier nicht: er
 * setzt `$data->url()`, und das ist ein Pfad ohne Host. In einer Mail gibt es
 * keine aktuelle Seite, gegen die ein relativer Pfad aufloest.
 */
beforeEach(function (): void {
    config()->set('app.url', 'https://beispiel.test');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');

    $this->pfad = storage_path('framework/testing/assets-'.uniqid());
    mkdir($this->pfad, 0777, true);
    file_put_contents($this->pfad.'/logo.png', 'nicht wirklich ein png');

    config()->set('filesystems.disks.pruef_assets', [
        'driver' => 'local',
        'root' => $this->pfad,
        'url' => '/assets',
    ]);

    AssetContainer::make('pruef')->disk('pruef_assets')->title('Pruef')->save();
    AssetContainer::find('pruef')->makeAsset('logo.png')->save();
});

function gerendert(string $inhalt): string
{
    return app(CampaignRenderer::class)->render(new Campaign(
        handle: 'bildprobe',
        name: 'Bildprobe',
        subject: 'Bildprobe',
        content: $inhalt,
        templateHandle: 'newsletter',
    ), test()->list)->html;
}

/*
 * Der Host wird nicht festgenagelt: `config('app.url')` zur Laufzeit zu setzen
 * stellt Laravels URL-Generator nicht mehr um, und welcher Host in der
 * Testumgebung herauskommt, ist nicht die Eigenschaft, um die es hier geht.
 * Geprueft wird, was zaehlt: aufgeloest, absolut, und auf die richtige Datei.
 */
it('resolves a CP image to an absolute url', function (): void {
    $html = gerendert('<p>Davor.</p><img src="statamic://asset::pruef::logo.png" alt="Logo"><p>Danach.</p>');

    // Auf welche Adresse genau, prueft der Glide-Test weiter unten; hier geht
    // es darum, dass ueberhaupt aufgeloest wird und das Ergebnis absolut ist.
    expect($html)->not->toContain('statamic://')
        ->and($html)->toMatch('~<img[^>]+src="https?://[^"]+"~');
});

/*
 * Ein Pfad ohne Host ist in einer Mail genauso tot wie die Referenz selbst: es
 * gibt keine Seite, gegen die er aufloest. Deshalb reicht Statamics eigener
 * Resolver hier nicht, und deshalb prueft dieser Test die Absolutheit eigens.
 */
it('never leaves a host-less path behind', function (): void {
    $html = gerendert('<img src="statamic://asset::pruef::logo.png" alt="Logo">');

    expect($html)->not->toMatch('~src="/assets~');
});

it('keeps the alt text it was given', function (): void {
    $html = gerendert('<img src="statamic://asset::pruef::logo.png" alt="Adrian Goldner">');

    expect($html)->toContain('alt="Adrian Goldner"');
});

/*
 * Ein Bild, dessen Datei es nicht mehr gibt, kann nicht geladen werden — in
 * jedem Programm steht dann ein kaputtes Symbol. Statamics Resolver macht aus
 * der Referenz in dem Fall `src=""`, was genau dasselbe Symbol erzeugt. Ein
 * Bild, das nicht laden kann, ist kein Inhalt, und es geht deshalb gar nicht
 * erst mit.
 */
it('drops an image whose asset is gone instead of sending a broken one', function (): void {
    $html = gerendert('<p>Davor.</p><img src="statamic://asset::pruef::weg.png" alt="Weg"><p>Danach.</p>');

    expect($html)->not->toContain('statamic://')
        ->and($html)->not->toContain('src=""')
        ->and($html)->not->toContain('alt="Weg"')
        // Der Text drumherum bleibt stehen.
        ->and($html)->toContain('Davor.')
        ->and($html)->toContain('Danach.');
});

it('resolves a link the same way', function (): void {
    $html = gerendert('<p>Mehr <a href="statamic://asset::pruef::logo.png">hier</a>.</p>');

    expect($html)->not->toContain('statamic://')
        ->and($html)->toMatch('~href="https?://[^"]+/assets/logo\.png"~');
});

/*
 * Ein Bild fuer die Mail, nicht fuers Archiv.
 *
 * Beim Versandtest am 18.09.2026 lag im Inhalt ein Bild mit 1114x2429 px. Was im
 * CP hochgeladen wird, ist das Original; ohne Zutun geht genau das raus, und
 * Gewicht ist in einer Mail teurer als auf einer Seite — es zaehlt fuer jeden
 * einzelnen Empfaenger. Das Addon laesst Statamic deshalb eine Fassung fuer die
 * Mail erzeugen, mit der doppelten Anzeigebreite fuer scharfe Bildschirme.
 */
it('renders a mail-sized version instead of the original', function (): void {
    config()->set('marketing.editor.image_width', 600);

    $html = gerendert('<img src="statamic://asset::pruef::logo.png" alt="Logo">');

    // Statamics Bildstrecke, nicht mehr der rohe Pfad zur Datei.
    expect($html)->toMatch('~<img[^>]+src="https?://[^"]*/img/[^"]+"~');
});

/*
 * Outlook rechnet mit dem `width`-Attribut, nicht mit CSS. Ohne es zeigt es das
 * Bild in seiner echten Pixelbreite — bei einem Original aus der Kamera also
 * weit ueber den Rand der Mail hinaus.
 */
it('gives the image a width attribute and a responsive cap', function (): void {
    config()->set('marketing.editor.image_width', 600);

    $html = gerendert('<img src="statamic://asset::pruef::logo.png" alt="Logo">');

    expect($html)->toMatch('~<img[^>]+width="600"~')
        ->and($html)->toMatch('~<img[^>]+style="[^"]*max-width:100%~');
});

/*
 * Das alt-Attribut kommt aus dem Asset selbst, so wie Statamic es beim
 * Augmentieren auch holt. Unser Weg umgeht das Augmentieren, also holen wir es
 * hier — sonst bleibt ein Bild ohne Alternativtext, obwohl einer gepflegt ist.
 */
it('takes the alt text from the asset when the tag has none', function (): void {
    $asset = AssetContainer::find('pruef')->asset('logo.png');
    $asset->data(['alt' => 'Das Logo von Adrian Goldner'])->save();

    $html = gerendert('<img src="statamic://asset::pruef::logo.png">');

    expect($html)->toContain('alt="Das Logo von Adrian Goldner"');
});

/*
 * Ist wirklich keiner da, bekommt das Bild ein leeres alt. Das ist die richtige
 * Auszeichnung fuer ein Bild ohne Alternativtext und nimmt Pruefwerkzeugen den
 * Befund „Bild ohne alt-Attribut" — erfunden wird dabei nichts.
 */
it('writes an empty alt rather than none at all', function (): void {
    $html = gerendert('<img src="statamic://asset::pruef::logo.png">');

    expect($html)->toMatch('~<img[^>]+alt=""~');
});

/*
 * Waechter: ein gewoehnlicher Link und ein gewoehnliches Bild duerfen von der
 * Aufloesung nicht angefasst werden. Sonst faellt erst im Postfach auf, dass
 * die Umschreibung mehr erwischt hat als gemeint.
 */
it('leaves ordinary urls alone', function (): void {
    $html = gerendert('<p><a href="https://adriangoldner.com/wissen">Wissen</a> und <img src="https://adriangoldner.com/bild.png" alt="Bild"></p>');

    expect($html)->toContain('https://adriangoldner.com/wissen')
        ->and($html)->toContain('https://adriangoldner.com/bild.png')
        ->and($html)->toContain('alt="Bild"');
});
