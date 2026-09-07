<?php

/**
 * Test-Doppel fuer die Snapshot-Schicht aus dem OPTIONALEN Addon
 * `goldnead/statamic-email-templates`.
 *
 * Marketing haengt an dieser Schicht nur ueber einen String-Klassennamen und
 * `class_exists()`, deshalb liegt das Paket nicht im vendor/ dieses Repos. Das
 * Doppel hier haelt fest, **womit** `record()` gerufen wurde, und macht damit
 * die eine Frage pruefbar, um die es bei dieser Bauart geht: steht in der Zeile
 * Empfaengertext?
 *
 * Die Deklaration ist bewacht. Ist das echte Paket doch installiert, wird sie
 * uebersprungen und die echte Klasse benutzt.
 */

namespace Goldnead\EmailTemplates\Snapshots;

if (! class_exists(Snapshots::class)) {
    class Snapshots
    {
        /**
         * Jeder Aufruf, in der Reihenfolge des Eingangs.
         *
         * @var array<int, array{ownerType: ?string, ownerId: int|string|null, template: array<string, mixed>, meta: array<string, mixed>}>
         */
        public static array $recorded = [];

        /**
         * Aufrufe, die die Wache abgewiesen hat — damit ein Test den
         * Unterschied zwischen "nichts festgehalten" und "abgewiesen" sieht.
         *
         * @var array<int, array{ownerType: ?string, ownerId: int|string|null, template: array<string, mixed>, meta: array<string, mixed>}>
         */
        public static array $refused = [];

        public static function reset(): void
        {
            self::$recorded = [];
            self::$refused = [];
        }

        /**
         * @param  array<string, mixed>  $template
         * @param  array<string, mixed>  $meta
         */
        public static function record(
            ?string $ownerType,
            int|string|null $ownerId,
            array $template,
            array $meta = [],
        ): ?object {
            // So streng wie das Original: gerenderte Mail eines Empfaengers
            // wird abgewiesen, es gibt `null` zurueck und der Versand laeuft
            // weiter. Ein Doppel, das alles annimmt, wuerde genau den Fehler
            // durchlassen, gegen den diese Tests geschrieben sind.
            foreach (['subject', 'body', 'plain_text'] as $feld) {
                if (is_string($template[$feld] ?? null) && self::looksRendered($template[$feld])) {
                    self::$refused[] = compact('ownerType', 'ownerId', 'template', 'meta');

                    return null;
                }
            }

            self::$recorded[] = compact('ownerType', 'ownerId', 'template', 'meta');

            return (object) ['id' => count(self::$recorded)];
        }

        /** Woertlich aus dem Original. */
        public static function looksRendered(string $html): bool
        {
            if ($html === '') {
                return false;
            }

            if (preg_match('/[?&]signature=[a-f0-9]{16,}/i', $html) === 1) {
                return true;
            }

            return preg_match('#/o/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.gif#i', $html) === 1;
        }

        /*
         * Die Lesefläche. Sie steht hier nicht der Vollstaendigkeit halber.
         *
         * Pest laeuft die ganze Suite in einem Prozess, und diese Klasse
         * existiert ab der Datei, die sie einbindet, fuer jeden weiteren Test.
         * Ein Doppel, das nur `record()` kann, laesst `class_exists()` ueberall
         * wahr werden und die Kampagnen-Detailseite dann mit "undefined method
         * previewUrl()" abstuerzen — vierzig fremde Tests rot, ohne dass an
         * ihnen etwas falsch war. Ein Doppel muss koennen, was das Original
         * kann.
         */

        public static function available(): bool
        {
            return true;
        }

        public static function find(int|string $id): ?object
        {
            return isset(self::$recorded[$id - 1]) ? (object) ['id' => $id] : null;
        }

        public static function latestForOwner(string $ownerType, int|string $ownerId): ?object
        {
            foreach (array_reverse(self::$recorded, true) as $index => $aufruf) {
                if ($aufruf['ownerType'] === $ownerType && (string) $aufruf['ownerId'] === (string) $ownerId) {
                    return (object) ['id' => $index + 1];
                }
            }

            return null;
        }

        /** @return array<int, object> */
        public static function forOwner(string $ownerType, int|string $ownerId): array
        {
            $treffer = [];

            foreach (array_reverse(self::$recorded, true) as $index => $aufruf) {
                if ($aufruf['ownerType'] === $ownerType && (string) $aufruf['ownerId'] === (string) $ownerId) {
                    $treffer[] = (object) ['id' => $index + 1];
                }
            }

            return $treffer;
        }

        public static function previewUrl(object|int|string|null $snapshot): ?string
        {
            if ($snapshot === null) {
                return null;
            }

            $id = is_object($snapshot) ? $snapshot->id : $snapshot;

            return 'http://localhost/cp/email-templates/snapshots/'.$id.'/preview';
        }
    }
}
