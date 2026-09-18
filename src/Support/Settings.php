<?php

namespace Goldnead\Marketing\Support;

use Goldnead\BrandContext\Contracts\ProvidesSettings;

/**
 * Was ein Betreiber am Marketing aus dem Control Panel heraus aendern darf.
 *
 * Diese Klasse ist **nur** die Feldliste. Seite, Formular, Validierung,
 * Speicher, Rechtepruefung und die Marken-Dimension kommen aus
 * `goldnead/statamic-brand-context` — siehe {@see ProvidesSettings}. Hier steht
 * kein Controller, keine Vue-Seite, keine Route und keine eigene Tabelle.
 *
 * **Ueberschreibungen, keine Kopie.** Gespeichert wird nur, was jemand
 * tatsaechlich geaendert hat. Alles andere folgt weiter `config/marketing.php`,
 * ein Paket-Update bewegt also die Vorgaben, und eine Installation, die diese
 * Seite nie oeffnet, verhaelt sich wie vor ihrer Existenz.
 *
 * **Je Marke, nicht je Installation.** Das ist fuer dieses Addon der eigentliche
 * Gewinn: `footer.postal_line` ist eine Pflichtangabe nach § 5 DDG, und die
 * eigene Config sagt seit jeher, sie gehoere je Marke gesetzt. Bis hierher ging
 * das nur ueber eine einzige `.env`-Variable, die fuer alle Marken dieselbe war.
 *
 * ## Was nicht auf der Seite steht, und warum
 *
 * `SettingsManager::apply()` laeuft aus `app->booted()`. Alles, was beim Booten
 * gelesen wird, sieht dort noch den Paketwert. Ein Schalter, der erst beim
 * naechsten Deploy wirkt, ist eine Luege in der Oberflaeche — deshalb bleiben
 * draussen:
 *
 * - `routes.prefix`, `archive.enabled`, `archive.prefix`. Gelesen in
 *   `routes/web.php` (`:29`, `:145`, `:146`), also bevor irgendeine
 *   Ueberschreibung existiert.
 * - `integrations.automations`, `integrations.webhook_manager`,
 *   `timeline.enabled`. Sehen zur Laufzeit gelesen aus, entscheiden aber in
 *   Wahrheit ueber eine **einmalige Registrierung**:
 *   `ServiceProvider::registerSiblingBridges()` haengt aus einem
 *   `app->booted()`-Rueckruf die Bruecken und die sechs Zeitachsen-Listener an,
 *   und welcher der beiden `booted()`-Rueckrufe zuerst feuert — der von
 *   brand-context oder der hier — haengt an der Paket-Ladereihenfolge. Das
 *   Ausschalten wuerde also wirken, das Einschalten je nach Installation nicht.
 *   Die Abdeckungsliste vom 06.09.2026 fuehrt diese drei auf; sie liegt damit
 *   falsch.
 * - `storage.driver`, `storage.flat.path`. Ein Wechsel muss erst die Daten
 *   bewegen, und `storage.flat.path` steckt zusaetzlich in einer
 *   Singleton-Closure, ist nach der ersten Aufloesung im Prozess also
 *   eingefroren.
 * - `sending.mailer`, `sending.queue`,
 *   `subscriptions.confirmation_throttle.store`. Deployment-Werte. Eine falsche
 *   Queue bedient kein Worker, ein falscher Cache-Store legt den Versand der
 *   Bestaetigungsmails still.
 * - `delivery.mail_headers`. Eine verschachtelte Abbildung (Header-Name auf
 *   Wert, beliebige Schluessel); die Schicht kennt dafuer keinen Typ, und ein
 *   erfundener sechster waere ein schlechterer Editor als keiner. Der Wert
 *   bleibt in `config/marketing.php` und wird auf der Seite benannt statt
 *   verschwiegen — siehe die Beschreibung der Gruppe „Versand".
 * - `delivery.ignored_query_parameters` und `timeline.types`. Flache Listen von
 *   Protokoll- beziehungsweise Klassenkonstanten; ein Tippfehler wirkt dort
 *   still.
 *
 * ## Und was hier wirkt, schlaegt die Umgebung
 *
 * Mehrere dieser Schluessel haben in `config/marketing.php` ein `env()` hinter
 * sich. Eine gespeicherte Ueberschreibung gewinnt gegen die `.env`. Wer danach
 * weiter an der `.env` schraubt, wundert sich, warum nichts passiert; die
 * Gruppenbeschreibungen sagen es.
 */
class Settings implements ProvidesSettings
{
    /**
     * Fest fuer immer: steht in `brand_settings.namespace` auf jeder Zeile, ein
     * neuer Name verwaist jede Ueberschreibung, die eine Installation gemacht
     * hat.
     */
    public static function settingsNamespace(): string
    {
        return 'marketing';
    }

    /** Die Config-Wurzel, der nicht gesetzte Werte weiter folgen. */
    public static function settingsConfigPath(): string
    {
        return 'marketing';
    }

    /**
     * Das Recht, das diesen Abschnitt bewacht.
     *
     * Neu angelegt, nicht umbenannt: `statamic-marketing` hatte bis hierher
     * keine Einstellungsseite und damit auch kein Recht dafuer. Die fuenf
     * bestehenden Rechte bleiben Wort fuer Wort, wie sie sind — ein
     * umbenanntes Recht ist ein stiller Rechteentzug fuer jede Nutzergruppe,
     * der es zugewiesen war.
     */
    public static function settingsPermission(): string
    {
        return 'manage marketing settings';
    }

