import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const hier = dirname(fileURLToPath(import.meta.url));
const show = readFileSync(resolve(hier, '../../resources/js/pages/Campaigns/Show.vue'), 'utf8');

/**
 * Der Hinweis auf den Bildcache des Versenders — und vor allem, wo er NICHT
 * stehen darf.
 *
 * Gemessen am 18.09.2026: Brevo schreibt jedes Bild der Mail auf seinen eigenen
 * Proxy um, auch den Oeffnungspixel. Der zweite Abruf kam aus dem Cache
 * (`age: 700`, `max-age=172800`), obwohl das Addon den Pixel mit `no-store`
 * ausliefert. Zwei Tage lang erreicht so je Empfaenger nur die erste Oeffnung
 * den Zaehler.
 *
 * Betroffen ist davon GENAU EINE Zahl: die Spalte `opens` in der
 * Empfaengertabelle, ein roher Zaehler je Nachricht. Die Kennzahl „Geoeffnet"
 * im Kopf ist `where('opens', '>', 0)->count()` — Nachrichten mit mindestens
 * einer Oeffnung — und von einem Cache unberuehrt. Ein Hinweis dort waere
 * falsch und wuerde eine belastbare Zahl in Zweifel ziehen.
 */
describe('Hinweis auf den Bildcache des Versenders', () => {
    it('steht unter der Spalte mit dem rohen Zaehler', () => {
        expect(show).toContain('data-marketing-proxied-opens-note');
        expect(show).toContain('marketing::timeline.proxied_opens_note');
    });

    it('erscheint nur auf den Reitern, die diese Spalte zeigen', () => {
        const block = show.slice(show.indexOf('data-marketing-proxied-opens-note') - 600);
        const bedingung = block.slice(0, block.indexOf('data-marketing-proxied-opens-note'));

        expect(bedingung).toContain("tab === 'opens'");
        expect(bedingung).toContain("tab === 'delivery'");
        // Nicht auf einem leeren Reiter, wo er Zahlen erklaert, die es nicht gibt.
        expect(bedingung).toContain('rows.length');
    });

    /**
     * Der Waechter gegen die naheliegende Verschlimmbesserung: den Hinweis
     * zusaetzlich neben die Kennzahl im Kopf zu haengen.
     */
    it('haengt nicht an der Kennzahl im Kopf', () => {
        const kopf = show.slice(0, show.indexOf("tab === 'opens'"));

        expect(kopf).not.toContain('proxied_opens_note');
    });
});
