<?php

/**
 * Phlix media server component: Catalog.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

/**
 * Normalises an operator-supplied **catalog** source URL into a concrete
 * `plugins.json` URL that {@see PluginCatalogService} can fetch.
 *
 * The admin "Plugins" section seeds itself from a *catalog* repository — a
 * git repo whose root holds a `plugins.json` listing installable plugins
 * (see https://github.com/detain/phlix-plugins). Operators paste a
 * **repository** URL, but the service can only fetch a direct JSON file, so
 * this resolver rewrites well-known git-host repository URLs to their raw
 * `plugins.json`:
 *
 * ```
 * https://github.com/OWNER/REPO              -> https://raw.githubusercontent.com/OWNER/REPO/HEAD/plugins.json
 * https://github.com/OWNER/REPO.git          -> https://raw.githubusercontent.com/OWNER/REPO/HEAD/plugins.json
 * https://github.com/OWNER/REPO/tree/BRANCH  -> https://raw.githubusercontent.com/OWNER/REPO/BRANCH/plugins.json
 * github.com/OWNER/REPO                       -> https://raw.githubusercontent.com/OWNER/REPO/HEAD/plugins.json
 * git@github.com:OWNER/REPO.git               -> https://raw.githubusercontent.com/OWNER/REPO/HEAD/plugins.json
 * ```
 *
 * A URL that already points at a `.json` file (a raw blob, a gist, a
 * self-hosted catalog) is returned **unchanged**, as is anything this
 * resolver does not recognise — making it a safe, idempotent no-op for every
 * direct-file caller. `HEAD` resolves to the repository's default branch, so
 * no GitHub API call is required.
 *
 * Mirrors {@see \Phlix\Plugins\Installer\SourceUrlResolver} (which rewrites
 * the same repo URLs to a *tarball* for installation); the two are kept
 * separate because a catalog wants the raw manifest, not an archive.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.33.0
 */
final class CatalogSourceResolver
{
    /**
     * Default catalog file fetched from a repository root when the operator
     * gives a bare repo URL.
     */
    public const CATALOG_FILE = 'plugins.json';

    /**
     * Owner/name of the official, first-party catalog repository. Only this
     * repo is auto-pinned (SV-S2b) — operator-added catalogs keep `HEAD`.
     */
    public const OFFICIAL_OWNER = 'detain';
    public const OFFICIAL_REPO = 'phlix-plugins';

    /**
     * The default ref the official catalog is pinned to (SV-S2b). A release
     * tag/commit of `detain/phlix-plugins` so a malicious merge to the default
     * branch cannot silently rewrite every operator's targets. Operator-
     * overridable via the {@see PINNED_REF_ENV} env var.
     *
     * NOTE: kept as a release tag (not a moving branch). When phlix-plugins
     * cuts a new pinned catalog release, bump this constant in lockstep.
     */
    public const OFFICIAL_PINNED_REF = 'v2.3.0';

    /**
     * Env var to override the official catalog pinned ref without a code change
     * (e.g. to track a newer phlix-plugins release tag, or to roll back).
     */
    public const PINNED_REF_ENV = 'PHLIX_PLUGINS_CATALOG_REF';

    /**
     * Release channels for the OFFICIAL first-party catalog (S27). The channel
     * selects which ref the official catalog resolves to when no explicit ref
     * is given in the URL and no {@see PINNED_REF_ENV} override is set:
     *
     *  - {@see CHANNEL_STABLE} (default) → {@see OFFICIAL_PINNED_REF}, the
     *    audited pinned release tag.
     *  - {@see CHANNEL_DEV} → {@see DEV_REF} (`master`), the moving default
     *    branch — **opt-in / advanced**.
     *
     * The channel only widens catalog *discovery*: per-entry `ref` +
     * `artifactSha256` verification still gates every actual install on BOTH
     * channels (see {@see PluginCatalogService::pinFor()} +
     * {@see \Phlix\Plugins\PluginLoader::install()}), so `dev` does not move the
     * trust boundary.
     */
    public const CHANNEL_STABLE = 'stable';
    public const CHANNEL_DEV = 'dev';

    /** The ref the `dev` channel tracks: the catalog repo's moving default branch. */
    public const DEV_REF = 'master';

    /**
     * Rewrite a GitHub repository URL to a raw file in its default branch
     * (`$file`, default `plugins.json`), or return the URL unchanged when it
     * already names a `.json` file or is not a recognised GitHub repository URL.
     *
     * @param string      $url         The raw repository/source URL.
     * @param string      $file        Repo-relative file to resolve to (e.g.
     *                                 `plugin.json` for a plugin's own manifest,
     *                                 used by update checks).
     * @param string|null $officialRef Ref selected by the caller's catalog
     *                                 channel for the OFFICIAL repo (S27; see
     *                                 {@see refForChannel()}). `null` preserves
     *                                 the historic env-or-pinned behaviour. The
     *                                 {@see PINNED_REF_ENV} env override still
     *                                 takes precedence over this value.
     *
     * @return string A raw URL the caller can fetch.
     *
     * @since 0.33.0
     */
    public static function normalize(
        string $url,
        string $file = self::CATALOG_FILE,
        ?string $officialRef = null,
    ): string {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $url;
        }

        // Already a direct JSON document — never rewrite it.
        if (self::isJsonUrl($trimmed)) {
            return $trimmed;
        }

        $repo = self::matchGitHubRepo($trimmed);
        if ($repo === null) {
            return $trimmed;
        }

