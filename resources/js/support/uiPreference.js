import { ref, watch } from 'vue';

/**
 * A ref whose value survives leaving the page.
 *
 * WHY THIS EXISTS
 *
 * The preview panels are the first thing in this addon whose state is a
 * *choice about the workspace* rather than a piece of the record being edited.
 * "I want the preview open" is not a property of a campaign — it belongs to
 * the person, across every campaign they open, and asking the server to
 * remember it would mean a column, a migration and a round trip for a piece of
 * furniture.
 *
 * WHY IT IS GUARDED
 *
 * `localStorage` is not always there. A private window, a browser configured to
 * block site data, and the Control Panel opened inside an iframe with
 * third-party storage partitioned off all make the *accessor itself* throw —
 * not return null. An unguarded read at setup time therefore does not lose a
 * preference, it kills the whole page component before it renders. Everything
 * here degrades to "the default, and nothing is remembered".
 *
 * The key is namespaced (`marketing.…`) because the Control Panel's origin is
 * shared with Statamic itself and every other addon on the site.
 */
function read(key, fallback) {
    try {
        const stored = window.localStorage.getItem(key);

        return stored === null ? fallback : JSON.parse(stored);
    } catch (e) {
        return fallback;
    }
}

function write(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        // Storage full, or blocked. The screen keeps working; only the memory
        // of this choice is lost, and silently is the right way to lose it.
    }
}

/**
 * @param {string} name  Unprefixed preference name, e.g. `campaign.preview.open`.
 * @param {*}      fallback  What to use when nothing was ever stored.
 */
export function uiPreference(name, fallback) {
    const key = `marketing.${name}`;
    const value = ref(read(key, fallback));

    watch(value, (next) => write(key, next));

    return value;
}
