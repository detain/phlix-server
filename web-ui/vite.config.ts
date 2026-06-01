import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'node:path';

/**
 * Vite config for the shared Vue 3 SPA consumer.
 *
 * - `base: '/assets/app/'` so emitted asset URLs resolve correctly when the
 *   bundle is served from `public/assets/app/` by the server's static-file
 *   handler (and nginx `try_files` in production).
 * - `build.outDir` points directly into the server's `public/assets/app/`
 *   so the committed bundle is the artifact the server ships — the production
 *   Workerman server has no Node build dependency at runtime.
 * - `emptyOutDir: true` keeps the bundle directory clean between builds.
 * - `manifest: true` emits a `manifest.json` that `ViteAssets` reads to
 *   inject the correct asset filenames into the Smarty shell template.
 * - The `index.html` at the project root is the HTML entry point;
 *   Vite's Vue plugin processes it and copies the result to `outDir`.
 */
export default defineConfig({
    plugins: [vue()],
    base: '/assets/app/',
    build: {
        outDir: resolve(__dirname, '../public/assets/app'),
        emptyOutDir: true,
        sourcemap: true,
        manifest: true,
    },
});
