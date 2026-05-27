/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Vite config for the Phlix admin SPA.
 *
 * - `base: '/assets/admin/'` so the emitted asset URLs resolve correctly
 *   when the bundle is served from `public/assets/admin/` by the
 *   server's static-file handler (and nginx `try_files` in production).
 * - `build.outDir` points OUTSIDE this project, directly into the
 *   server's `public/assets/admin/` so the committed bundle is the
 *   artifact the server ships (see the build-output decision in the
 *   step 0.4 worklog: we commit the built bundle, gitignore node_modules,
 *   so the production server has no Node build dependency at runtime).
 * - `emptyOutDir: true` keeps the bundle directory clean between builds;
 *   it is safe because nothing else is written to public/assets/admin/.
 */
export default defineConfig({
  plugins: [react()],
  base: '/assets/admin/',
  build: {
    // Build straight into the server's public dir. Relative to admin-ui/.
    outDir: resolve(__dirname, '../public/assets/admin'),
    emptyOutDir: true,
    sourcemap: true,
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    coverage: {
      provider: 'v8',
      reportsDirectory: './coverage',
      reporter: ['text', 'text-summary', 'html'],
      // Cover the application source only — exclude entry/bootstrap and
      // test scaffolding which have no meaningful branches.
      include: ['src/**/*.{ts,tsx}'],
      exclude: [
        'src/main.tsx',
        'src/test/**',
        'src/**/*.d.ts',
        'src/vite-env.d.ts',
      ],
    },
  },
});
