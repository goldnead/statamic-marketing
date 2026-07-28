/**
 * Vitest bootstrap for the Control Panel components.
 *
 * `@statamic/cms/ui` and `@statamic/cms/inertia` are thin re-export shims:
 *
 *     export const { Alert, Badge, Button, … } = __STATAMIC__.ui;
 *
 * `__STATAMIC__` is put on the window by the Control Panel at runtime. In a
 * test process nobody does that, so the destructure would throw before a
 * single assertion ran — every test would fail at an import instead of at the
 * thing under test. We install the global here, before any test module is
 * imported, and answer every requested name with a stub component.
 *
 * The stubs render a `<div data-stub="Badge" data-attr-text="…">`: every
 * scalar attribute is mirrored into the DOM, so a test can assert both that a
 * component was rendered and what it was handed — without depending on the
 * real CP markup, which is not ours to pin down.
 */
import { defineComponent, h, reactive } from 'vue';
import { config } from '@vue/test-utils';

const stubs = new Map();

/**
 * A component that renders its name, its scalar attributes and its slot.
 *
 * `:mode="responseMode()"` becomes `data-attr-mode="json"`, so a test asserts
 * against the DOM instead of poking at component internals, and can locate a
 * component by what it was given (`[data-attr-heading="Response"]`) rather
 * than by its position in a list.
 */
function stubComponent(name) {
    if (!stubs.has(name)) {
        stubs.set(name, defineComponent({
            name,
            inheritAttrs: false,
            setup(_props, { attrs, slots }) {
                return () => {
                    const rendered = { 'data-stub': name };
                    const text = [];

                    for (const [key, value] of Object.entries(attrs)) {
                        if (value === null || value === undefined) continue;
                        if (typeof value === 'object' || typeof value === 'function') continue;

                        // `data-testid` and friends pass through untouched so
                        // a test can select by the marker the template sets.
                        rendered[
                            key.startsWith('data-')
                                ? key
                                : 'data-attr-' + key.replace(/([A-Z])/g, '-$1').toLowerCase()
                        ] = String(value);

                        // Text-carrying props also go into the text content so
                        // `wrapper.text()` reads like the rendered page does.
                        if (['text', 'heading', 'title', 'label'].includes(key)) {
                            text.push(h('span', String(value)));
                        }
                    }

                    return h('div', rendered, [...text, slots.default ? slots.default() : null]);
                };
            },
        }));
    }

    return stubs.get(name);
}

/** Anything asked of `__STATAMIC__.ui` is a component. */
const componentBag = () => new Proxy({}, {
    get: (_target, prop) => (typeof prop === 'string' ? stubComponent(prop) : undefined),
});

/** `inertia` mixes components (Head, Link) with plain helpers (router). */
const inertiaHelpers = {
    router: {
        reload: () => {},
        visit: () => {},
        get: () => {},
        post: () => {},
    },
    useForm: (data) => reactive({ ...data, processing: false, errors: {} }),
    usePoll: () => {},
    toggleArchitecturalBackground: () => {},
    useArchitecturalBackground: () => ({}),
};

globalThis.__STATAMIC__ = {
    ui: componentBag(),
    api: componentBag(),
    bard: componentBag(),
    cms: componentBag(),
    'save-pipeline': componentBag(),
    inertia: new Proxy(inertiaHelpers, {
        get: (target, prop) => (
            prop in target
                ? target[prop]
                : (typeof prop === 'string' ? stubComponent(prop) : undefined)
        ),
    }),
};

// The CP exposes the translator as a global template helper. Templates call
// `__('Delivery')`; returning the key keeps assertions readable.
const translate = (key) => (Array.isArray(key) ? key.filter(Boolean).join(' ') : String(key ?? ''));

config.global.mocks = { __: translate };

// It is a real global too, not only a template helper: a `<script setup>`
// block calls `__('Choose a list...')` directly when it builds an option list
// or a status label, and Vue Test Utils' `mocks` only reach templates. Without
// this the component throws at setup, before it can render anything.
globalThis.__ = translate;

// Statamic's remaining Vue 2-era global components are not registered here.
config.global.stubs = {
    'date-time': true,
    'svg-icon': true,
};
