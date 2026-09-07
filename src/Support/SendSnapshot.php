<?php

namespace Goldnead\Marketing\Support;

use Goldnead\Marketing\Data\Campaign;
use Goldnead\Marketing\Mail\CampaignMail;
use Goldnead\Marketing\Services\CampaignRenderer;

/**
 * Marketings Seite der Snapshot-Schicht aus `goldnead/statamic-email-templates`.
 *
 * Die Schicht selbst wird dort gebaut und hier nur benutzt. Was diese Klasse
 * beisteuert, ist das, was in **jedem** Aufruf von marketing aus gleich sein
 * muss: der Eigentuemer-Typ, die Identitaet einer Kampagne, und die Vorlage
 * statt der gerenderten Mail.
 *
 * **Warum ueberhaupt eine eigene Klasse.** Drei Stellen sprechen mit der
 * Schicht: der Start eines Broadcasts, der Einzelversand aus einer Automation,
 * und die Detailseite. Der Klassenname darf nicht importiert werden — das
 * Addon ist optional, ein `use` wuerde jeden Versand ohne es zerreissen —, also
 * steht er als Zeichenkette da. Dreimal dieselbe Zeichenkette ist dreimal die
 * Gelegenheit, dass eine davon bei einer Umbenennung stehen bleibt, und der
 * Ausfall waere lautlos: `class_exists()` sagt falsch, es wird nichts
 * festgehalten, und es faellt erst auf der Detailseite auf.
 */
class SendSnapshot
{
    /**
     * Bindend aus dem Vertrag vom 07.09.2026: marketing besitzt genau diesen
     * Eigentuemer-Typ, notifications und automations haben eigene.
     */
    public const OWNER_CAMPAIGN = 'marketing:campaign';

    /**
     * Eingefroren. Wird in email-templates als `Snapshots::CLASS_NAME` gefuehrt
     * und ist woertlich in drei Verbraucher-Repos kopiert.
     */
    private const SNAPSHOTS = 'Goldnead\\EmailTemplates\\Snapshots\\Snapshots';

    /**
     * Festhalten, was von dieser Kampagne rausgeht.
     *
     * Zu rufen **einmal je Versand**, und erst wenn die Mail wirklich draussen
     * ist. Zwei Versaende derselben unveraenderten Vorlage sind dank des
     * Inhalts-Hashes eine wiederverwendete Zeile: ein Broadcast an 800 Leute
     * ebenso wie ein Automations-Knoten, der zehntausendmal feuert.
     *
     * Uebergeben wird {@see CampaignRenderer::templateAtSendTime()} und nie
     * `RenderedMail->html` — das Zweite gehoert einem Empfaenger und traegt
     * signierte Links und Zaehlpixel. Die Schicht weist so etwas zwar ab, aber
     * eine Abweisung heisst: kein Schnappschuss.
     *
     * Der Absender wird mit derselben Kette aufgeloest, die
     * {@see CampaignMail::decideSender()} beim Versand
     * benutzt. Ohne sie traegt die Schicht die Vorschau-Identitaet der Marke
     * ein, und die Kopfleiste der Vorschau behauptete dann einen Absender, der
     * nie gesendet hat.
     */
    public static function recordCampaign(Campaign $campaign, ?CampaignRenderer $renderer = null): void
    {
        if (! class_exists(self::SNAPSHOTS)) {
            return;
        }

        $class = self::SNAPSHOTS;
        $renderer ??= app(CampaignRenderer::class);

        $class::record(self::OWNER_CAMPAIGN, self::ownerId($campaign), [
            'subject' => $campaign->subject,
            'body' => $renderer->templateAtSendTime($campaign),
            'slug' => $campaign->templateHandle,
        ], [
            'sender_name' => $campaign->fromName
                ?: config('marketing.from.name')
                ?: config('mail.from.name'),
            'sender_email' => $campaign->fromEmail
                ?: config('marketing.from.email')
                ?: config('mail.from.address'),
        ]);
    }

    /**
     * Die CP-Adresse der Vorschau fuer diese Kampagne, oder `null`.
     *
     * `null` heisst dreierlei, und alle drei enden gleich: Addon nicht
     * installiert, Schnappschuesse abgeschaltet oder Migration nicht gelaufen,
     * oder diese Kampagne ist noch nicht raus.
     */
    public static function previewUrlFor(Campaign $campaign): ?string
    {
        if (! class_exists(self::SNAPSHOTS)) {
            return null;
        }

        $class = self::SNAPSHOTS;

        return $class::previewUrl($class::latestForOwner(self::OWNER_CAMPAIGN, self::ownerId($campaign)));
    }

    /**
     * Die Identitaet einer Kampagne ist ihr Handle.
     *
     * Sie hat keine andere: `marketing_messages.campaign_handle`, jede CP-Route
     * und beide Ablagen (Datenbank wie Flatfile) benennen sie so.
     */
    protected static function ownerId(Campaign $campaign): string
    {
        return $campaign->handle;
    }
}
