/**
 * Client-local i18n entry point — resolves the boot locale and hands the
 * `@phlix/ui` app factory its message-catalog overrides.
 *
 * ## The seam this wires (verified against the resolved sha pin)
 *
 * `@phlix/ui` exposes a CONFIG-TIME i18n seam: `PhlixAppConfig.messages`
 * (a `PhlixMessagesConfig` = deep-partial `group.key` override map).
 * `createPhlixApp` spreads the consumer config and `provide()`s it under
 * `'phlixConfig'`; every `useMessages()` call injects it and builds a
 * translator via `createTranslator(config?.messages)`. Before this module the
 * server's web-ui passed no `messages`, so the seam existed but was unreachable
 * from this client (the estate i18n audit finding this wiring closes).
 *
 * Unlike the vendored-bundle clients (Tizen, Windows), this consumer's
 * `@phlix/ui` pin (a 40-hex sha on phlix-ui master) SHIPS the locale bundles
 * from its main entry — `LOCALE_MESSAGES` / `ES_MESSAGES`…`JA_MESSAGES` /
 * `PhlixLocaleCode` — so there is nothing to re-vendor: the ui package IS the
 * catalog SSOT here, and ui upgrades flow through the pin.
 *
 * ## Merge semantics we rely on (mirrored from ui `mergeMessages`)
 *
 * The catalog is exactly TWO levels (`group.key`). ui merges per group:
 * `{ ...DEFAULT_MESSAGES[group], ...overrides[group] }` for every group that
 * EXISTS in the defaults — an override for an unknown group is dropped, an
 * override for an unknown key inside a known group rides along but nothing
 * renders it. So a locale file may only override known `group.key` pairs;
 * partial groups are fine (omitted keys fall back to ui's English).
 *
 * ## Locale priority (highest first)
 *
 * 1. `explicit`  — a caller-supplied value (a future Settings row / embedded
 *    query param threads through this argument).
 * 2. `env`       — `VITE_PHLIX_LOCALE` build-time constant.
 * 3. `navigatorLanguage` — `navigator.language` of the viewer's browser.
 * 4. `FALLBACK_LOCALE` ('en').
 *
 * Every candidate is PARSED (Law 2): normalized against the registry (primary
 * BCP-47 subtag, with `pt` region-aware — see `normalizeLocaleTag`) and tested
 * against the catalog table, so only a `SupportedLocale` ever leaves
 * `resolveLocale` — downstream code never re-validates.
 *
 * ## The 'en' NO-OP law
 *
 * English ships NO catalog override: `messagesForLocale('en')` returns an
 * EMPTY map, and ui's `mergeMessages` makes an empty override indistinguishable
 * from an absent one — every group falls through to `{ ...DEFAULT_MESSAGES[g] }`.
 * So an English boot renders byte-identical to the pre-wiring behavior; the
 * seam is inert until a non-English signal resolves.
 *
 * ## Shipped locales (estate decision, 2026-09)
 *
 * en + the six estate locales es / fr / de / it / pt_BR / ja, authored once in
 * phlix-ui (`src/i18n/locales/`) and consumed here through the pin.
 *
 * ## Adding a 7th locale — two lines here, once ui ships it
 *
 * 1. Bump the `@phlix/ui` pin to a sha whose `LOCALE_MESSAGES` includes the tag.
 * 2. Add it to `SUPPORTED_LOCALES` and one registry line:
 *    `xx: () => freshCopy(LOCALE_MESSAGES.xx)`. The registry-parity test fails
 *    until both halves agree, so a ui-side addition cannot drift unseen here.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
import { LOCALE_MESSAGES } from '@phlix/ui';
import type { PhlixLocaleCode, PhlixMessages, PhlixMessagesConfig } from '@phlix/ui';

/** Locales this client can resolve. Union = 'en' plus every tag ui ships a
 *  complete bundle for — the ui barrel is the SSOT, kept honest by the
 *  registry-parity test in tests/i18n.test.ts. */
export type SupportedLocale = 'en' | PhlixLocaleCode;

/** Last-resort locale when no signal parses to a supported one. */
export const FALLBACK_LOCALE: SupportedLocale = 'en';

/** Every locale `resolveLocale` can select (mirrors the union; kept as data so
 *  tests and future Settings rows can enumerate it without type tricks). */
export const SUPPORTED_LOCALES: readonly SupportedLocale[] = [
    'en',
    'es',
    'fr',
    'de',
    'it',
    'pt_BR',
    'ja',
];

/**
 * Copy a ui bundle into a fresh two-level override map: new top level + shallow-
 * copied group maps, so a consumer mutating its override can never poison the
 * registry's frozen module data (the same "always fresh object" contract ui's
 * own `mergeMessages` upholds).
 */
function freshCopy(bundle: PhlixMessages): PhlixMessagesConfig {
    return Object.fromEntries(
        Object.entries(bundle).map(([group, entries]) => [group, { ...entries }]),
    ) as PhlixMessagesConfig;
}

