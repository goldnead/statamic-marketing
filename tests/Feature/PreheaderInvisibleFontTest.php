<?php

use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;

/**
 * The hidden preheader must not carry a tiny invisible font.
 *
 * `font-size:1px` together with a text colour is SpamAssassin's
 * FONT_INVIS_MSGID (2.5 points). mail-tester measured it on the welcome mails
 * on 13.10.2026. The injected preheader hides itself with display:none,
 * max-height, overflow, mso-hide and opacity alone.
 */
it('injects the preheader without font-size, line-height or colour', function (): void {
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'ohne-slot',
        name: 'Ohne Slot',
        html: '<html><head></head><body><p>Hallo.</p></body></html>',
    ));

    $campaign = new Campaign(
        handle: '',
        name: '',
        subject: 'Hallo',
        preheader: 'Der Vorschautext',
        listHandle: 'newsletter',
        templateHandle: 'ohne-slot',
        content: '',
    );

    $html = str_replace(' ', '', app(CampaignRenderer::class)->render(
        $campaign,
        new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false),
    )->html);

    expect($html)
        ->toContain('DerVorschautext')
        ->toContain('display:none')
        ->not->toContain('font-size:1px')
        ->not->toContain('line-height:1px')
        ->not->toContain('color:transparent');
});
