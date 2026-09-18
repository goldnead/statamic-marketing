<?php

/**
 * The image button in the campaign editor is a promise the field has to keep.
 *
 * Found on staging, 18.09.2026: a campaign whose text carried an `<img>` would
 * not open in the Control Panel any more. Bard showed "Invalid content, image
 * button/extension is not enabled" instead of the text. The blueprint listed
 * `image` under `buttons` but gave the field no `container`, and without an
 * asset container Bard does not load its image extension at all — so the
 * button sat in the toolbar and the editor refused any document that had used
 * it. Sending and preview took the stored HTML regardless; only the editor was
 * locked.
 *
 * The container is not hard-wired. It comes from `marketing.editor.asset_container`,
 * which the settings screen offers per brand, and where nobody set one the
 * first container of the site is taken — the same default core's own Bard
 * config screen proposes. A site with no container at all gets no image
 * button, exactly as core drops it from its own defaults there.
 */

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Marketing\Support\CampaignContentField;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

beforeEach(function (): void {
    // The asset meta Bard preloads asks who may see the container.
    $user = User::make()->email('editor@example.com')->makeSuper();
    $user->save();
    $this->actingAs($user);

    // Containers are stache items and would be written to the testbench
    // skeleton's `content/assets`. Point the store at the scratch root the bed
    // wipes after every test instead.
    $tmpRoot = sys_get_temp_dir().'/marketing-test-'.getmypid();
    Stache::store('asset-containers')->directory($tmpRoot.'/assets');

    $this->container = function (string $handle): void {
        AssetContainer::make($handle)->disk('local')->title(ucfirst($handle))->save();
    };

    /** The Bard field's config as the publish form receives it. */
    $this->fieldConfig = fn (): array => CampaignContentField::blueprint()
        ->toPublishArray()['tabs'][0]['sections'][0]['fields'][0];
});

afterEach(function (): void {
    Stache::store('asset-containers')->clear();
});

it('hands Bard the configured asset container', function (): void {
    ($this->container)('bilder');
    ($this->container)('downloads');
    config()->set('marketing.editor.asset_container', 'downloads');

    $field = ($this->fieldConfig)();

    expect($field['container'])->toBe('downloads')
        ->and($field['buttons'])->toContain('image');
});

it('takes the first container of the site when none is configured', function (): void {
    // Created out of order on purpose: "first" means first by handle, not
    // whichever the Stache happened to index first.
    ($this->container)('downloads');
    ($this->container)('bilder');
    config()->set('marketing.editor.asset_container', null);

    expect(($this->fieldConfig)()['container'])->toBe('bilder');
});

it('falls back to the first container when the configured handle names none', function (): void {
    // A container renamed or deleted after the setting was made. Offering an
    // image button bound to nothing would put the editor right back where the
    // bug was. Two containers on purpose: with exactly one, core's Bard
    // defaults to it by itself and this test would pass without the fallback.
    ($this->container)('bilder');
    ($this->container)('downloads');
    config()->set('marketing.editor.asset_container', 'gibt-es-nicht');

    expect(($this->fieldConfig)()['container'])->toBe('bilder');
});

it('offers no image button on a site without any asset container', function (): void {
    config()->set('marketing.editor.asset_container', null);

    $field = ($this->fieldConfig)();

    expect($field['buttons'])->not->toContain('image')
        ->and($field['container'] ?? null)->toBeNull();
});

it('opens a campaign that holds an image, with the image extension armed', function (): void {
    // The proof at the level PHP can give it: Bard's `preload()` puts the
    // `assets` block into the meta only when the field resolves a container,
    // and that block is what the browser reads to enable the image extension.
    ($this->container)('bilder');

    $field = app(CampaignContentField::class)->forEditing(
        '<p>Hallo</p><img src="https://example.com/portrait.png" alt="Adrian">'
    );

    expect(json_encode($field['values']['content']))->toContain('"type":"image"')
        ->and($field['meta']['content']['assets']['container']['id'] ?? null)->toBe('bilder');
});

it('names the container in the settings screen, per brand', function (): void {
    $fields = app(SettingsRegistry::class)->fields('marketing');

    expect($fields)->toHaveKey('editor.asset_container')
        ->and($fields['editor.asset_container']['type'])->toBe('string')
        ->and($fields['editor.asset_container']['nullable'])->toBeTrue();
});
