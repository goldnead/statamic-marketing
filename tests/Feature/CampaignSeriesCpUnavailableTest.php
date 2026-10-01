<?php

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Series\SeriesSync;
use Statamic\Facades\User;

/**
 * Without statamic-events (here: SeriesSync::available() pinned to false, the
 * answer an installed-but-unmigrated sibling gets as well — the suite migrates
 * the events tables for every test, and which test runs first in a process
 * must not decide whether they exist) the editor keeps its series section as
 * a one-line hint, and the switch cannot be thrown behind the screen's back
 * either.
 */
beforeEach(function (): void {
    (new ReflectionProperty(SeriesSync::class, 'available'))->setValue(null, false);

    $user = User::make()->email('ohne-termine@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(CampaignRepository::class)->save(new Campaign(
        handle: 'entwurf',
        name: 'Entwurf',
        subject: 'Hallo',
        content: '<p>Text</p>',
    ));
});

it('meldet im Editor, dass die Termine fehlen', function (): void {
    $series = json_decode(
        $this->withHeaders(['X-Inertia' => 'true'])
            ->get(cp_route('marketing.campaigns.edit', 'entwurf'))
            ->assertOk()
            ->getContent(),
        true,
    )['props']['series'];

    expect($series['available'])->toBeFalse()
        ->and($series['can_toggle'])->toBeFalse()
        ->and($series['events'])->toBe([])
        ->and($series['children'])->toBe([]);
});

it('macht ohne Termine-Addon keine Vorlage', function (): void {
    $this->patch(cp_route('marketing.campaigns.update', 'entwurf'), [
        'name' => 'Entwurf',
        'series_enabled' => true,
    ])->assertSessionHasErrors('series_enabled');

    expect(app(CampaignRepository::class)->find('entwurf')->status)->toBe(Campaign::STATUS_DRAFT);
});
