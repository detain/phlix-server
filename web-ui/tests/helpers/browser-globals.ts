/**
 * Minimal browser-global stubs so the REAL `@phlix/ui` app factory can run under
 * plain `node --test` — deliberately NO jsdom and NO new dev dependency (the
 * web-ui package stays dependency-clean; the estate doctrine keeps the heavy
 * component-matrix coverage in phlix-ui itself).
 *
 * Import this module FIRST in a test file — ESM evaluates static imports in
 * source order, so the globals exist before `@phlix/ui` / `vue-router` run their
 * module bodies or `createPhlixApp()` is called. Each node test file gets its
 * own process, so nothing here leaks into other suites.
 *
 * What the production boot path actually touches (all measured, not guessed):
 * `createWebHistory()` reads the bare `location`/`history` globals +
 * `window.addEventListener`; the token store defaults to `window.localStorage`;
 * `applyStoredThemeEarly`/`hasStoredPreferences` read
 * `document.documentElement` + storage. An EMPTY storage (every `getItem` →
 * `null`) is the honest "first visit" state: no tokens, no stored theme, so no
 * auth fetch is fired and the probe renders synchronously.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

const storage = new Map<string, string>();
const storageStub = {
    getItem: (key: string) => storage.get(key) ?? null,
    setItem: (key: string, value: string) => void storage.set(key, value),
    removeItem: (key: string) => void storage.delete(key),
    clear: () => storage.clear(),
    key: (index: number) => [...storage.keys()][index] ?? null,
    get length() {
        return storage.size;
    },
};

const windowStub = {
    location: {
        href: 'http://phlix.invalid/app',
        origin: 'http://phlix.invalid',
        protocol: 'http:',
        host: 'phlix.invalid',
        hostname: 'phlix.invalid',
        pathname: '/app',
        search: '',
        hash: '',
    },
    history: {
        state: null,
        pushState: () => {},
        replaceState: () => {},
        go: () => {},
        back: () => {},
        forward: () => {},
        length: 1,
    },
    addEventListener: () => {},
    removeEventListener: () => {},
    scrollTo: () => {},
    matchMedia: () => ({
        matches: false,
        addEventListener: () => {},
        removeEventListener: () => {},
    }),
    localStorage: storageStub,
};

const styleStub = {
    setProperty: () => {},
    removeProperty: () => {},
    getPropertyValue: () => '',
};

const elementStub = {
    style: styleStub,
    classList: {
        add: () => {},
        remove: () => {},
        contains: () => false,
        toggle: () => {},
    },
    dataset: {} as Record<string, string>,
    setAttribute: () => {},
    getAttribute: () => null,
    hasAttribute: () => false,
    removeAttribute: () => {},
    appendChild: () => {},
};

const documentStub = {
    title: '',
    documentElement: elementStub,
    head: elementStub,
    body: elementStub,
    addEventListener: () => {},
    removeEventListener: () => {},
    createElement: () => ({ style: styleStub, setAttribute: () => {}, appendChild: () => {} }),
    querySelector: () => null,
};

// Node >=21 ships a getter-only `navigator`; replace it deterministically so
// `navigator.language` is a real signal (jsdom parity for the resolver default).
globalThis.window = windowStub;
globalThis.location = windowStub.location;
globalThis.history = windowStub.history;
globalThis.localStorage = storageStub;
globalThis.document = documentStub;
Object.defineProperty(globalThis, 'navigator', {
    value: { language: 'en-US', languages: ['en-US'], userAgent: 'node-test', onLine: true },
    configurable: true,
    writable: true,
});

export {};