        [$owner, $name, $ref] = $repo;
        if ($ref === null || $ref === '') {
            // SV-S2b: the official catalog resolves to a configurable PINNED ref
            // (a phlix-plugins release tag), never the moving default branch, so
            // a malicious merge to `master` cannot silently rewrite every
            // operator's install targets. Operator-added catalogs (any other
            // owner/repo) keep `HEAD` but their entries are subject to
            // default-deny at install time (PluginLoader::assertVerifiedOrOverride).
            $ref = self::isOfficialRepo($owner, $name) ? self::officialPinnedRef($officialRef) : 'HEAD';
        }

        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            $owner,
            $name,
            $ref,
            ltrim($file, '/'),
        );
    }

    /**
     * Whether `$owner/$name` is the first-party catalog repo (case-insensitive).
     */
    private static function isOfficialRepo(string $owner, string $name): bool
    {
        return strtolower($owner) === self::OFFICIAL_OWNER
            && strtolower($name) === self::OFFICIAL_REPO;
    }

    /**
     * The configured ref for the official catalog, resolved in strict
     * precedence order (highest first):
     *
     *   1. the {@see PINNED_REF_ENV} env override, when set — the operator
     *      escape hatch, always wins;
     *   2. `$channelRef` — the ref selected by the `plugins.catalog.channel`
     *      setting (`master` for `dev`), when the caller supplies one;
     *   3. {@see OFFICIAL_PINNED_REF} — the audited default (stable channel).
     *
     * i.e. **env > setting > default** (S27). Passing `$channelRef = null`
     * preserves the historic env-or-pinned behaviour exactly.
     *
     * @param string|null $channelRef Setting-derived ref (see {@see refForChannel()}),
     *                                or `null` when the caller supplies no channel.
     */
    public static function officialPinnedRef(?string $channelRef = null): string
    {
        $env = getenv(self::PINNED_REF_ENV);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if ($channelRef !== null && trim($channelRef) !== '') {
            return trim($channelRef);
        }
        return self::OFFICIAL_PINNED_REF;
    }

    /**
     * Map a catalog channel name to the OFFICIAL-catalog ref it selects, or
     * `null` for the stable/default channel (which keeps {@see OFFICIAL_PINNED_REF}).
     *
     * Unknown, empty, or mixed-case values are treated as **stable** (fail-safe):
     * a typo can never silently promote installs to the moving `dev` branch.
     *
     * @param string $channel A channel name (`stable` | `dev`).
     *
     * @return string|null {@see DEV_REF} for the `dev` channel, else `null`.
     *
     * @since 0.42.0
     */
    public static function refForChannel(string $channel): ?string
    {
        return strtolower(trim($channel)) === self::CHANNEL_DEV ? self::DEV_REF : null;
    }

    /**
     * Whether the URL's path ends in `.json` (case-insensitive).
     */
    private static function isJsonUrl(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $haystack = strtolower(is_string($path) && $path !== '' ? $path : $url);
        return str_ends_with($haystack, '.json');
    }

    /**
     * Parse a GitHub repository URL into `[owner, repo, ref|null]`.
     *
     * Accepts `https://`, `http://`, scheme-less `github.com/…`, and the
     * `git@github.com:owner/repo.git` SSH form. Returns `null` for anything
     * that is not a GitHub repository-root (or `/tree/<ref>`) URL.
     *
     * @return array{0: string, 1: string, 2: string|null}|null
     */
    private static function matchGitHubRepo(string $url): ?array
    {
        // SSH form: git@github.com:owner/repo(.git)
        if (preg_match('#^git@github\.com:([^/]+)/(.+)$#i', $url, $m) === 1) {
            $owner = $m[1];
            $name = self::stripGit(rtrim($m[2], '/'));
            return self::validIdentifiers($owner, $name) ? [$owner, $name, null] : null;
        }

        $candidate = $url;
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $candidate) !== 1) {
            // No scheme: only adopt one when the string clearly starts at the
            // GitHub host, so a local path is never turned into a remote fetch.
            if (preg_match('#^(www\.)?github\.com/#i', $candidate) !== 1) {
                return null;
            }
            $candidate = 'https://' . $candidate;
        }

        $host = strtolower((string) (parse_url($candidate, PHP_URL_HOST) ?? ''));
        if ($host !== 'github.com' && $host !== 'www.github.com') {
            return null;
        }

        $path = trim((string) (parse_url($candidate, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        if (count($segments) < 2) {
            return null;
        }

        $owner = $segments[0];
        $name = self::stripGit($segments[1]);
        if (!self::validIdentifiers($owner, $name)) {
            return null;
        }

        // `/tree/<ref>[/path]` names a branch/tag — take the ref. Any other
        // trailing sub-resource means the URL is not a plain repo root, so we
        // leave it untouched rather than guess.
        if (isset($segments[2]) && $segments[2] !== '') {
            if (strtolower($segments[2]) === 'tree' && isset($segments[3]) && $segments[3] !== '') {
                $ref = implode('/', array_slice($segments, 3));
                return [$owner, $name, $ref];
            }
            return null;
        }

        return [$owner, $name, null];
    }

    /**
     * Strip a trailing `.git` from a repository name component.
     */
    private static function stripGit(string $name): string
    {
        if (str_ends_with(strtolower($name), '.git')) {
            return substr($name, 0, -4);
        }
        return $name;
    }

    /**
     * GitHub owner/repo identifiers are limited to word characters, dots and
     * hyphens. Reject anything else (query strings, `..`, encoded slashes).
     */
    private static function validIdentifiers(string $owner, string $name): bool
    {
        foreach ([$owner, $name] as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '..')) {
                return false;
            }
            if (preg_match('#^[A-Za-z0-9._-]+$#', $segment) !== 1) {
                return false;
            }
        }
        return true;
    }
}
