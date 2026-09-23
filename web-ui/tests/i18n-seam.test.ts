/**
 * END-TO-END proof that a client-supplied catalog override actually reaches the
 * strings `@phlix/ui` renders — against the REAL sha-pinned `@phlix/ui` bundle,
 * with NO mocks of the seam itself:
 *
 *  - a fake override `{ common: { retry: 'ZZZ-TEST' } }` passed to the real
 *    `createPhlixApp()` surfaces as 'ZZZ-TEST' through the real
 *    `useMessages().t` inside a rendered component, while untouched keys keep
 *    their English defaults;
 *  - omitting `messages` renders the untouched defaults (behavior-preservation);
 *  - the 'en' boot (this client's dominant path) renders byte-identical English
 *    through the EMPTY override;
 *  - the real vendored `es` bundle flips a key through the SAME chain.
 *
 * The probe component consumes `app._context.provides` — the exact object the
 * production factory handed to `provide('phlixConfig', …)` — so the config
 * spread + provide + inject + merge + resolve chain under test IS the production
 * code path. Rendering goes through `vue/server-renderer` (real setup/inject,
 * zero DOM), so the whole suite runs under `node --test` with the minimal
 * globals in helpers/browser-globals.ts and no jsdom dependency.
 *
 * Mirrors the proven phlix-tizen-client tests/unit/i18n.test.ts doctrine.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import './helpers/browser-globals.ts';
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { defineComponent, h } from 'vue';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { createPhlixApp, mergeMessages, useMessages } from '@phlix/ui';
import type { MessageKey, PhlixAppConfig, PhlixMessagesConfig } from '@phlix/ui';
import { messagesForLocale, resolveLocale } from '../src/i18n/index.ts';

/** Vue's internal provide storage of the REAL app instance. `_context.provides`
 *  is stable across Vue 3.x, and reading it is the only way to observe what the
 *  factory provided WITHOUT booting the full router-driven SPA (whose guards
 *  init auth over the network). */
function realProvides(application: ReturnType<typeof createPhlixApp>): Record<string, unknown> {
    return (application as unknown as { _context: { provides: Record<string, unknown> } })._context
        .provides;
}

/** The real config object `createPhlixApp` spread + provided under 'phlixConfig'. */
function providedPhlixConfig(application: ReturnType<typeof createPhlixApp>): PhlixAppConfig {
    return realProvides(application).phlixConfig as PhlixAppConfig;
}

/** A minimal component resolved through ui's REAL `useMessages()` composable. */
function probeComponent(keys: readonly string[]) {
    return defineComponent({
        name: 'I18nProbe',
        setup() {
            const { t } = useMessages();
            return () =>
                h(
                    'div',
                    keys.map((key) => h('span', { 'data-key': key }, t(key as MessageKey))),
                );
        },
    });
}

/** Render the probe against the real app's provided config. Returns the HTML. */
async function renderProbe(
    application: ReturnType<typeof createPhlixApp>,
    keys: readonly string[],
): Promise<string> {
    const ssr = createSSRApp(probeComponent(keys));
    ssr.provide('phlixConfig', providedPhlixConfig(application));
    return renderToString(ssr);
}

/** Extract the rendered text of one probe span. */
function renderedText(html: string, key: string): string {
    const match = html.match(new RegExp(`data-key="${key}">([^<]*)</span>`));
    assert.ok(match, `probe rendered no span for ${key}: ${html}`);
    return match[1];
}

// ---------------------------------------------------------------------------
// 1. End-to-end through the REAL @phlix/ui factory + composable
// ---------------------------------------------------------------------------

