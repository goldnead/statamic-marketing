<?php

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\EmailTemplateRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\EmailTemplate;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;

// Stand-in for the OPTIONAL email-templates addon (not vendored in this repo).
require_once __DIR__.'/../Fixtures/EmailTemplatesStub.php';

beforeEach(function () {
    EmailTemplates::reset();
    $this->list = new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false);
});

function renderCampaign(MailingList $list, ?string $templateHandle): string
{
    $campaign = new Campaign(
        handle: 'welcome',
        name: 'Welcome',
        subject: 'Hi',
        listHandle: 'newsletter',
        templateHandle: $templateHandle,
        content: '<p>CONTENT</p>',
    );

    return app(CampaignRenderer::class)->render($campaign, $list)->html;
}

it('renders the managed email_templates entry when its slug is referenced', function () {
    EmailTemplates::$entries = ['news_layout' => ['body' => '<div>ENTRY {{ content }}</div>']];

    $html = renderCampaign($this->list, 'news_layout');

    expect($html)->toContain('ENTRY');
    expect($html)->toContain('CONTENT'); // campaign content injected at {{ content }}
});

it('renders the managed entry wrapped in its LAYOUT and resolves marketing merge tokens', function () {
    // The email-templates resolver returns `->body` already wrapped in the
    // entry's chosen LAYOUT (config('email-templates.layouts') + default_layout);
    // marketing receives that layout-wrapped HTML verbatim and only applies its
    // own recipient/merge substitution on top. This proves campaigns pick up the
    // branded layout AND that marketing's existing token handling is not regressed.
    EmailTemplates::$entries = ['news_layout' => [
        'body' => '<html><body class="et-layout-newsletter">'
            .'<main>{{ content }}</main>'
            .'<a href="{{ unsubscribe_url }}">off</a>'
            .'</body></html>',
    ]];

    $html = renderCampaign($this->list, 'news_layout');

    expect($html)->toContain('et-layout-newsletter');       // layout marker survives the render
    expect($html)->toContain('CONTENT');                    // campaign content injected at {{ content }}
    expect($html)->not->toContain('{{ content }}');         // content token consumed
    expect($html)->not->toContain('{{ unsubscribe_url }}'); // marketing merge token resolved
});

it('gracefully falls back to marketing rendering when the email-templates resolver yields nothing', function () {
    // Mirrors the addon-absent path: the slug is neither a managed entry nor a
    // marketing repository template, so resolve() returns null and marketing
    // renders through its own built-in fallback layout — no layout marker, but
    // content and unsubscribe handling stay intact.
    $html = renderCampaign($this->list, 'does_not_exist_anywhere');

    expect($html)->toContain('CONTENT');
    expect($html)->toContain('Unsubscribe'); // built-in EmailTemplate::fallback()
    expect($html)->not->toContain('et-layout-newsletter');
});

it('falls back to the marketing template repository for a raw legacy handle', function () {
    // No managed entry for this slug → resolver invokes the marketing repo
    // fallback. This is also the exact branch taken when the addon is absent.
    app(EmailTemplateRepository::class)->save(new EmailTemplate(
        handle: 'brand',
        name: 'Brand',
        html: '<main>BRAND {{ content }}</main>',
    ));

    $html = renderCampaign($this->list, 'brand');

    expect($html)->toContain('BRAND');
    expect($html)->toContain('CONTENT');
});

it('uses the built-in fallback layout when no template is referenced', function () {
    $html = renderCampaign($this->list, null);

    expect($html)->toContain('CONTENT');
    expect($html)->toContain('Unsubscribe'); // from EmailTemplate::fallback()
});

it('persists and reloads the template reference on a campaign', function () {
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'ref',
        name: 'Ref',
        listHandle: 'newsletter',
        templateHandle: 'news_layout',
        content: '<p>x</p>',
    ));

    $reloaded = app(CampaignRepository::class)->find('ref');

    expect($reloaded)->not->toBeNull();
    expect($reloaded->templateHandle)->toBe('news_layout');
});
