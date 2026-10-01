<?php

use Illuminate\Http\Request;
use Statamic\CP\Breadcrumbs\Breadcrumbs;
use Statamic\Facades\User;

/**
 * The breadcrumb of a marketing screen names the screen's own section.
 *
 * The dashboard item's URL is the parent of every other marketing URL, so
 * the active pattern Statamic derives from it claimed them all and a
 * campaign read "Marketing / Übersicht". Also the guard for the nav closure
 * itself: it only runs when a CP page builds the nav, so a call to a method
 * NavItem does not have took the whole Control Panel down with a 500 that no
 * other test in this suite could see.
 */
beforeEach(function (): void {
    $user = User::make()->email('nav@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);
});

function marketingBreadcrumb(string $url): array
{
    app()->instance('request', Request::create($url));

    return array_map(fn ($crumb) => $crumb->text(), Breadcrumbs::build());
}

it('names the section a screen belongs to', function (string $route, array $params, string $expected): void {
    $crumbs = marketingBreadcrumb(cp_route($route, $params));

    expect($crumbs[0] ?? null)->toBe('Marketing')
        ->and($crumbs[1] ?? null)->toBe(__($expected));
})->with([
    'campaign show' => ['marketing.campaigns.show', ['x'], 'marketing::nav.campaigns'],
    'campaign edit' => ['marketing.campaigns.edit', ['x'], 'marketing::nav.campaigns'],
    'campaign list' => ['marketing.campaigns.index', [], 'marketing::nav.campaigns'],
    'dashboard' => ['marketing.dashboard', [], 'marketing::nav.dashboard'],
]);