    /**
     * @return array<int, array{title: string, description?: string, fields: array<int, array<string, mixed>>}>
     */
    public static function settingsGroups(): array
    {
        return [
            [
                'title' => __('marketing::settings.groups.sender.title'),
                'description' => __('marketing::settings.groups.sender.description'),
                'fields' => [
                    static::field('from.name', 'string', ['nullable' => true]),
                    static::field('from.email', 'string', ['nullable' => true]),
                    // Mehrzeilig: eine Anschrift hat mehr als eine Zeile, und
                    // der Renderer setzt sie mit `nl2br()`.
                    static::field('footer.postal_line', 'text', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('marketing::settings.groups.editor.title'),
                'description' => __('marketing::settings.groups.editor.description'),
                'fields' => [
                    // Ein Handle, kein Select: die Liste der Container liegt im
                    // Stache, und ein Feld, dessen Optionen beim Booten
                    // eingesammelt wuerden, saehe eine Site ohne Stache leer.
                    // Ein Handle, das keinen Container mehr nennt, faellt beim
                    // Lesen auf den ersten Container zurueck.
                    static::field('editor.asset_container', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('marketing::settings.groups.sending.title'),
                'description' => __('marketing::settings.groups.sending.description'),
                'fields' => [
                    static::field('sending.chunk', 'integer', ['min' => 1]),
                    // 0 heisst „ungedrosselt" und muss erreichbar bleiben.
                    static::field('sending.messages_per_minute', 'integer', ['min' => 0]),
                    static::field('sending.claim_lease_minutes', 'integer', ['min' => 1]),
                    static::field('sending.window.from', 'integer', ['nullable' => true, 'min' => 0, 'max' => 23]),
                    static::field('sending.window.to', 'integer', ['nullable' => true, 'min' => 0, 'max' => 23]),
                    static::field('sending.window.timezone', 'string', ['nullable' => true]),
                ],
            ],
            [
                'title' => __('marketing::settings.groups.subscriptions.title'),
                'description' => __('marketing::settings.groups.subscriptions.description'),
                'fields' => [
                    static::field('subscriptions.double_opt_in', 'boolean'),
                    static::field('subscriptions.honeypot', 'string'),
                    // 0 heisst „laeuft nie ab".
                    static::field('subscriptions.confirmation_ttl_hours', 'integer', ['min' => 0]),
                    static::field('subscriptions.confirm_requires_post', 'boolean'),
                    static::field('subscriptions.confirmation_throttle.enabled', 'boolean'),
                    static::field('subscriptions.confirmation_throttle.per_list', 'integer', ['min' => 1]),
                    static::field('subscriptions.confirmation_throttle.per_list_window_minutes', 'integer', ['min' => 1]),
                    static::field('subscriptions.confirmation_throttle.per_mailbox', 'integer', ['min' => 1]),
                    static::field('subscriptions.confirmation_throttle.per_mailbox_window_minutes', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('marketing::settings.groups.after_sending.title'),
                'description' => __('marketing::settings.groups.after_sending.description'),
                'fields' => [
                    static::field('unsubscribe.global_opt_out', 'boolean'),
                    static::field('tracking.opens', 'boolean'),
                    static::field('tracking.clicks', 'boolean'),
                    static::field('frequency_cap.enabled', 'boolean'),
                    static::field('frequency_cap.max', 'integer', ['min' => 1]),
                    static::field('frequency_cap.window_hours', 'integer', ['min' => 1]),
                    static::field('frequency_cap.defer.retry_after_minutes', 'integer', ['min' => 1]),
                    // 0 heisst „nie erneut versuchen": die erste Deckelung
                    // verwirft die Nachricht.
                    static::field('frequency_cap.defer.max_deferrals', 'integer', ['min' => 0]),
                ],
            ],
            [
                'title' => __('marketing::settings.groups.archive.title'),
                'description' => __('marketing::settings.groups.archive.description'),
                'fields' => [
                    static::field('archive.title', 'string', ['nullable' => true]),
                    static::field('archive.neutral_name', 'string', ['nullable' => true]),
                    static::field('archive.feed_limit', 'integer', ['min' => 1]),
                ],
            ],
            [
                'title' => __('marketing::settings.groups.leadhub.title'),
                'description' => __('marketing::settings.groups.leadhub.description'),
                'fields' => [
                    static::field('leadhub.tag_subscribers', 'boolean'),
                    static::field('leadhub.tag_prefix', 'string'),
                    static::field('leadhub.hard_bounce_opt_out', 'boolean'),
                    static::field('leadhub.complaint_opt_out', 'boolean'),
                ],
            ],
        ];
    }

    /**
     * Ein Feld, mit Beschriftung und Beschreibung aus den Sprachdateien.
     *
     * Der Sprachschluessel ist der Config-Pfad mit ersetzten Punkten: ein Punkt
     * im Schluessel ist fuer den Uebersetzer ein Pfadtrenner, und
     * `settings.fields.from.email.label` wuerde als vier verschachtelte Arrays
     * gesucht, die es nicht gibt.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function field(string $key, string $type, array $extra = []): array
    {
        $handle = str_replace('.', '_', $key);

        return array_merge([
            'key' => $key,
            'type' => $type,
            'label' => __("marketing::settings.fields.{$handle}.label"),
            'description' => __("marketing::settings.fields.{$handle}.description"),
            'nullable' => false,
        ], $extra);
    }
}
