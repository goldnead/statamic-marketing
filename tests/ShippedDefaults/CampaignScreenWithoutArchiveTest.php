<?php

/**
 * The campaign screen on an install that never enabled the archive.
 *
 * Which is every install by default: `MARKETING_ARCHIVE` is `false`, and
 * `routes/web.php` registers the archive routes only when it is true. The
 * screen asked for `marketing.archive.show` regardless, so opening a campaign
 * threw RouteNotFoundException — a 500, not a missing link.
 *
 * It stayed invisible for two reasons at once: the suite turns the archive on
 * for every other test, and the one production install had no campaign to open
 * yet. Found by rendering the page rather than by running the tests.
 */

use Goldnead\Marketing\Contracts\Repositories\CampaignRepository;
use Goldnead\Marketing\Contracts\Repositories\MailingListRepository;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;

beforeEach(function (): void {
    $user = User::make()->email('archive-off@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    app(MailingListRepository::class)->save(new MailingList(handle: 'newsletter', name: 'Newsletter'));
    app(CampaignRepository::class)->save(new Campaign(
        handle: 'brief',
        name: 'Der Brief',
        subject: 'Hallo',
        listHandle: 'newsletter',
    ));
});

it('has no archive route at all, which is the situation being tested', function (): void {
    // Guards the guard. If a later change registered these routes
    // unconditionally, every assertion below would keep passing while testing
    // nothing.
    expect(Route::has('marketing.archive.show'))->toBeFalse();
});

it('opens the campaign screen instead of answering 500', function (): void {
    $props = $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.campaigns.show', ['brief']))
        ->assertOk()
        ->json('props');

    expect($props['archive']['enabled'])->toBeFalse()
        ->and($props['archive']['url'])->toBeNull();
});

it('opens every tab of the report', function (string $tab): void {
    // The tabs are separate server round trips, and each one rebuilds the
    // archive block. One guarded call site is not the same as a guarded page.
    $this->withHeaders(['X-Inertia' => 'true'])
        ->get(cp_route('marketing.campaigns.show', ['brief', 'tab' => $tab]))
        ->assertOk();
})->with(['overview', 'delivery', 'opens', 'clicks', 'unsubscribes']);
