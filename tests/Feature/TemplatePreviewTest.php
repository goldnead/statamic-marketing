<?php

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
    $data = ($this->preview)('Hallo {{ first_name }}, du liest {{ list_name }}. {{ content }}')
        ->assertOk()->json('data');

    expect($data['html'])->toContain('Hallo Maria')
        ->and($data['html'])->toContain('Beispiel-Verteiler');
});

it('uses stand-in values, never a real subscriber', function (): void {
    $data = ($this->preview)('{{ email }} {{ content }}')->assertOk()->json('data');

    expect($data['html'])->toContain(TemplatePreview::SAMPLE['email']);
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
