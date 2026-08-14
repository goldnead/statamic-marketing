<?php

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Services\TemplatePreview;
use Statamic\Facades\User;

/**
 * The layout editor's preview, and the two things it says about a layout.
 *
 * A template is the envelope, not the letter. Editing one used to be a textarea
 * of HTML whose only feedback loop was save → write a campaign → send yourself a
 * test. Two failures came out of that, and both are what is tested here: you
 * could not see the envelope, and a layout that never printed `{{ content }}`
 * looked exactly like one that did — right up to the moment every subscriber
 * received a frame around nothing.
 */
beforeEach(function (): void {
    $user = User::make()->email('templates@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    $this->preview = fn (string $html) => $this->postJson(
        cp_route('marketing.templates.preview'),
        ['html' => $html],
    );
});

it('renders the layout with stand-in content in the hole', function (): void {
    $data = ($this->preview)('<div class="wrap">{{ content }}</div>')->assertOk()->json('data');

    expect($data['html'])->toContain('<div class="wrap">')
        ->and($data['html'])->toContain('<h1>')
        ->and($data['error'])->toBeNull();
});

it('renders through the same parser the real send uses', function (): void {
    // Not a second renderer. A preview through a different engine would be a
    // second implementation to keep in step with CampaignRenderer, and the
    // first divergence between them would appear in somebody's inbox.
    $data = ($this->preview)('Du liest {{ list.name }} von {{ campaign.name }}. {{ content }}')
        ->assertOk()->json('data');

    // Compared against the preview's own values rather than a literal, so the
    // assertion does not depend on the language the test bed runs in.
    $vars = app(TemplatePreview::class)->variables();

    expect($data['html'])->toContain('Du liest '.$vars['list']['name'])
        ->and($data['html'])->toContain('von '.$vars['campaign']['name']);
});

it('uses stand-in values, never a real subscriber', function (): void {
    $data = ($this->preview)('[{{ email }}][{{ first_name }}] {{ content }}')->assertOk()->json('data');

    // The renderer's own depersonalised set: no address, a neutral salutation.
    expect($data['html'])->toContain('[]')
        ->and($data['html'])->not->toContain('@example.com');
});

// -- The variables are the renderer's, not a second list ------------------
//
// The defect this section exists for, found on Adrian's FamilyStack layout:
// the preview shipped a hand-written list of variables beside the renderer's,
// and it was wrong in both directions on the day it was written. It offered
// `list_name`, which no send has ever provided, and left out `preheader`,
// `campaign.*` and `list.*`, which every send does. A layout using `preheader`
// therefore looked broken in the preview and worked in production.

it('offers exactly the variables a real send provides', function (): void {
    $renderer = app(CampaignRenderer::class);
    $preview = app(TemplatePreview::class);

    $offered = $preview->availableVariables();

    foreach ($renderer->archiveVariables(
        new Campaign(handle: 'c', name: 'C'),
        new MailingList(handle: 'l', name: 'L'),
    ) as $name => $value) {
        // A group is offered by the names a layout actually prints
        // (`campaign.name`), never by the bare parent.
        if (is_array($value)) {
            foreach (array_keys($value) as $sub) {
                expect($offered)->toContain($name.'.'.$sub);
            }

            continue;
        }

        expect($offered)->toContain($name);
    }

    expect($offered)->toContain('content')
        // And nothing invented: `list_name` is the one that started this.
        ->and($offered)->not->toContain('list_name');
});

it('fills in the preheader, which a layout puts in its hidden preview line', function (): void {
    $data = ($this->preview)('<span>{{ preheader }}</span>{{ content }}')->assertOk()->json('data');

    expect($data['html'])->not->toContain('<span></span>');
});

it('fills in the campaign and list names a layout may print', function (): void {
    $data = ($this->preview)('[{{ campaign.name }}][{{ list.name }}]{{ content }}')->assertOk()->json('data');

    expect($data['html'])->not->toContain('[]');
});

it('warns about a placeholder that no send fills in', function (): void {
    // Antlers resolves an unknown variable to the empty string, so this fails
    // silently in the inbox: a gap where a word should be, and no error.
    $findings = ($this->preview)('{{ content }}{{ unsubscribe_url }}{{ list_name }}')
        ->assertOk()->json('data.findings');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['level'])->toBe('warning')
        ->and($findings[0]['message'])->toContain('list_name');
});

