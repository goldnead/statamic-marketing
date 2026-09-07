<?php

/**
 * Die Einstellungsseite des Marketings, auf dem gemeinsamen Bildschirm der
 * Suite.
 *
 * Bewusst ueber HTTP und nicht am Manager vorbei: was von dieser Seite aus
 * wirklich zu pruefen ist, ist dass marketing **angemeldet** ist. Ein
 * implementierter Vertrag, den niemand anmeldet, ist eine Einstellungsseite
 * ohne Marketing-Abschnitt darauf, und jede Behauptung unten waere gegen den
 * Manager trotzdem wahr.
 *
 * Der wichtigste Test ist der letzte: ein geaenderter Wert muss beim Leser
 * ankommen. HTTP 200 und eine Zeile in `brand_settings` belegen das nicht.
 */

use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\BrandContext\Models\Brand;
use Goldnead\BrandContext\Models\BrandSetting;
use Goldnead\BrandContext\Settings\SettingsManager;
use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Data\MailingList;
use Goldnead\Marketing\Services\CampaignRenderer;
use Goldnead\Marketing\Support\Settings;

beforeEach(function (): void {
    // Eine Ueberschreibung gehoert einer Marke: ohne aufgeloeste Marke gaebe es
    // keine Zeile, die den Wert besitzt, und `save()` verweigert die Arbeit.
    $this->brand = Brand::create(['handle' => 'haus', 'name' => 'Haus']);
    BrandContext::setCurrent($this->brand);
});

/** @return array<string, array<string, mixed>> */
function marketingSettingFields(): array
{
    return app(SettingsRegistry::class)->fields('marketing');
}

/**
 * Speichern, wie die Seite es tut: der ganze Abschnitt auf einmal, ein Test
 * nennt nur den Schluessel, den er meint, der Rest kommt aus der Config.
 *
 * Ueber den Manager und nicht ueber HTTP. Der Endpunkt gehoert brand-context
 * und wird dort geprueft; hier ginge er ohnehin nicht, weil das Testgeruest
 * dieses Repos Inertias ServiceProvider nicht laedt und der Controller
 * `$request->inertia()` ruft. Was von hier aus zu belegen ist, liegt vor und
 * hinter dem Endpunkt: dass marketing angemeldet ist, und dass ein
 * gespeicherter Wert beim Leser ankommt.
 */
function saveMarketingSettings(array $overrides): void
{
    $settings = [];

    foreach (array_keys(marketingSettingFields()) as $key) {
        $settings[$key] = config('marketing.'.$key);
    }

    app(SettingsManager::class)->for('marketing')->save(array_replace($settings, $overrides));
}

it('meldet marketing bei der Einstellungs-Registry an', function (): void {
    // Das eine, was sonst keine Behauptung hier faende: ohne die Anmeldung im
    // ServiceProvider gaebe es den Abschnitt nicht, und alles Uebrige liefe
    // trotzdem durch.
    expect(app(SettingsRegistry::class)->has('marketing'))->toBeTrue();
    expect(app(SettingsRegistry::class)->provider('marketing'))->toBe(Settings::class);
    expect(app(SettingsRegistry::class)->permission('marketing'))->toBe('manage marketing settings');
});

it('bietet keinen Schluessel an, der beim Booten gelesen wird', function (): void {
    $angeboten = array_keys(marketingSettingFields());

    // Routing: steht in routes/web.php, also fest, bevor eine Ueberschreibung
    // existieren kann.
    expect($angeboten)->not->toContain('routes.prefix');
    expect($angeboten)->not->toContain('archive.enabled');
    expect($angeboten)->not->toContain('archive.prefix');

    // Einmalige Registrierungen aus `app->booted()`: das Ausschalten wuerde
    // wirken, das Einschalten je nach Paket-Ladereihenfolge nicht.
    expect($angeboten)->not->toContain('integrations.automations');
    expect($angeboten)->not->toContain('integrations.webhook_manager');
    expect($angeboten)->not->toContain('timeline.enabled');

    // Verschachtelte Abbildung: kein Typ dafuer, bleibt in der Config.
    expect($angeboten)->not->toContain('delivery.mail_headers');

    // Deployment und Datenumzug.
    expect($angeboten)->not->toContain('storage.driver');
    expect($angeboten)->not->toContain('storage.flat.path');
    expect($angeboten)->not->toContain('sending.mailer');
    expect($angeboten)->not->toContain('sending.queue');
});

it('benennt die verschachtelte Abbildung, statt sie zu verschweigen', function (): void {
    $gruppen = Settings::settingsGroups();

    $versand = collect($gruppen)->firstWhere('title', __('marketing::settings.groups.sending.title'));

    expect($versand['description'])->toContain('mail_headers');
    expect($versand['description'])->toContain('config/marketing.php');
});

it('speichert eine Aenderung und laesst unveraenderte Werte ungespeichert', function (): void {
    saveMarketingSettings(['sending.messages_per_minute' => 120]);

    $gespeichert = BrandSetting::query()->where('namespace', 'marketing')->pluck('key')->all();

    expect($gespeichert)->toContain('sending.messages_per_minute');

    // Was unveraendert mitgeschickt wurde, bekommt keine Zeile. Sonst haette
    // ein einziges Speichern jeden Wert dieser Installation an seinen heutigen
    // Stand genagelt, und ein spaeteres Paket-Update koennte keinen davon mehr
    // bewegen.
    expect($gespeichert)->not->toContain('sending.chunk');
    expect($gespeichert)->not->toContain('subscriptions.double_opt_in');
    expect($gespeichert)->not->toContain('leadhub.tag_prefix');
});

it('laesst einen geaenderten Wert bis in die versendete Mail durchschlagen', function (): void {
    // Der Beleg, um den es geht. Nicht: die Zeile steht in der Datenbank.
    // Sondern: der Leser, der die Mail baut, sieht sie.
    $zeile = "Adrian Goldner\nBeispielweg 1, 60311 Frankfurt";

    // Vorher steht sie nirgends.
    expect(config('marketing.footer.postal_line'))->toBeNull();

    saveMarketingSettings(['footer.postal_line' => $zeile]);

    expect(config('marketing.footer.postal_line'))->toBe($zeile);

    // Und jetzt die Grenze, die zaehlt: der Renderer.
    $html = app(CampaignRenderer::class)->templateAtSendTime(new Campaign(
        handle: 'september',
        name: 'September',
        subject: 'Hallo',
        listHandle: 'newsletter',
        content: '<p>Text</p>',
    ));

    expect($html)->toContain('Beispielweg 1, 60311 Frankfurt');

    // Und in der wirklich versendeten Fassung ebenso, nicht nur im
    // Schnappschuss.
    $mail = app(CampaignRenderer::class)->render(
        new Campaign(
            handle: 'september',
            name: 'September',
            subject: 'Hallo',
            listHandle: 'newsletter',
            content: '<p>Text</p>',
        ),
        new MailingList(handle: 'newsletter', name: 'Newsletter', doubleOptIn: false),
    );

    expect($mail->html)->toContain('Beispielweg 1, 60311 Frankfurt');
});
