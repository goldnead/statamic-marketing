<?php

namespace Goldnead\Marketing\Sending;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Ob der Versandweg einer Marke gerade eben versagt hat.
 *
 * Das hier existiert für genau einen Zweck, und ohne den wäre es Ballast: die
 * Antwort auf eine Anmeldung darf nicht davon abhängen, ob die Adresse schon im
 * Verteiler steht — auch dann nicht, wenn die Installation gerade nichts
 * verschicken kann.
 *
 * Der Weg „Adresse ist schon angemeldet" verschickt nichts und kann deshalb
 * auch nicht merken, dass das Relay tot ist. Er antwortet `sent`. Eine frische
 * Adresse dagegen läuft in den echten Versand, der wirft, und bekommt
 * `unavailable`. Damit steht die Mitgliedschaft nach EINER Anfrage fest, und
 * zwar genau in dem Moment, für den `unavailable` erfunden wurde. Gefunden von
 * einem Kritiker-Durchlauf am 14.08.2026, gemessen und nicht vermutet.
 *
 * Die drei anderen Gründe für `unavailable` — kein brauchbarer Zähler, ein
 * unerreichbares Suppression-Gate, eine halbe Absenderidentität — lassen sich
 * auf dem stillen Weg direkt prüfen, ohne etwas zu verschicken.
 * `SubscriptionService` tut das. Nur der Transport lässt sich nicht befragen,
 * ohne ihm eine Nachricht zu geben, und die gibt es auf diesem Weg nicht.
 * Deshalb merkt sich der echte Versand seinen letzten Fehlschlag kurz, und der
 * stille Weg liest ihn.
 *
 * **Der Preis ist bewusst klein gehalten.** Die Notiz ändert ausschließlich die
 * Antwort des stillen Weges, nie die einer echten Anmeldung: wer sich neu
 * einträgt, bekommt weiterhin das Ergebnis seines eigenen Versands. Betroffen
 * ist nur, wer schon im Verteiler steht und sich erneut einträgt — der bekommt
 * für eine Minute „gerade nicht möglich" statt „schau in dein Postfach", und
 * beides ist für diese Person ohnehin eine Auskunft über nichts.
 *
 * Eine Minute, weil ein Relay, das einen einzelnen Empfänger ablehnt, die
 * Installation nicht länger als nötig für gestört erklären soll.
 */
class TransportHealth
{
    protected const FENSTER_SEKUNDEN = 60;

    public function melden(?int $brandId): void
    {
        $this->speicher()?->put($this->schluessel($brandId), true, static::FENSTER_SEKUNDEN);
    }

    public function gestoert(?int $brandId): bool
    {
        return (bool) $this->speicher()?->get($this->schluessel($brandId), false);
    }

    /**
     * Derselbe Speicher, den die Empfänger-Drossel zählt.
     *
     * Nicht aus demselben Grund: hier wird nichts hochgezählt, ein einfaches
     * put/get genügt und braucht keine atomare Operation. Aber ein zweiter,
     * anders konfigurierter Speicher wäre eine zweite Sache, die auseinander
     * laufen kann, und die Frage „welcher Cache gilt für dieses Addon" ist
     * bereits an einer Stelle beantwortet.
     *
     * Ein unerreichbarer Cache ist hier kein Grund, irgendetwas zu verweigern:
     * antwortet er nicht, verweigert die Drossel bereits jede Bestätigung, und
     * dann antworten beide Wege ohnehin `unavailable`.
     */
    protected function speicher(): ?Repository
    {
        try {
            return Cache::store(config('marketing.subscriptions.confirmation_throttle.store'));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function schluessel(?int $brandId): string
    {
        return 'marketing:doi:transport-gestoert:'.($brandId ?? 'global');
    }
}