it('does not warn about Antlers\' own words', function (): void {
    // A warning that fires on correct markup is how warnings stop being read.
    $layout = '{{ if first_name }}Hallo{{ /if }}{{ noparse }}x{{ /noparse }}'
        .'{{ content }}{{ unsubscribe_url }}';

    expect(($this->preview)($layout)->assertOk()->json('data.findings'))->toBe([]);
});

it('does not warn about a parent whose children it offers', function (): void {
    $layout = '{{ campaign }}{{ content }}{{ unsubscribe_url }}';

    expect(($this->preview)($layout)->assertOk()->json('data.findings'))->toBe([]);
});

it('renders Adrian\'s FamilyStack layout without complaint', function (): void {
    // The layout that started this: a full HTML email with conditional
    // comments, a <style> block full of single braces, and four placeholders.
    // Nothing in it may be eaten, and nothing in it may be flagged.
    $layout = file_get_contents(__DIR__.'/../fixtures/familystack-layout.html');

    $data = ($this->preview)($layout)->assertOk()->json('data');

    expect($data['error'])->toBeNull()
        ->and($data['findings'])->toBe([])
        // The CSS survives verbatim — Antlers must not touch single braces.
        ->and($data['html'])->toContain('@media only screen and (max-width:600px)')
        ->and($data['html'])->toContain('.body h1{margin:0 0 16px')
        // The conditional comment for Outlook survives too.
        ->and($data['html'])->toContain('<!--[if mso]>')
        // And every placeholder resolved.
        ->and($data['html'])->not->toContain('{{');
});

it('says a layout that never prints the content will send an empty mail', function (): void {
    $findings = ($this->preview)('<div>Nur ein Rahmen</div>')->assertOk()->json('data.findings');

    expect($findings)->toHaveCount(2)
        ->and(collect($findings)->firstWhere('level', 'error'))->not->toBeNull();
});

it('is happy with a layout that prints the content', function (): void {
    $findings = ($this->preview)('{{ content }} {{ unsubscribe_url }}')->assertOk()->json('data.findings');

    expect($findings)->toBe([]);
});

it('accepts any spacing inside the braces', function (): void {
    $findings = ($this->preview)('{{content}}{{  unsubscribe_url  }}')->assertOk()->json('data.findings');

    expect($findings)->toBe([]);
});

it('does not mistake the word content in a sentence for the placeholder', function (): void {
    // The false negative that matters: telling an author their correct layout
    // is broken is how a warning stops being read.
    $findings = ($this->preview)('<p>Here is the content of the mail.</p>')->assertOk()->json('data.findings');

    expect(collect($findings)->firstWhere('level', 'error'))->not->toBeNull();
});

it('warns about a missing unsubscribe link without blocking on it', function (): void {
    // A warning and not an error: the same layout is legitimate for
    // transactional mail, where there is nothing to unsubscribe from.
    $findings = ($this->preview)('{{ content }}')->assertOk()->json('data.findings');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['level'])->toBe('warning');
});

it('accepts a one-click unsubscribe as the link', function (): void {
    $findings = ($this->preview)('{{ content }}{{ one_click_unsubscribe_url }}')->assertOk()->json('data.findings');

    expect($findings)->toBe([]);
});

it('reports a broken layout instead of failing the request', function (): void {
    // Half-typed Antlers is the normal state of a layout being edited. An error
    // page here would mean the preview breaks the moment somebody opens a brace.
    $response = ($this->preview)('{{ content }} {{ if')->assertOk();

    expect($response->json('data.html'))->toBe('');
});

it('answers with nothing at all for an empty layout', function (): void {
    $data = ($this->preview)('')->assertOk()->json('data');

    expect($data['html'])->toBe('')
        ->and($data['error'])->toBeNull();
});

it('refuses a preview to somebody who could not save the same layout', function (): void {
    // Antlers evaluates what is sent, so this must not be reachable by anyone
    // who could not already store the same string and have it rendered.
    $plain = User::make()->email('reader@example.com');
    $plain->save();
    $this->actingAs($plain);

    ($this->preview)('{{ content }}')->assertStatus(403);
});
