/**
 * Client module tests — parsing, priority table, registry lookup, and the
 * wiring/source pins that keep the seam honest. Pure over the module's public
 * surface (no DOM, no app factory here — the factory chain is proven in
 * tests/i18n-seam.test.ts).
 *
 * Runtime: node's built-in test runner (`npm test`) with native TS type
 * stripping — zero dev dependencies, matching web-ui's lean footprint.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { LOCALE_MESSAGES } from '@phlix/ui';
import type { PhlixMessagesConfig } from '@phlix/ui';
import {
    resolveLocale,
    messagesForLocale,
    normalizeLocaleTag,
    isSupportedLocale,
    FALLBACK_LOCALE,
    SUPPORTED_LOCALES,
} from '../src/i18n/index.ts';

describe('normalizeLocaleTag', () => {
    it('parses BCP-47 tags to a lowercase primary subtag', () => {
        assert.equal(normalizeLocaleTag('en-US'), 'en');
        assert.equal(normalizeLocaleTag('ES_es'), 'es');
        assert.equal(normalizeLocaleTag('  zh-Hans-CN '), 'zh');
    });

    it('maps EVERY Portuguese signal to the one shipped pt catalog (pt_BR)', () => {
        // Region-aware exception to the primary-subtag rule (docblock in
        // src/i18n/index.ts): collapsing pt to bare 'pt' would drop the only key
        // the registry has, degrading every pt device to English.
        assert.equal(normalizeLocaleTag('pt'), 'pt_BR');
        assert.equal(normalizeLocaleTag('pt_BR'), 'pt_BR');
        assert.equal(normalizeLocaleTag('PT_br'), 'pt_BR');
        assert.equal(normalizeLocaleTag('pt-PT'), 'pt_BR'); // European pt sees Brazilian, not English
        assert.equal(normalizeLocaleTag('pt-419'), 'pt_BR');
    });

    it('yields null for empty/whitespace/absent signals', () => {
        assert.equal(normalizeLocaleTag(''), null);
        assert.equal(normalizeLocaleTag('   '), null);
        assert.equal(normalizeLocaleTag(null), null);
        assert.equal(normalizeLocaleTag(undefined), null);
    });
});

describe('isSupportedLocale', () => {
    it('accepts every registered locale and rejects the rest', () => {
        for (const locale of SUPPORTED_LOCALES) {
            assert.equal(isSupportedLocale(locale), true);
        }
        // Genuinely unsupported: raw subtags that never key a catalog. 'pt' is
        // deliberately rejected BEFORE normalization — the registry key is the
        // region tag 'pt_BR' (see normalizeLocaleTag).
        assert.equal(isSupportedLocale('zz'), false);
        assert.equal(isSupportedLocale('ko'), false);
        assert.equal(isSupportedLocale('pt'), false);
        assert.equal(isSupportedLocale(null), false);
        assert.equal(isSupportedLocale('constructor'), false); // prototype keys never count
    });
});

describe('resolveLocale priority (explicit → env → navigator → fallback)', () => {
    it('explicit beats env and navigator', () => {
        assert.equal(resolveLocale({ explicit: 'en', env: 'zz', navigatorLanguage: 'zz' }), 'en');
    });

    it('env beats navigator', () => {
        assert.equal(resolveLocale({ env: 'en', navigatorLanguage: 'zz-ZZ' }), 'en');
    });

    it('navigator is used when no higher signal exists', () => {
        assert.equal(resolveLocale({ env: null, navigatorLanguage: 'de-DE' }), 'de');
    });

    it('unsupported signals fall through to the fallback locale', () => {
        assert.equal(resolveLocale({ explicit: 'zz', env: 'xx_YY', navigatorLanguage: 'kl-GL' }), FALLBACK_LOCALE);
    });

    it('a higher-priority UNSUPPORTED signal does not veto a lower supported one', () => {
        // 'zz' parses but has no catalog → the walk continues to the next signal.
        assert.equal(resolveLocale({ explicit: 'zz-ZZ', env: null, navigatorLanguage: 'en_US' }), 'en');
    });

    it('a higher-priority SUPPORTED regional signal wins outright', () => {
        assert.equal(resolveLocale({ explicit: 'es-ES', env: 'ja', navigatorLanguage: 'de' }), 'es');
    });

    it('every Portuguese signal resolves to pt_BR', () => {
        assert.equal(resolveLocale({ navigatorLanguage: 'pt-PT' }), 'pt_BR');
    });

    it('never returns anything outside SUPPORTED_LOCALES', () => {
        const probes = ['', '   ', 'x', 'en-Latn-US', 'EN', 'en_US', null, undefined] as const;
        for (const explicit of probes) {
            for (const env of probes) {
                for (const navigatorLanguage of probes) {
                    const locale = resolveLocale({ explicit, env, navigatorLanguage });
                    assert.ok(SUPPORTED_LOCALES.includes(locale));
                }
            }
        }
    });
});

describe('messagesForLocale', () => {
    it('en resolves to an EMPTY override (ui English defaults pass through untouched)', () => {
        assert.deepEqual(messagesForLocale('en'), {});
        assert.deepEqual(messagesForLocale('EN-US'), {});
    });

    it('unsupported/mistyped locales select the fallback catalog without throwing', () => {
        assert.deepEqual(messagesForLocale('klingon'), messagesForLocale(FALLBACK_LOCALE));
        assert.deepEqual(messagesForLocale(''), messagesForLocale(FALLBACK_LOCALE));
    });

    it('returns a fresh two-level copy per call (registry data is unshared and unmutatable)', () => {
        const a = messagesForLocale('es');
        const b = messagesForLocale('es');
        assert.notEqual(a, b);
        assert.deepEqual(a, b);
        // Mutating one copy must not bleed into the next — the groups are copies too.
        const firstGroup = Object.keys(a)[0] as keyof typeof a;
        const anyKey = Object.keys(a[firstGroup] as object)[0];
        (a[firstGroup] as Record<string, string>)[anyKey] = 'ZZZ-MUTATED';
        assert.notEqual((b[firstGroup] as Record<string, string>)[anyKey], 'ZZZ-MUTATED');
        assert.notEqual(messagesForLocale('es') && (messagesForLocale('es')[firstGroup] as Record<string, string>)[anyKey], 'ZZZ-MUTATED');
    });

    it('the six estate locales resolve to complete, non-empty override maps', () => {
        for (const locale of SUPPORTED_LOCALES) {
            if (locale === 'en') continue;
            const cfg = messagesForLocale(locale);
            assert.ok(Object.keys(cfg).length > 0, `${locale} must carry groups`);
            assert.deepEqual(Object.keys(cfg).sort(), Object.keys(LOCALE_MESSAGES.es).sort(), `${locale} group set == ui bundle group set`);
        }
    });

    it('the result is a legal PhlixMessagesConfig for the ui seam (type-level pin)', () => {
        const cfg: PhlixMessagesConfig = messagesForLocale(resolveLocale({ navigatorLanguage: 'ja-JP' }));
        assert.ok(cfg);
    });
});

describe('registry parity with the @phlix/ui pin (drift guard)', () => {
    it('SUPPORTED_LOCALES minus en == exactly the tags ui LOCALE_MESSAGES ships', () => {
        const uiTags = Object.keys(LOCALE_MESSAGES).sort();
        const clientTags = SUPPORTED_LOCALES.filter((locale) => locale !== 'en').sort();
        assert.deepEqual(clientTags, uiTags);
    });
});

describe('wiring source pins (mechanical proofs the seam is reachable)', () => {
    it('main.ts boots createPhlixApp with the resolved locale messages', () => {
        const main = readFileSync(new URL('../src/main.ts', import.meta.url), 'utf8');
        assert.ok(
            /messages:\s*messagesForLocale\(resolveLocale\(\)\)/.test(main),
            'main.ts must pass messagesForLocale(resolveLocale()) into createPhlixApp',
        );
    });

    it('the i18n module reads the VITE_PHLIX_LOCALE build constant', () => {
        const source = readFileSync(new URL('../src/i18n/index.ts', import.meta.url), 'utf8');
        assert.ok(
            /VITE_PHLIX_LOCALE/.test(source),
            'src/i18n/index.ts must read the VITE_PHLIX_LOCALE env signal',
        );
    });

    it('the env constant is type-declared for vue-tsc', () => {
        const decl = readFileSync(new URL('../src/vite-env.d.ts', import.meta.url), 'utf8');
        assert.ok(/readonly VITE_PHLIX_LOCALE\?: string;/.test(decl));
    });
});
