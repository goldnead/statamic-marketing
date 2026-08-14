<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Support\CampaignContentField;
use Statamic\Facades\User;

/**
 * The campaign text, written in Bard and stored as the HTML it always was.
 *
 * The whole point of doing it this way is what does *not* change: `content` is
 * still a string of HTML, {@see CampaignRenderer} still parses it as Antlers,
 * and a campaign written through the API or imported before this release opens
 * in the editor and saves back out. If any of that stopped holding, the editor
 * would have quietly become a migration.
 */
beforeEach(function (): void {
    $user = User::make()->email('campaigns@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    $this->campaigns = app(CampaignRepository::class);

    $this->existing = function (string $content): Campaign {
        $campaign = new Campaign(
            handle: 'brief',
            name: 'Brief',
            subject: 'Hallo',
            content: $content,
        );

        $this->campaigns->save($campaign);

        return $campaign;
    };

    /** A ProseMirror document, the shape Bard submits. */
    $this->document = fn (string $text) => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
    ];

    $this->save = fn (mixed $content) => $this->patch(
        cp_route('marketing.campaigns.update', 'brief'),
        ['name' => 'Brief', 'subject' => 'Hallo', 'content' => $content],
    );
});

it('stores what Bard submits as HTML', function (): void {
    ($this->existing)('<p>Alt</p>');

    ($this->save)(($this->document)('Neu geschrieben'))->assertSessionHasNoErrors();

    expect($this->campaigns->find('brief')->content)
        ->toBeString()
        ->toContain('Neu geschrieben')
        ->toContain('<p>');
});

it('still accepts a plain HTML string, so the API is unchanged', function (): void {
    // Everything that posts to this endpoint without a publish form — an
    // import, a script, the API — keeps working untouched.
    ($this->existing)('<p>Alt</p>');

    ($this->save)('<p>Von aussen</p>')->assertSessionHasNoErrors();

    expect($this->campaigns->find('brief')->content)->toBe('<p>Von aussen</p>');
});

it('opens HTML written before this release in the editor', function (): void {
    // The conversion is Statamic's own `preProcess`, so a campaign written in a
    // textarea years ago opens without a converter of ours in between.
    $field = app(CampaignContentField::class)->forEditing('<p>Ein alter Text</p>');

    expect($field['values']['content'])->toBeArray()
        ->and(json_encode($field['values']['content']))->toContain('Ein alter Text');
});

it('hands the editor a blueprint and the fieldtype metadata', function (): void {
    $field = app(CampaignContentField::class)->forEditing('');

    expect($field['blueprint']['tabs'][0]['sections'][0]['fields'][0]['handle'])->toBe('content')
        ->and($field['blueprint']['tabs'][0]['sections'][0]['fields'][0]['type'])->toBe('bard')
        ->and($field['meta'])->toHaveKey('content');
});

it('saves HTML and not a ProseMirror document', function (): void {
    // `save_html` is what keeps the column a string. Bard turns it off the
    // moment a field has sets, and the renderer would then be handed an array
    // to parse as Antlers — so the absence of sets is part of the contract.
    $stored = app(CampaignContentField::class)->fromForm(($this->document)('Text'));

    expect($stored)->toBeString()->toContain('<p>Text</p>');
});

it('survives a round trip without changing the text', function (): void {
    $field = app(CampaignContentField::class);

    $once = $field->fromForm($field->forEditing('<p>Hallo Welt</p>')['values']['content']);
    $twice = $field->fromForm($field->forEditing($once)['values']['content']);

    expect($twice)->toBe($once)
        ->and($twice)->toContain('Hallo Welt');
});

it('keeps Antlers placeholders through the round trip', function (): void {
    // A campaign greets people by name. Bard must not escape or swallow the
    // braces on the way in or out — the greeting is the most-used feature there
    // is, and a broken one goes out to the whole list.
    $field = app(CampaignContentField::class);

    $stored = $field->fromForm($field->forEditing('<p>Hallo {{ first_name }}!</p>')['values']['content']);

    expect($stored)->toContain('{{ first_name }}');
});

it('stores nothing for an empty editor rather than an empty paragraph', function (): void {
    expect(app(CampaignContentField::class)->fromForm([]))->toBe('');
});