/** The 'en' entry: NOT a catalog — the deliberate absence of one (see
 *  "The 'en' NO-OP law" above). Fresh object per call like every registry arm. */
function englishNoop(): PhlixMessagesConfig {
    return {};
}

/**
 * Locale → catalog factory. Factories (not constants) keep module-load lazy and
 * hand each caller a fresh object. Registry order is irrelevant; lookup is a
 * property access — and `hasOwnProperty` on THIS table is the support test
 * below, so adding a locale here is the single act that makes it supported.
 */
const MESSAGE_CATALOGS: Record<SupportedLocale, () => PhlixMessagesConfig> = {
    en: englishNoop,
    es: () => freshCopy(LOCALE_MESSAGES.es),
    fr: () => freshCopy(LOCALE_MESSAGES.fr),
    de: () => freshCopy(LOCALE_MESSAGES.de),
    it: () => freshCopy(LOCALE_MESSAGES.it),
    pt_BR: () => freshCopy(LOCALE_MESSAGES.pt_BR),
    ja: () => freshCopy(LOCALE_MESSAGES.ja),
};

/** Inputs `resolveLocale` considers, highest priority first. */
export interface LocaleSignals {
    /** Explicit app-config value (Settings choice, embed param, …). */
    explicit?: string | null;
    /** Build-time env value; defaults to `import.meta.env.VITE_PHLIX_LOCALE`. */
    env?: string | null;
    /** Browser language; defaults to `navigator.language`. */
    navigatorLanguage?: string | null;
}

/**
 * Parse a BCP-47-ish tag ('en-US', 'ES_es', ' es-419 ', 'pt_BR') into its
 * registry key. Splitting on both hyphen and underscore keeps legacy
 * `en_US`-style tags parseable. Empty/whitespace input yields `null` so the
 * caller's early-exit chain treats it as "no signal", never as locale ''.
 *
 * Region rule: the primary subtag wins for every locale EXCEPT Portuguese.
 * The estate ships one pt catalog — `pt_BR`, Brazilian, per the 2026-09
 * decision — and collapsing `pt-*` to bare `pt` would drop the only key the
 * registry has. So any `pt` signal (bare `pt`, `pt_BR`, or a foreign region
 * like `pt-PT`) resolves to `pt_BR`: presenting European-Portuguese viewers
 * their own-language Brazilian variant beats silently degrading them to
 * English, and a future second pt bundle is a registry addition, not a
 * resolver rewrite.
 */
export function normalizeLocaleTag(tag: string | null | undefined): string | null {
    if (!tag) return null;
    const primary = tag.trim().toLowerCase().split(/[-_]/)[0];
    if (primary === '') return null;
    return primary === 'pt' ? 'pt_BR' : primary;
}

/** Whether a normalized primary subtag has a catalog in this client.
 *  `hasOwnProperty` — prototype keys like 'constructor' never count. */
export function isSupportedLocale(tag: string | null): tag is SupportedLocale {
    return tag !== null && Object.prototype.hasOwnProperty.call(MESSAGE_CATALOGS, tag);
}

/** Platform reads, applied only where a signal was not explicitly supplied —
 *  so tests pass everything and stay pure, while boot reads the real world. */
function defaultSignalEnvironment(): Pick<LocaleSignals, 'env' | 'navigatorLanguage'> {
    return {
        // `?.` because this module is loaded under two hosts: the Vite build
        // always defines `import.meta.env`, a plain `node --test` run does not.
        env: import.meta.env?.VITE_PHLIX_LOCALE ?? null,
        // Optional chain: a non-DOM host (worker, odd test) simply offers no signal.
        navigatorLanguage: globalThis.navigator?.language ?? null,
    };
}

/**
 * Resolve the effective locale. Pure over its `signals` argument (Law 3): same
 * signals → same locale; unspecified slots are filled from the environment.
 * Returns the first supported parse; never throws, never returns unsupported.
 */
export function resolveLocale(signals: LocaleSignals = {}): SupportedLocale {
    const environment = defaultSignalEnvironment();
    const candidates = [
        signals.explicit ?? null,
        signals.env ?? environment.env,
        signals.navigatorLanguage ?? environment.navigatorLanguage,
    ];
    for (const candidate of candidates) {
        const parsed = normalizeLocaleTag(typeof candidate === 'string' ? candidate : null);
        if (isSupportedLocale(parsed)) return parsed;
    }
    return FALLBACK_LOCALE;
}

/**
 * The ui `PhlixAppConfig.messages` override map for a locale. The input is
 * parsed (Law 2): anything unsupported — including a mistyped locale — selects
 * the fallback (empty) catalog rather than throwing at boot.
 */
export function messagesForLocale(locale: string): PhlixMessagesConfig {
    const parsed = normalizeLocaleTag(locale);
    const effective: SupportedLocale = isSupportedLocale(parsed) ? parsed : FALLBACK_LOCALE;
    return MESSAGE_CATALOGS[effective]();
}
