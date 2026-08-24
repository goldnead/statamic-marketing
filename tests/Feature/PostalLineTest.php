<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\SubscriptionService;

/**
 * Werbepost ohne Anbieterkennzeichnung, lautlos.
 *
 * `ensureSelfServiceFooter()` haengt nur an, wenn die Vorlage gar keinen
 * Selbstbedienungs-Weg enthaelt. Das mitgelieferte Ersatzlayout hat einen
 * Abmeldelink — und keine Anschrift. Damit rutschte genau die Kombination
 * durch, die nicht durchrutschen darf: Ausweg ja, Pflichtangabe nein.
 *
 * Es braucht dafuer nicht einmal eine Loeschung: ein `et_templates`-Eintrag mit
 * demselben Slug gewinnt gegen die Marketing-Vorlage und tauscht das Layout
 * lautlos aus.
 */
beforeEach(function (): void {
    config()->set('mail.default', 'array');
    config()->set('marketing.footer.postal_line', 'Adrian Goldner · Musterweg 1 · 60311 Frankfurt');

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));

    $this->list = app(MailingListRepository::class)->find('newsletter');
    $this->subscription = app(SubscriptionService::class)->subscribe($this->list, 'jane@example.com');
});

function renderMitAnschrift(?string $templateHandle = null, string $content = '<p>Hallo</p>'): string
{
    return app(CampaignRenderer::class)->render(
        new Campaign(
            handle: 'welcome',
            name: 'Welcome',
            subject: 'Hallo',
            content: $content,
            templateHandle: $templateHandle,
        ),
        test()->list,
        test()->subscription,
    )->html;
}

it('adds the postal line when the fallback layout is used', function (): void {
    // Kein Vorlagen-Handle → Ersatzlayout. Genau der Fall aus dem Ticket.
    expect(renderMitAnschrift())->toContain('Musterweg 1');
});

it('adds the postal line when a template handle does not resolve', function (): void {
    // Umbenannt oder geloescht — am Ergebnis nicht zu unterscheiden.
    expect(renderMitAnschrift('gibt-es-nicht'))->toContain('Musterweg 1');
});

it('leaves a template that already carries the address alone', function (): void {
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'mit-anschrift',
        name: 'Mit Anschrift',
        html: '<html><body>{{ content }}<footer>Adrian Goldner · Musterweg 1 · 60311 Frankfurt</footer></body></html>',
    ));

    $html = renderMitAnschrift('mit-anschrift');

    // Genau einmal. Zweimal waere kein Fehler im Rechtssinn, sieht aber nach
    // einem aus — und ein Netz, das sichtbar wird, wenn es nicht gebraucht
    // wird, wird abgeschaltet.
    expect(substr_count($html, 'Musterweg 1'))->toBe(1);
});

it('does nothing when no address is configured', function (): void {
    config()->set('marketing.footer.postal_line', null);

    $html = renderMitAnschrift();

    // Ein Addon kann die Anschrift seines Betreibers nicht erfinden, und eine
    // erfundene waere schlimmer als keine.
    expect($html)->not->toContain('Musterweg');
});

it('puts the line inside the document, not after it', function (): void {
    $html = renderMitAnschrift();

    $body = strripos($html, '</body>');
    $line = strpos($html, 'Musterweg 1');

    expect($body)->not->toBeFalse();
    expect($line)->toBeLessThan($body);
});

it('escapes the configured line', function (): void {
    config()->set('marketing.footer.postal_line', 'Muster & Co <script>alert(1)</script>');

    $html = renderMitAnschrift();

    expect($html)->not->toContain('<script>alert(1)</script>');
    expect($html)->toContain('Muster &amp; Co');
});
