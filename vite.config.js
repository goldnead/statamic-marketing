import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/js/cp.js',
                'resources/css/cp.css',
            ],
            // Statamic's AddonServiceProvider publishes the compiled assets from
            // <publicDirectory>/build — the same directory configured on the
            // ServiceProvider's $vite property. laravel-vite-plugin emits the
            // manifest flat at resources/dist/build/manifest.json, where the
            // Laravel/Statamic Vite tag looks for it.
            publicDirectory: 'resources/dist',
            refresh: true,
        }),
        // Externalises `vue` to the CP's runtime build and registers the Vue
        // plugin, so the addon's @statamic/cms/* imports resolve against the
        // host Control Panel instead of being re-bundled.
        statamic(),
        tailwindcss(),
    ],
});
