<?php

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Models\Subscription;
use Goldnead\Marketing\Services\CampaignRenderer;
use Statamic\Facades\Antlers;

/**
 * `subscriber.*` alongside the flat person variables.
 *
 * Which variables a template may use used to depend on which node sent it: an
 * automation resolves its own node config against the run, where the person
 * lives under `subscriber.*`, while a `marketing.send_email` body is parsed
 * against the flat array from CampaignRenderer::variables(). Antlers resolves
 * an unknown variable to the empty string, so a template written for one
 * context rendered its greeting empty in the other — with no error, and the
 * mail still went out.
 *
 * The alias is derived from the same values rather than rebuilt beside them,
 * which is what the drift test below pins down.
 */
beforeEach(function () {
    $this->list = new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false);

    $this->campaign = new Campaign(
        handle: 'welcome',
        name: 'Welcome',
        subject: 'Hi',
        listHandle: 'newsletter',
        content: '<p>CONTENT</p>',
    );
});

it('offers the person under subscriber.* as well as flat', function () {
    $subscription = Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'mara@example.com',
        'first_name' => 'Mara',
        'last_name' => 'Mustermann',
    ]);

    $variables = app(CampaignRenderer::class)->variables($this->campaign, $this->list, $subscription);

    expect($variables['subscriber']['first_name'])->toBe('Mara');
    expect($variables['subscriber']['last_name'])->toBe('Mustermann');
    expect($variables['subscriber']['email'])->toBe('mara@example.com');
    expect($variables['subscriber']['name'])->toBe($variables['name']);
    expect($variables['subscriber']['unsubscribe_url'])->toBe($variables['unsubscribe_url']);
});

it('resolves a subscriber.* greeting in a template body instead of rendering it empty', function () {
    $subscription = Subscription::create([
        'list_handle' => 'newsletter',
        'email' => 'mara@example.com',
        'first_name' => 'Mara',
    ]);

    $renderer = app(CampaignRenderer::class);
    $variables = $renderer->variables($this->campaign, $this->list, $subscription);

    $rendered = (string) Antlers::parse('Hallo {{ subscriber.first_name }},', $variables);

    expect($rendered)->toBe('Hallo Mara,');
    expect($rendered)->not->toContain('Hallo ,');
});

it('does not leak the recipient into subscriber.* on the public archive', function () {
    $renderer = app(CampaignRenderer::class);

    $archive = $renderer->archiveVariables($this->campaign, $this->list);

    // archiveVariables() overrides the flat person keys after building on top
    // of variables(). If the alias were not re-derived afterwards it would
    // still carry the values from before the override — the exact drift the
    // second spelling risks.
    expect($archive['subscriber']['first_name'])->toBe($archive['first_name']);
    expect($archive['subscriber']['name'])->toBe($archive['name']);
    expect($archive['subscriber']['email'])->toBe('');
    expect($archive['subscriber']['first_name'])->not->toBe('');
});

it('keeps the alias in step with the flat variables for an anonymous preview', function () {
    $variables = app(CampaignRenderer::class)->variables($this->campaign, $this->list, null);

    foreach (['email', 'first_name', 'last_name', 'name', 'unsubscribe_url'] as $key) {
        expect($variables['subscriber'][$key])->toBe($variables[$key]);
    }
});
