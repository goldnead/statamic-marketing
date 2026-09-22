<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Exceptions\BlockValuesRejected;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Services\BlockLayoutCompiler;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;
use Goldnead\Marketing\Support\LayoutBlocks;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\User;

/**
 * Der Baukasten, und der Zuschnitt, der ihn klein gehalten hat.
 *
 * Bloecke sind eine **zweite Eingabe, kein zweiter Ausgang**: beim Speichern
 * werden sie zu Mail-HTML uebersetzt und landen in derselben `html`-Spalte,
 * die ein handgeschriebenes Layout auch fuellt. Das ist keine Feinheit der
 * Umsetzung, sondern die Abnahmepruefung dieses Tickets — deshalb steht hier
 * ein Test, der eine echte Kampagne mit einem Block-Layout durch den echten
 * Versand schickt und nachsieht, dass der Renderer nichts davon gemerkt hat.
 *
 * Die zweite Haelfte ist die Falle: `html` ist fuer ein Block-Layout ein
 * **abgeleiteter** Wert. Wer die Spalte von Hand aendert, verliert es beim
 * naechsten Speichern. Dass das wirklich so ist — und nicht nur so gemeint —
 * steht weiter unten als eigener Test.
 */
beforeEach(function (): void {
    $user = User::make()->email('baukasten@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    /** Ein sendbares Block-Layout: Kopf, das Loch, Fuss. */
    $this->blocks = fn (array $extra = []): array => [
        ['_id' => 'a1', 'type' => LayoutBlocks::SET_HEADER, 'enabled' => true, 'brand_name' => 'Chorwerkstatt', 'align' => 'left'],
        ...$extra,
        ['_id' => 'b2', 'type' => LayoutBlocks::SET_CONTENT, 'enabled' => true],
        ['_id' => 'c3', 'type' => LayoutBlocks::SET_FOOTER, 'enabled' => true, 'text' => 'Chorwerkstatt, Musterweg 1', 'show_unsubscribe' => true],
    ];

    $this->store = fn (array $payload) => $this->post(cp_route('marketing.templates.store'), $payload);
});

it('speichert ein Block-Layout als das HTML, das der Uebersetzer liefert', function (): void {
    $blocks = ($this->blocks)();

    ($this->store)([
        'name' => 'Baukasten',
        'handle' => 'baukasten',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => $blocks,
    ])->assertRedirect();

    $template = app(EmailTemplateRepository::class)->find('baukasten');

    expect($template)->not->toBeNull()
        ->and($template->type)->toBe(EmailTemplate::TYPE_BLOCKS)
        ->and($template->blocks)->toHaveCount(3);

    // Zeichen fuer Zeichen das, was der Uebersetzer aus den gespeicherten
    // Bloecken macht. Nicht „enthaelt ungefaehr": waere es etwas anderes,
    // haette irgendwo eine zweite Auslegung der Bloecke mitgeschrieben.
    expect($template->html)->toBe(app(BlockLayoutCompiler::class)->compile($template->blocks));

    expect($template->html)->toContain('{{ content }}')
        ->and($template->html)->toContain('Chorwerkstatt')
        ->and($template->html)->toContain('{{ unsubscribe_url }}');
});

it('laesst ein HTML-Layout beim Speichern Zeichen fuer Zeichen gleich', function (): void {
    // Der Uebersetzer darf diesen Weg nie anfassen. Wer rohes HTML schreibt,
    // bekommt sein HTML zurueck — auch das schlecht eingerueckte, auch den
    // Kommentar, auch die Leerzeile.
    $html = "<!DOCTYPE html>\n<html>\n<!-- von Hand -->\n<body>   {{ content }}\n\n</body>\n</html>";

    ($this->store)([
        'name' => 'Handarbeit',
        'handle' => 'handarbeit',
        'type' => EmailTemplate::TYPE_HTML,
        'html' => $html,
    ])->assertRedirect();

    $template = app(EmailTemplateRepository::class)->find('handarbeit');

    expect($template->html)->toBe($html)
        ->and($template->type)->toBe(EmailTemplate::TYPE_HTML)
        ->and($template->blocks)->toBe([]);

    // Und auch nach einem zweiten Speichern, bei dem sich nur der Name aendert.
    $this->patch(cp_route('marketing.templates.update', 'handarbeit'), [
        'name' => 'Handarbeit, umbenannt',
        'html' => $html,
    ])->assertRedirect();

    expect(app(EmailTemplateRepository::class)->find('handarbeit')->html)->toBe($html);
});

it('behaelt die Bloecke, wenn ein Aufruf nur umbenennen will', function (): void {
    // Der Editor schickt `blocks` immer mit. Ein Aufruf ueber die API, der nur
    // den Namen aendert, bekaeme sonst „es fehlt der Inhalts-Baustein" — eine
    // Behauptung ueber etwas, das er gar nicht angefasst hat.
    ($this->store)([
        'name' => 'Umbenennen',
        'handle' => 'umbenennen',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => ($this->blocks)(),
    ])->assertRedirect();

    $vorher = app(EmailTemplateRepository::class)->find('umbenennen');

    $this->patch(cp_route('marketing.templates.update', 'umbenennen'), [
        'name' => 'Anders benannt',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $nachher = app(EmailTemplateRepository::class)->find('umbenennen');

    expect($nachher->name)->toBe('Anders benannt')
        ->and($nachher->blocks)->toBe($vorher->blocks)
        ->and($nachher->html)->toBe($vorher->html);
});

it('lehnt ein Block-Layout ohne Inhalts-Block ab, am Feld blocks', function (): void {
    // Ein Layout ohne Inhaltsplatz verschickt einen Rahmen um nichts: die
    // Kampagne ist geschrieben, der Versand meldet Erfolg, und jeder Abonnent
    // bekommt eine leere Mail. Das faellt sonst erst im Postfach auf.
    ($this->store)([
        'name' => 'Ohne Loch',
        'handle' => 'ohne_loch',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [
            ['_id' => 'a1', 'type' => LayoutBlocks::SET_HEADER, 'enabled' => true, 'brand_name' => 'Chorwerkstatt'],
        ],
    ])->assertSessionHasErrors('blocks');

    expect(app(EmailTemplateRepository::class)->find('ohne_loch'))->toBeNull();
});

it('lehnt zwei Inhalts-Bloecke ab', function (): void {
    // Teurer als keiner: die Kampagne kaeme doppelt an, und das merkt zuerst
    // der Empfaenger.
    ($this->store)([
        'name' => 'Zweimal',
        'handle' => 'zweimal',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [
            ['_id' => 'a1', 'type' => LayoutBlocks::SET_CONTENT, 'enabled' => true],
            ['_id' => 'b2', 'type' => LayoutBlocks::SET_CONTENT, 'enabled' => true],
        ],
    ])->assertSessionHasErrors('blocks');

    expect(app(EmailTemplateRepository::class)->find('zweimal'))->toBeNull();
});

it('zaehlt einen abgeschalteten Inhalts-Block nicht mit', function (): void {
    // Eine abgeschaltete Zeile rendert nicht. Ein Layout, dessen einziger
    // Inhalts-Block abgeschaltet ist, ist genau so kaputt wie eines ohne.
    ($this->store)([
        'name' => 'Abgeschaltet',
        'handle' => 'abgeschaltet',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [
            ['_id' => 'a1', 'type' => LayoutBlocks::SET_CONTENT, 'enabled' => false],
        ],
    ])->assertSessionHasErrors('blocks');
});

it('aendert das html mit, wenn sich die Bloecke aendern', function (): void {
    // DIE FALLE. `html` ist fuer ein Block-Layout ein abgeleiteter Wert: wer
    // die Spalte von Hand aendert, verliert es beim naechsten Speichern. Der
    // Test haelt beide Haelften fest — dass die Aenderung an den Bloecken
    // ankommt, und dass die Handarbeit an der Spalte verschwindet.
    ($this->store)([
        'name' => 'Wandelbar',
        'handle' => 'wandelbar',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => ($this->blocks)(),
    ])->assertRedirect();

    $templates = app(EmailTemplateRepository::class);
    $vorher = $templates->find('wandelbar')->html;

    // Von Hand in die abgeleitete Spalte geschrieben, so wie es jemand ueber
    // die API oder in der Datenbank taete.
    $handarbeit = $templates->find('wandelbar');
    $handarbeit->html = '<p>von Hand</p>';
    $templates->save($handarbeit);
    expect($templates->find('wandelbar')->html)->toBe('<p>von Hand</p>');

    // Ein Knopf dazu, und speichern.
    $this->patch(cp_route('marketing.templates.update', 'wandelbar'), [
        'name' => 'Wandelbar',
        'blocks' => ($this->blocks)([[
            '_id' => 'x9',
            'type' => LayoutBlocks::SET_BUTTON,
            'enabled' => true,
            'label' => 'Zur Anmeldung',
            'url' => 'https://example.com/anmeldung',
            'align' => 'center',
        ]]),
    ])->assertRedirect();

    $nachher = $templates->find('wandelbar')->html;

    expect($nachher)->not->toBe($vorher)
        ->and($nachher)->not->toContain('von Hand')
        ->and($nachher)->toContain('Zur Anmeldung')
        ->and($nachher)->toContain('https://example.com/anmeldung');
});

it('laesst den Typ nach dem Anlegen nicht wechseln', function (): void {
    // Es gaebe keinen ehrlichen Weg zurueck: HTML liesse sich nur in Bloecke
    // raten, und ein Umschalten auf HTML wuerfe die Bloecke ersatzlos weg.
    // Beides genau einmal, und unumkehrbar.
    ($this->store)([
        'name' => 'Fest',
        'handle' => 'fest',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => ($this->blocks)(),
    ])->assertRedirect();

    $this->patch(cp_route('marketing.templates.update', 'fest'), [
        'name' => 'Fest',
        'type' => EmailTemplate::TYPE_HTML,
        'html' => '<p>{{ content }}</p>',
    ])->assertSessionHasErrors('type');

    $template = app(EmailTemplateRepository::class)->find('fest');

    expect($template->type)->toBe(EmailTemplate::TYPE_BLOCKS)
        ->and($template->blocks)->not->toBe([])
        ->and($template->html)->not->toBe('<p>{{ content }}</p>');

    // Und andersherum.
    ($this->store)([
        'name' => 'Auch fest',
        'handle' => 'auch_fest',
        'type' => EmailTemplate::TYPE_HTML,
        'html' => '<p>{{ content }}</p>',
    ])->assertRedirect();

    $this->patch(cp_route('marketing.templates.update', 'auch_fest'), [
        'name' => 'Auch fest',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => ($this->blocks)(),
    ])->assertSessionHasErrors('type');

    expect(app(EmailTemplateRepository::class)->find('auch_fest')->type)->toBe(EmailTemplate::TYPE_HTML);
});

it('rendert und verschickt eine Kampagne mit Block-Layout wie jede andere', function (): void {
    // Der Beleg, dass der Zuschnitt gehalten hat. Renderer, Versand, Snapshot
    // und Archiv wurden nicht angefasst — und sie merken nichts, weil sie
    // weiterhin genau einen HTML-String lesen.
    Mail::fake();

    ($this->store)([
        'name' => 'Versand',
        'handle' => 'versand_layout',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => ($this->blocks)(),
    ])->assertRedirect();

    $list = new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false);
    app(MailingListRepository::class)->save($list);
    $list = app(MailingListRepository::class)->find('newsletter');

    $jane = app(SubscriptionService::class)->subscribe($list, 'jane@example.com', ['first_name' => 'Jane']);

    $campaign = new Campaign(
        handle: 'block-kampagne',
        name: 'Block-Kampagne',
        subject: 'Probe',
        listHandle: 'newsletter',
        templateHandle: 'versand_layout',
        content: '<p>Hallo {{ first_name }}.</p>',
    );

    $rendered = app(CampaignRenderer::class)->render($campaign, $list, $jane);

    expect($rendered->html)->toContain('Hallo Jane.')
        ->and($rendered->html)->toContain('Chorwerkstatt')
        // Der Platzhalter ist gefuellt und nicht mehr da: der Renderer hat das
        // Block-HTML genau so behandelt wie handgeschriebenes.
        ->and($rendered->html)->not->toContain('{{ content }}')
        ->and($rendered->html)->not->toContain('{{ unsubscribe_url }}');

    Mail::to('jane@example.com')->send(new CampaignMail($campaign, $rendered));

    Mail::assertSent(CampaignMail::class);
});

it('baut Mail-HTML und keine Browser-Boxen', function (): void {
    // Ein Postfach ist ein zwanzig Jahre alter Renderer mit Hausrecht. Was
    // hier geprueft wird, sind die Regeln, die daraus folgen — nicht der
    // Geschmack, sondern das, was ankommt.
    $html = app(BlockLayoutCompiler::class)->compile([
        ['type' => LayoutBlocks::SET_HEADER, 'brand_name' => 'Marke'],
        ['type' => LayoutBlocks::SET_TEXT, 'heading' => 'Hallo', 'text' => "Erster Absatz.\n\nZweiter Absatz."],
        ['type' => LayoutBlocks::SET_BUTTON, 'label' => 'Los', 'url' => 'https://example.com'],
        ['type' => LayoutBlocks::SET_DIVIDER],
        ['type' => LayoutBlocks::SET_SPACER, 'height' => 40],
        ['type' => LayoutBlocks::SET_CONTENT],
        ['type' => LayoutBlocks::SET_FOOTER],
    ]);

    expect($html)->toContain('<table role="presentation"')
        // Zwei Absaetze aus einer Leerzeile, nicht einer mit Umbruch.
        ->and(substr_count($html, '<p style="margin:0 0 16px 0;'))->toBe(2)
        // Der Knopf traegt seine Farbe als Attribut, sonst ist er in Outlook
        // ein farbloser Link.
        ->and($html)->toContain('bgcolor="'.BlockLayoutCompiler::THEME['button_background'].'"')
        // Kein Flexbox, kein Grid, kein externes Stylesheet, kein Skript.
        ->and($html)->not->toContain('display:flex')
        ->and($html)->not->toContain('display:grid')
        ->and($html)->not->toContain('<link ')
        ->and($html)->not->toContain('<script');
});

it('haengt https vor eine Adresse ohne Schema, statt den Knopf wegzuwerfen', function (): void {
    // Die haeufigste echte Eingabe. Ohne diese Zeile faellt sie unter
    // „unbekanntes Schema", der Knopf verschwindet aus der Mail, und der
    // Benutzer hat Beschriftung und Ziel eingetippt und bekommt nichts.
    $compiler = app(BlockLayoutCompiler::class);

    $html = $compiler->compile([
        ['type' => LayoutBlocks::SET_BUTTON, 'label' => 'Los', 'url' => 'www.example.com/anmeldung'],
        ['type' => LayoutBlocks::SET_CONTENT],
    ]);

    expect($html)->toContain('href="https://www.example.com/anmeldung"')
        ->and($compiler->warnings())->toBe([]);
});

it('sagt, wenn ein Knopf wegen seines Ziels nicht in die Mail kommt', function (): void {
    // Der stille Fehler, um den es geht: ein verworfener Baustein sah bis
    // hierher genau so aus wie ein nie ausgefuellter.
    $compiler = app(BlockLayoutCompiler::class);

    $html = $compiler->compile([
        ['type' => LayoutBlocks::SET_BUTTON, 'label' => 'Zur Anmeldung', 'url' => 'javascript:alert(1)'],
        ['type' => LayoutBlocks::SET_CONTENT],
    ]);

    expect($html)->not->toContain('Zur Anmeldung')
        ->and($compiler->warnings())->toHaveCount(1)
        ->and($compiler->warnings()[0])->toContain('Zur Anmeldung');

    // Ein Knopf, den noch niemand ausgefuellt hat, meldet nichts: das ist der
    // Normalzustand eines Layouts, an dem gerade gearbeitet wird.
    $compiler->compile([
        ['type' => LayoutBlocks::SET_BUTTON, 'label' => '', 'url' => ''],
        ['type' => LayoutBlocks::SET_CONTENT],
    ]);

    expect($compiler->warnings())->toBe([]);
});

it('traegt die Warnungen des Uebersetzers in die Befunde der Vorschau', function (): void {
    $data = $this->postJson(cp_route('marketing.templates.preview'), [
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [
            ['_id' => 'b1', 'type' => LayoutBlocks::SET_BUTTON, 'label' => 'Zur Anmeldung', 'url' => 'javascript:alert(1)'],
            ['_id' => 'c1', 'type' => LayoutBlocks::SET_CONTENT],
        ],
    ])->assertOk()->json('data');

    $warnungen = collect($data['findings'])->where('level', 'warning');

    expect($warnungen->pluck('message')->implode(' '))->toContain('Zur Anmeldung');
});

it('laesst die Zellen der inneren Tabellen aus den Medienabfragen heraus', function (): void {
    // `.m-shell td` ist ein Nachfahrenselektor: mit `!important` schlaegt er
    // den Inline-Style UND das `bgcolor`-Attribut, und er trifft auch die
    // Zellen der verschachtelten Tabellen. Der Knopf bekaeme im Dunkel-Modus
    // die Blattfarbe und verschwaende, der Trenner — der ist eine 1 Pixel
    // hohe farbige Zelle — ebenfalls, und auf dem Telefon bekaeme die
    // Knopffflaeche 40 Pixel Innenabstand, die niemand gebaut hat.
    $html = app(BlockLayoutCompiler::class)->compile([
        ['type' => LayoutBlocks::SET_BUTTON, 'label' => 'Los', 'url' => 'https://example.com'],
        ['type' => LayoutBlocks::SET_DIVIDER],
        ['type' => LayoutBlocks::SET_CONTENT],
        ['type' => LayoutBlocks::SET_FOOTER],
    ]);

    // Keine Regel im Kopf zeigt auf `td` ohne Klasse.
    expect($html)->not->toMatch('/\.m-shell\s+td\s*[,{]/')
        ->and($html)->toContain('.m-shell td.m-cell')
        // Und die Zellen, die etwas bedeuten, tragen ihre eigene Klasse.
        ->and($html)->toContain('class="m-btn"')
        ->and($html)->toContain('class="m-rule"')
        ->and($html)->toContain('class="m-cell m-foot"');
});

it('laesst keinen javascript-Link in eine Mail', function (): void {
    $html = app(BlockLayoutCompiler::class)->compile([
        ['type' => LayoutBlocks::SET_BUTTON, 'label' => 'Klick', 'url' => 'javascript:alert(1)'],
        ['type' => LayoutBlocks::SET_CONTENT],
    ]);

    // Kein Knopf statt eines Knopfes, der irgendwo doch einmal etwas tut.
    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('Klick');
});

it('fuehrt HTML aus einem Textbaustein nicht aus', function (): void {
    // Die ehrliche Zusage eines Baukastens: was im Textfeld steht, ist Text.
    // Wer Markup schreiben will, legt ein Layout vom Typ html an.
    $html = app(BlockLayoutCompiler::class)->compile([
        ['type' => LayoutBlocks::SET_TEXT, 'text' => '<script>alert(1)</script> und {{ first_name }}'],
        ['type' => LayoutBlocks::SET_CONTENT],
    ]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;')
        // Antlers ueberlebt: das ist die ganze Pointe eines Platzhalters.
        ->and($html)->toContain('{{ first_name }}');
});

it('zeigt die Vorschau eines Block-Layouts ohne es zu speichern', function (): void {
    $data = $this->postJson(cp_route('marketing.templates.preview'), [
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => ($this->blocks)(),
    ])->assertOk()->json('data');

    expect($data['error'])->toBeNull()
        ->and($data['html'])->toContain('Chorwerkstatt')
        // Das Loch ist gefuellt: die Vorschau laeuft durch denselben Antlers,
        // den der Versand laeuft.
        ->and($data['html'])->not->toContain('{{ content }}')
        // Und deshalb meldet die bestehende Pruefung auch fuer den Block-Weg
        // keinen fehlenden Inhaltsplatz.
        ->and(collect($data['findings'])->where('level', 'error'))->toBeEmpty();
});

it('lehnt Bausteine ab, die sich nicht verarbeiten lassen, statt mit 500 zu sterben', function (): void {
    // Der Fall ist weder Angriff noch Bedienfehler: jemand waehlt ein Bild,
    // jemand anderes loescht es aus dem Container, und `Assets::process()`
    // wirft beim naechsten Speichern `Asset [x] not found` mitten aus der
    // Kette heraus. Ungefangen waere das eine 500er-Seite — beim Speichern
    // und, weil die Kette dieselbe ist, bei jedem Tastendruck in der Vorschau.
    //
    // Simuliert wird es an der Stelle, an der es auch entsteht: die Ausnahme
    // des Feldtyps. Ein echtes geloeschtes Asset braeuchte einen Container im
    // Testbett, und was hier geprueft wird, ist nicht der Asset-Feldtyp,
    // sondern dass diese Ausnahme einen Ort bekommt.
    // Ausgeloest wird es hier durch eine Zeile ohne `type` — der Replicator
    // liest `$row['type']` ungeprueft, und das ist dieselbe Bruchstelle in
    // derselben Kette. Ein wirklich geloeschtes Asset braeuchte einen
    // Container im Testbett, und geprueft wird ohnehin nicht der Feldtyp,
    // sondern dass seine Ausnahme einen Ort bekommt.
    expect(fn () => LayoutBlocks::fromForm([['_id' => 'a1', 'enabled' => true]]))
        ->toThrow(BlockValuesRejected::class);

    ($this->store)([
        'name' => 'Mit Bild',
        'handle' => 'mit_bild',
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [['_id' => 'a1', 'enabled' => true]],
    ])->assertSessionHasErrors('blocks');

    // Abgelehnt wird der Schreibvorgang, nicht der Editor: nichts ist
    // entstanden, und nichts ist halb entstanden.
    expect(app(EmailTemplateRepository::class)->find('mit_bild'))->toBeNull();
});

it('laesst die Vorschau am Leben, wenn die Bausteine nicht verarbeitbar sind', function (): void {
    $data = $this->postJson(cp_route('marketing.templates.preview'), [
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [['_id' => 'a1', 'enabled' => true]],
    ])->assertOk()->json('data');

    // Ein Text neben der Vorschau, keine Fehlerseite. Der Editor behaelt die
    // zuletzt gelungene Fassung auf dem Schirm — das ist dieselbe Zusage, die
    // fuer halb getippte Antlers im HTML-Weg gilt.
    expect($data['error'])->not->toBeNull()
        ->and($data['html'])->toBe('');
});

it('meldet in der Vorschau einen fehlenden Inhaltsplatz auch im Baukasten', function (): void {
    $data = $this->postJson(cp_route('marketing.templates.preview'), [
        'type' => EmailTemplate::TYPE_BLOCKS,
        'blocks' => [['_id' => 'a1', 'type' => LayoutBlocks::SET_TEXT, 'text' => 'Nur Text.']],
    ])->assertOk()->json('data');

    expect(collect($data['findings'])->where('level', 'error'))->not->toBeEmpty();
});
