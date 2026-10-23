<?php

use Goldnead\Marketing\Support\ClickTarget;

/**
 * What of a clicked URL may be written down: a login link is cut down to the
 * page it leads to, everything else stays as it was.
 */
it('cuts a login link down to its target, whatever the host and slashes', function (string $url): void {
    expect(ClickTarget::forStorage($url))->toBe('/account/kurs?x=1');
})->with([
    'https://example.test/!/autologin?to=%2Faccount%2Fkurs%3Fx%3D1&signature=SECRET',
    'http://other.test/!/autologin/?to=%2Faccount%2Fkurs%3Fx%3D1&signature=SECRET',
    'https://example.test//!/autologin?to=%2Faccount%2Fkurs%3Fx%3D1&signature=SECRET',
    // Laravel decodes the path before routing: these reach the same route.
    'https://example.test/%21/autologin?to=%2Faccount%2Fkurs%3Fx%3D1&signature=SECRET',
    'https://example.test/!/auto%6Cogin?to=%2Faccount%2Fkurs%3Fx%3D1&signature=SECRET',
    'https://example.test/!%2Fautologin?to=%2Faccount%2Fkurs%3Fx%3D1&signature=SECRET',
]);

it('keeps only the path of a login link without a usable target', function (): void {
    expect(ClickTarget::forStorage('https://example.test/!/autologin?signature=SECRET'))->toBe('/!/autologin')
        ->and(ClickTarget::forStorage('https://example.test/!/autologin?to=%2F%2Fevil.test&signature=SECRET'))->toBe('/!/autologin');
});

it('leaves other urls alone', function (): void {
    expect(ClickTarget::forStorage('https://example.com/news?a=1'))->toBe('https://example.com/news?a=1')
        ->and(ClickTarget::forStorage(null))->toBeNull()
        ->and(ClickTarget::forStorage(''))->toBe('');
});

it('treats nothing as a login link when no route is configured', function (): void {
    config(['identity-contracts.autologin.route' => '']);

    expect(ClickTarget::forStorage('https://example.com/?utm=1'))->toBe('https://example.com/?utm=1');
});

it('follows a configured route', function (): void {
    config(['identity-contracts.autologin.route' => 'login-link']);

    expect(ClickTarget::forStorage('https://example.test/login-link?to=%2Faccount%2Fa&signature=S'))->toBe('/account/a');
});
