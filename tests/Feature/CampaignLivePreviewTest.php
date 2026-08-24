<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\MailingList;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\User;

/**
 * Die Vorschau zeigt, was getippt wird — nicht, was gespeichert ist.
 *
 * Vorher zeigte sie die gespeicherte Fassung und sagte es auch dazu („Save your
 * changes first"). Damit war sie drei Schritte vom Bearbeiteten entfernt.
 */
beforeEach(function (): void {
    $user = User::make()->email('vorschau@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(
        handle: 'newsletter',
        name: 'Newsletter',
        doubleOptIn: false,
    ));
});

function livePreview(array $payload = []): TestResponse
{
    return test()->postJson(cp_route('marketing.campaigns.live-preview'), array_merge([
        'handle' => 'ungespeichert',
        'name' => 'Ungespeichert',
        'subject' => 'Betreff',
        'content' => '<p>Frisch getippt</p>',
        'list_handle' => 'newsletter',
    ], $payload));
}

it('renders content that was never saved', function (): void {
    // Der ganze Punkt: die Kampagne gibt es nicht in der Datenbank.
    livePreview()
        ->assertOk()
        ->assertJsonPath('data.error', null)
        ->assertSee('Frisch getippt', false);
});

it('answers with an error instead of a blank page when the list is missing', function (): void {
    livePreview(['list_handle' => 'gibt-es-nicht'])
        ->assertOk()
        ->assertJsonPath('data.html', '')
        ->assertJsonPath('data.error', __('marketing::campaigns.errors.no_list'));
});

it('saves nothing', function (): void {
    livePreview(['handle' => 'darf-nicht-entstehen']);

    // Ueber das Repository, nicht ueber ein Model: welcher Treiber dahinter
    // liegt (flat oder eloquent) entscheidet die Installation.
    expect(app(CampaignRepository::class)->find('darf-nicht-entstehen'))
        ->toBeNull();
});

it('needs permission', function (): void {
    $user = User::make()->email('niemand@example.com');
    $user->save();

    $this->actingAs($user);

    livePreview()->assertForbidden();
});
