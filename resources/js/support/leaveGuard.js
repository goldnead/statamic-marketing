/**
 * Statamic's unsaved-changes guard, kept out of the way of saving.
 *
 * THE DEFECT THIS EXISTS FOR
 *
 * A Bard field whose content holds an image marks itself dirty the moment the
 * page loads — nobody has typed anything, and `Statamic.$dirty.names()` already
 * answers `['campaign-content']`. Measured on staging on 19.09.2026: the same
 * campaign without an image answers `[]`.
 *
 * Statamic's guard hooks Inertia's `before` event and asks, through a NATIVE
 * `confirm()`, "You have unsaved changes. Are you sure you want to leave this
 * page?". A native dialog is answered by a human — or, in an automated browser,
 * dismissed by the driver. Either "no" cancels the visit, and Inertia returns
 * without firing `start`, without an error, without a rejected promise.
 *
 * The visible result was a campaign with an image in which **no button did
 * anything**: Save, Send test, Send now, all silent. The editor looked like it
 * had saved (backlog-marketing-kampagne-mit-bild-nicht-speicherbar).
 *
 * WHY THE FIX BELONGS HERE AND NOT IN THE GUARD
 *
 * The guard is right about leaving a page. It is simply wrong about this visit:
 * **saving IS the resolution of the unsaved changes**, not an abandonment of
 * them. Statamic's own publish forms clear the dirty state before they save;
 * this screen did not, because it never touched the dirty state at all.
 *
 * WHY LIFTING THE DIRTY FLAG IS NOT ENOUGH
 *
 * The obvious fix — `$dirty.remove(key)` before the visit — does not work, and
 * the measurement says why. At `inertia:before` the store already answers
 * `names() === []`, and the dialog appears anyway: Statamic installs the
 * listener the first time anything goes dirty and never takes it down again.
 * `remove()` only filters the array; it does not unsubscribe.
 *
 * The one lever that unsubscribes is `disableWarning()`:
 *
 *     function () { window.onbeforeunload = null; P && P(); P = null }
 *
 * `P` is the unsubscribe handle. Once called, it is gone — a later `add()` does
 * NOT re-arm it (measured: `onbeforeunload` stays unset). So this is a trade,
 * and it is made deliberately and narrowly:
 *
 *  - A successful save answers 303, Inertia follows it, the page component is
 *    rebuilt and Statamic arms its guard again. Nothing is lost.
 *  - A FAILED save leaves the page standing with the guard gone. For that case
 *    a plain `beforeunload` is installed here as a replacement. It catches
 *    closing the tab and typing another address; it does NOT catch Inertia's
 *    own in-app navigation, because that hook belongs to Statamic and cannot be
 *    re-registered from outside. Narrower than the original, and the honest
 *    limit of what this can do without a change in Statamic.
 *
 * The dirty flag is lifted as well, so the screen's own state stays truthful,
 * and put back when the request fails.
 */

/** The dirty key belongs to the publish field: `name="campaign-content"`. */
export const CONTENT_DIRTY_KEY = 'campaign-content';

/**
 * Wraps Inertia visit options so the guard cannot cancel the visit.
 *
 * @param {object} options            the visit options (onError/onSuccess may be set)
 * @param {object} [dirty]            Statamic's dirty store; defaults to the global one
 * @param {string} [key]              the dirty key to lift
 * @returns {object} options to hand to `router.patch` / `router.post`
 */
export function withoutLeaveGuard(options = {}, dirty = globalDirty(), key = CONTENT_DIRTY_KEY) {
    // No store (older CP, or a unit test without one): nothing to lift, and the
    // caller must still get working options back.
    const wasDirty = Boolean(dirty && typeof dirty.has === 'function' && dirty.has(key));

    if (wasDirty) {
        dirty.remove(key);
        // Nimmt Statamics Zuhoerer ab. Ohne das kommt der native Dialog, auch
        // wenn nichts mehr als schmutzig gilt.
        dirty.disableWarning?.();
    }

    let restored = false;
    const restore = () => {
        if (wasDirty && ! restored) {
            restored = true;
            dirty.add(key);
            installFallbackGuard();
        }
    };

    return {
        ...options,
        onError: (errors) => {
            // The changes really are still unsaved — warn again next time.
            restore();
            options.onError?.(errors);
        },
        // A visit can also end without success and without `onError`: cancelled,
        // offline, a 500 that Inertia turns into a modal. Anything that is not a
        // success leaves the content unsaved, so the flag goes back.
        onSuccess: (page) => {
            restored = true;
            options.onSuccess?.(page);
        },
        onFinish: (visit) => {
            restore();
            options.onFinish?.(visit);
        },
    };
}

function globalDirty() {
    return typeof window !== 'undefined' ? window.Statamic?.$dirty : undefined;
}

/**
 * Der Ersatz fuer die abgehaengte Wache, nur fuer den Fall eines
 * fehlgeschlagenen Speicherns.
 *
 * Faengt das Schliessen des Tabs und das Eintippen einer anderen Adresse. Die
 * Navigation innerhalb des CP faengt er NICHT — dieser Haken gehoert Statamic
 * und laesst sich von aussen nicht neu setzen. Das ist weniger als vorher und
 * steht hier ausdruecklich, damit niemand mehr Schutz annimmt, als da ist.
 */
export function installFallbackGuard() {
    if (typeof window === 'undefined' || window.onbeforeunload) return;

    window.onbeforeunload = (e) => {
        e.preventDefault();
        // Der Text ist seit Jahren in keinem Browser mehr sichtbar; er muss nur
        // gesetzt sein, damit der Browser ueberhaupt fragt.
        e.returnValue = '';
        return '';
    };
}