describe('end-to-end: client messages override reaches ui-rendered strings (real @phlix/ui, no mocks)', () => {
    const OVERRIDE: PhlixMessagesConfig = { common: { retry: 'ZZZ-TEST' } };

    const app = createPhlixApp({
        app: 'server',
        apiBase: 'http://phlix.invalid',
        messages: OVERRIDE,
    });

    it('createPhlixApp carries the client override into the provided phlixConfig', () => {
        const provided = providedPhlixConfig(app);
        // Same reference: the factory spreads the consumer config verbatim, and
        // useMessages() injects THIS object.
        assert.equal(provided.messages, OVERRIDE);
        assert.equal(provided.messages?.common?.retry, 'ZZZ-TEST');
    });

    it('useMessages().t renders the override through the real inject→merge→resolve chain', async () => {
        const html = await renderProbe(app, ['common.retry', 'common.close']);
        // The overridden string wins end-to-end…
        assert.equal(renderedText(html, 'common.retry'), 'ZZZ-TEST');
        // …while a sibling key in the SAME group keeps its ui English default
        // (per-group partial spread, not whole-group replacement).
        assert.equal(renderedText(html, 'common.close'), 'Close');
    });

    it('omitting messages renders the untouched English default (behavior-preservation pin)', async () => {
        const baseline = createPhlixApp({ app: 'server', apiBase: 'http://phlix.invalid' });
        const html = await renderProbe(baseline, ['common.retry']);
        assert.equal(renderedText(html, 'common.retry'), 'Retry');
    });

    it('the en boot (empty override) renders byte-identical English defaults', async () => {
        const enApp = createPhlixApp({
            app: 'server',
            apiBase: 'http://phlix.invalid',
            messages: messagesForLocale(resolveLocale({ navigatorLanguage: 'en-US' })),
        });
        const html = await renderProbe(enApp, ['common.retry', 'common.close', 'player.play', 'shell.browse']);
        // ui English defaults at the pinned sha — any drift here is a ui-side
        // change, not this wiring.
        assert.equal(renderedText(html, 'common.retry'), 'Retry');
        assert.equal(renderedText(html, 'common.close'), 'Close');
        assert.equal(renderedText(html, 'player.play'), 'Play');
        assert.equal(renderedText(html, 'shell.browse'), 'Browse');
    });

    it('the shipped es bundle flips its keys through the same chain (locale actually reaches render)', async () => {
        const esApp = createPhlixApp({
            app: 'server',
            apiBase: 'http://phlix.invalid',
            messages: messagesForLocale(resolveLocale({ navigatorLanguage: 'es-MX' })),
        });
        const html = await renderProbe(esApp, ['common.retry', 'common.close']);
        assert.equal(renderedText(html, 'common.retry'), 'Reintentar');
        assert.equal(renderedText(html, 'common.close'), 'Cerrar');
    });
});

// ---------------------------------------------------------------------------
// 2. Merge semantics of the seam we wire (ui's own exported function)
// ---------------------------------------------------------------------------

describe('ui mergeMessages semantics the client catalog relies on', () => {
    it('overrides merge per group; keys not overridden fall back to DEFAULT_MESSAGES', () => {
        const merged = mergeMessages({ common: { retry: 'ZZZ-TEST' } });
        assert.equal(merged.common.retry, 'ZZZ-TEST');
        assert.equal(merged.common.close, 'Close');
    });

    it('an EMPTY override === an absent one === untouched English (the en NO-OP law)', () => {
        assert.deepEqual(mergeMessages({}), mergeMessages());
    });

    it('an unknown top-level group is DROPPED (merge iterates only default groups)', () => {
        const bogus = { not_a_group: { anything: 'X' }, common: { retry: 'ZZZ-TEST' } } as unknown as PhlixMessagesConfig;
        const merged = mergeMessages(bogus);
        assert.ok(!('not_a_group' in merged));
        assert.equal(merged.common.retry, 'ZZZ-TEST');
    });

    it('a null group override degrades to the English default instead of throwing', () => {
        const merged = mergeMessages({ common: null } as unknown as PhlixMessagesConfig);
        assert.equal(merged.common.retry, 'Retry');
    });

    it('always returns a fresh object — never the DEFAULT_MESSAGES reference', () => {
        assert.notEqual(mergeMessages(), mergeMessages());
    });
});
