import { vi } from 'vitest';
import { router } from '@statamic/cms/inertia';

/**
 * Shared plumbing for the page tests.
 *
 * A CP page never renders an error on its own: the server rejects, Inertia
 * calls the `onError` handler the page passed to `router.post/patch/delete`,
 * and only then does anything appear. So a test that wants to assert what the
 * user sees has to travel that path — press the button, take the options the
 * page handed the router, and hand back the rejection Laravel would have sent.
 */

/**
 * Replaces the router's verbs with recorders and returns the recorded calls.
 *
 * `router` is the object the `@statamic/cms/inertia` shim destructures out of
 * `__STATAMIC__`, so the page and the test hold the same reference and
 * replacing a method here replaces the one the page calls.
 */
export function captureRouter() {
    const calls = [];

    for (const verb of ['post', 'patch', 'put', 'delete', 'get', 'reload']) {
        // setup.js carries the verbs webhook-manager needed; this control
        // panel also patches and deletes. Defining the missing ones here keeps
        // that file the shared, addon-independent bootstrap it is meant to be.
        if (! (verb in router)) {
            router[verb] = () => {};
        }

        vi.spyOn(router, verb).mockImplementation((...args) => {
            // delete/reload take (url, options); post/patch take (url, data, options).
            const options = args.find((argument, index) => index > 0
                && argument
                && typeof argument === 'object'
                && ('onError' in argument || 'onSuccess' in argument || 'preserveScroll' in argument));

            calls.push({ verb, url: args[0], options: options ?? {} });
        });
    }

    return calls;
}

/**
 * Presses the control carrying this label, the way a person would.
 *
 * The stubs render every scalar attribute, so a control is located by what the
 * template labelled it with rather than by its position, and the handler that
 * is invoked is the one the template actually bound to `@click`.
 *
 * `DropdownItem` counts as well as `Button`: a destructive page action is not
 * a `Button variant="danger"` in the header, it is a `DropdownItem
 * variant="destructive"` in the header's `…` menu (ui-vocabulary §9
 * antipattern 24). To the person clicking it that is the same press, so the
 * test that asserts what a refused delete shows should not have to know which
 * of the two the page happens to render.
 */
export function press(wrapper, label) {
    const button = [
        ...wrapper.findAllComponents({ name: 'Button' }),
        ...wrapper.findAllComponents({ name: 'DropdownItem' }),
    ].find((candidate) => candidate.attributes('data-attr-text') === label);

    if (! button) {
        throw new Error(`No button labelled "${label}" is rendered.`);
    }

    button.vm.$attrs.onClick?.();

    return button;
}

/** The rejection Inertia would deliver for a 422, in the shape it delivers it. */
export async function reject(wrapper, call, errors) {
    call.options.onError?.(errors);

    await wrapper.vm.$nextTick();
}

/** The summary panel above the form, or null when the page is not showing one. */
export function summary(wrapper) {
    const found = wrapper.find('[data-marketing-form-errors]');

    return found.exists() ? found : null;
}

/**
 * Everything the page is showing the user, as one string.
 *
 * Field-level errors reach the DOM through the stub's mirrored attributes
 * (`data-attr-error`), the summary through ordinary text, so "is this message
 * visible anywhere" has to look at both.
 */
export function visibleText(wrapper) {
    const attributes = wrapper.findAll('[data-attr-error]')
        .map((node) => node.attributes('data-attr-error'))
        .join(' | ');

    return `${wrapper.text()} | ${attributes}`;
}
