import { describe, it, expect, vi, beforeEach } from 'vitest';
import { withoutLeaveGuard, CONTENT_DIRTY_KEY } from '../../resources/js/support/leaveGuard.js';

/**
 * Der Defekt, den dieser Test festhaelt.
 *
 * Ein Bard-Feld mit einem Bild im Inhalt meldet sich beim Laden als schmutzig,
 * ohne dass jemand etwas angefasst hat. Statamics Wache haengt an Inertias
 * `before` und fragt per NATIVEM `confirm()`, ob man die Seite wirklich
 * verlassen will. „Nein" bricht den Besuch ab — ohne `start`, ohne Fehler, ohne
 * abgelehnte Promise. Sichtbar war: in einer Kampagne mit Bild tat KEIN Knopf
 * mehr etwas, und die Seite sah aus, als haette sie gespeichert.
 *
 * Am 19.09.2026 auf staging gemessen:
 *
 *   ohne Bild  $dirty.names() -> []                     Save -> PATCH 303
 *   mit Bild   $dirty.names() -> ['campaign-content']    Save -> keine Anfrage
 *   mit Bild, nach $dirty.disableWarning()               Save -> PATCH 303
 *
 * Was dieser Test NICHT beweist: dass die Wache im echten CP nicht mehr
 * dazwischenfunkt. Das ist Verhalten eines fremden Skripts in einem echten
 * Browser, und dafuer gibt es hier kein jsdom, das es nachstellt — der Beleg
 * dafuer ist der Lauf gegen staging, der im Ticket steht. Hier steht die Regel,
 * an der sich der Aufrufer halten muss: vor dem Besuch lueften, bei Misserfolg
 * zurueckgeben.
 */

function dirtyDouble(initial = []) {
    const namen = new Set(initial);
    return {
        has: (k) => namen.has(k),
        add: (k) => namen.add(k),
        remove: (k) => namen.delete(k),
        names: () => [...namen],
        disableWarning: vi.fn(),
    };
}

beforeEach(() => { window.onbeforeunload = null; });

describe('withoutLeaveGuard', () => {
    it('lueftet die Markierung, damit der Besuch nicht abgebrochen wird', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);

        withoutLeaveGuard({}, dirty);

        expect(dirty.names()).toEqual([]);
    });

    /**
     * Der entscheidende Teil. `remove()` allein genuegt NICHT: gemessen am
     * 19.09.2026 meldete der Speicher beim `inertia:before` schon `[]` und der
     * native Dialog kam trotzdem, weil Statamic seinen Zuhoerer nie abnimmt.
     * Nur `disableWarning()` haengt ihn ab.
     */
    it('haengt Statamics Zuhoerer ab, nicht nur die Markierung', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);

        withoutLeaveGuard({}, dirty);

        expect(dirty.disableWarning).toHaveBeenCalled();
    });

    it('haengt nichts ab, wenn gar nichts schmutzig war', () => {
        const dirty = dirtyDouble([]);

        withoutLeaveGuard({}, dirty);

        expect(dirty.disableWarning).not.toHaveBeenCalled();
    });

    /**
     * Die abgehaengte Wache kommt nicht von selbst zurueck (gemessen: ein
     * spaeteres `add()` setzt `onbeforeunload` nicht). Nach einem
     * fehlgeschlagenen Speichern steht die Seite also ohne Schutz da — dafuer
     * der Ersatz.
     */
    it('setzt nach einem Fehlschlag einen Ersatz fuer das Verlassen der Seite', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);

        const options = withoutLeaveGuard({}, dirty);
        expect(window.onbeforeunload).toBeNull();

        options.onError({ content: 'zu lang' });

        expect(typeof window.onbeforeunload).toBe('function');
    });

    it('setzt keinen Ersatz, wenn das Speichern durchgeht', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);

        const options = withoutLeaveGuard({}, dirty);
        options.onSuccess({});
        options.onFinish({});

        expect(window.onbeforeunload).toBeNull();
    });

    it('gibt sie zurueck, wenn der Server den Inhalt ablehnt', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);
        const onError = vi.fn();

        const options = withoutLeaveGuard({ onError }, dirty);
        expect(dirty.names()).toEqual([]);

        options.onError({ content: 'zu lang' });

        // Die Aenderung ist wirklich noch ungespeichert — beim naechsten
        // Verlassen der Seite muss wieder gewarnt werden.
        expect(dirty.names()).toEqual([CONTENT_DIRTY_KEY]);
        expect(onError).toHaveBeenCalledWith({ content: 'zu lang' });
    });

    it('laesst sie nach einem erfolgreichen Speichern geluftet', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);
        const onSuccess = vi.fn();

        const options = withoutLeaveGuard({ onSuccess }, dirty);
        options.onSuccess({ props: {} });
        options.onFinish({});

        expect(dirty.names()).toEqual([]);
        expect(onSuccess).toHaveBeenCalled();
    });

    /**
     * Ein Besuch kann auch ohne Erfolg und ohne `onError` enden: abgebrochen,
     * offline, ein 500er, den Inertia in ein eigenes Fenster haengt. Alles
     * davon laesst den Inhalt ungespeichert.
     */
    it('gibt sie auch zurueck, wenn der Besuch ohne Erfolg endet', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);

        const options = withoutLeaveGuard({}, dirty);
        options.onFinish({ completed: false });

        expect(dirty.names()).toEqual([CONTENT_DIRTY_KEY]);
    });

    it('gibt sie nicht doppelt zurueck', () => {
        const dirty = dirtyDouble([CONTENT_DIRTY_KEY]);
        const options = withoutLeaveGuard({}, dirty);

        options.onError({});
        options.onFinish({});

        expect(dirty.names()).toEqual([CONTENT_DIRTY_KEY]);
    });

    /**
     * War nichts schmutzig, darf der Wrapper auch nichts erfinden — sonst
     * warnt die Seite nach einem Speichern ohne Aenderung.
     */
    it('markiert nichts, was vorher nicht schmutzig war', () => {
        const dirty = dirtyDouble([]);

        const options = withoutLeaveGuard({}, dirty);
        options.onError({});
        options.onFinish({});

        expect(dirty.names()).toEqual([]);
    });

    it('kommt ohne Statamics Speicher aus', () => {
        const onSuccess = vi.fn();
        const options = withoutLeaveGuard({ onSuccess }, undefined);

        expect(() => options.onSuccess({})).not.toThrow();
        expect(() => options.onFinish({})).not.toThrow();
        expect(onSuccess).toHaveBeenCalled();
    });
});
