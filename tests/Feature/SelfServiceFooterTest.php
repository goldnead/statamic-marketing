<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;

/**
 * Keine Werbemail ohne Ausweg.
 *
 * `{{ unsubscribe_url }}` steht jeder Vorlage zur Verfuegung — aber eine
 * Vorlage kann es vergessen, und genau das ist passiert: die fuenfteilige
 * Willkommensstrecke von adriangoldner.com ging monatelang ohne sichtbaren
 * Abmelde-Link raus. Der `List-Unsubscribe`-Kopfeintrag war da, aber den zeigt
 * nicht jedes Mailprogramm.
 *
 * Deshalb liegt die Zusicherung im Renderer und nicht in den Vorlagen. Diese
 * Tests halten fest, was das heisst: angehaengt wenn noetig, in Ruhe gelassen
 * wenn die Vorlage ihre Arbeit macht.
 */
beforeEach(function (): void {
    config()->set('mail.default', 'array');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subscription = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');

    /*
     * Ein eigener Rahmen OHNE Abmelde-Link — der Fall, um den es geht.
     *
     * Die mitgelieferte Ersatzvorlage traegt selbst einen; bei ihr greift das
     * Netz zu Recht nie. Die Luecke entsteht dort, wo ein Host seinen eigenen
     * Rahmen mitbringt, so wie adriangoldner.com es mit seinem gebrandeten
     * Mail-Layout tut. Genau der wird hier nachgestellt.
     */
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'nackt',
        name: 'Ohne Fuss',
        html: '<html><body><main>{{ content }}</main></body></html>',
    ));
});

function renderMail(string $content, ?string $sequenceUuid = null)
{
    return app(CampaignRenderer::class)->render(
        new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hallo', content: $content, templateHandle: 'nackt'),
        test()->list,
        test()->subscription,
        null,
        $sequenceUuid,
    );
}

it('haengt einen Abmelde-Link an, wenn die Vorlage keinen hat', function (): void {
    $rendered = renderMail('<p>Hallo!</p>');

    expect($rendered->html)->toContain($rendered->unsubscribeUrl);
});

it('laesst einen Rahmen in Ruhe, der den Link selbst setzt', function (): void {
    // Erkannt daran, dass die gerenderte Mail schon auf einen
    // Selbstbedienungs-Weg zeigt. Zweimal derselbe Link waere unsauber und
    // wuerde eine bewusst gestaltete Fusszeile verdoppeln.
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'mit-fuss',
        name: 'Mit Fuss',
        html: '<html><body>{{ content }}<a href="{{ unsubscribe_url }}">Abmelden</a></body></html>',
    ));

    $rendered = app(CampaignRenderer::class)->render(
        new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hallo', content: '<p>Hallo!</p>', templateHandle: 'mit-fuss'),
        $this->list,
        $this->subscription,
    );

    expect(substr_count($rendered->html, $rendered->unsubscribeUrl))->toBe(1);
});

it('setzt keinen Serien-Link in eine Mail, die aus keiner Serie kommt', function (): void {
    // Eine Kampagne ist keine Serie. Ein Link, der "diese Serie abbestellen"
    // verspricht und den Newsletter beendet, waere eine Falle.
    $rendered = renderMail('<p>Hallo!</p>');

    expect($rendered->html)->not->toContain('/serie/');
});

it('haengt nichts an eine Vorschau ohne Anmeldung', function (): void {
    // Ohne Token gaebe es nur eine Attrappe, und eine Attrappe in einer
    // Vorschau sieht aus wie ein funktionierender Link.
    $rendered = app(CampaignRenderer::class)->render(
        new Campaign(handle: 'welcome', name: 'Welcome', subject: 'Hallo', content: '<p>Hallo!</p>', templateHandle: 'nackt'),
        $this->list,
        null,
    );

    expect($rendered->html)->not->toContain('/serie/');
});

it('setzt den Serien-Ausstieg vor die vollstaendige Abmeldung', function (): void {
    /*
     * Die Reihenfolge ist die Aussage, nicht Kosmetik.
     *
     * Wer eine Willkommensstrecke loswerden will, will selten den Newsletter
     * los. Stuende die vollstaendige Abmeldung vorn, klickt sie jemand, weil
     * sie die erste ist — und ist dann ganz weg.
     *
     * Geprueft am Fuss selbst und nicht am ganzen Rendering: der Serien-Link
     * entsteht nur, wenn das Automations-Addon danebensteht, und das ist in
     * dieser Suite bewusst nicht der Fall. Der Zusammenbau ueber beide Addons
     * hinweg liegt in tests/Integration/SequenceFooterIntegrationTest.php.
     */
    $renderer = app(CampaignRenderer::class);

    $methode = new ReflectionMethod($renderer, 'selfServiceFooterHtml');
    $methode->setAccessible(true);

    $html = $methode->invoke($renderer, 'https://example.test/abmelden', 'https://example.test/serie/abc/def');

    expect(strpos($html, '/serie/'))->toBeLessThan(strpos($html, '/abmelden'));
});

