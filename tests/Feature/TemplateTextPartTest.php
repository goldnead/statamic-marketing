<?php

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;

// Stand-in for the OPTIONAL email-templates addon (not vendored in this repo).
require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';

/**
 * The `text/plain` part of a template send.
 *
 * A template send has no campaign content: the mail IS the template. The text
 * part used to be derived from the (empty) campaign content, so it carried the
 * unsubscribe line and the postal address and nothing else. SpamAssassin's
 * MPART_ALT_DIFF (0.7) fires on exactly that: the two alternatives say
 * different things. mail-tester measured it on the welcome mails on 13.10.2026.
 */
beforeEach(function (): void {
    EmailTemplates::reset();
    $this->list = new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false);

    $this->renderTemplate = function (string $handle): string {
        $campaign = new Campaign(
            handle: '',
            name: '',
            subject: 'Hallo',
            listHandle: 'newsletter',
            templateHandle: $handle,
            content: '',
        );

        return app(CampaignRenderer::class)->render($campaign, $this->list)->text;
    };
});

it('derives the text part from the template html when the template has no plain text', function (): void {
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'willkommen',
        name: 'Willkommen',
        html: '<html><head><title>Titel</title><style>p{color:red}</style></head><body>'
            .'<div style="display:none;max-height:0;overflow:hidden;" aria-hidden="true">Vorschautext</div>'
            .'<p>Schön, dass du dabei bist.</p>'
            .'<p>Hier geht es <a href="https://example.com/start">zum Start</a>.</p>'
            .'</body></html>',
    ));

    $text = ($this->renderTemplate)('willkommen');

    expect($text)
        ->toContain('Schön, dass du dabei bist.')
        ->toContain('zum Start (https://example.com/start)')
        ->not->toContain('<')
        ->not->toContain('color:red')
        ->not->toContain('Vorschautext')
        ->not->toContain('Titel');
});

it('prefers the plain text written on the template', function (): void {
    EmailTemplates::$entries = ['willkommen' => [
        'body' => '<html><body><p>HTML-Fassung.</p></body></html>',
        'plain_text' => 'Handgeschriebene Textfassung.',
    ]];

    $text = ($this->renderTemplate)('willkommen');

    expect($text)->toBe('Handgeschriebene Textfassung.');
});
