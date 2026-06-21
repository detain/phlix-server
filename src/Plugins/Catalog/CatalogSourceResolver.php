<?php

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
     * Rewrite a GitHub repository URL to a raw file in its default branch
     * (`$file`, default `plugins.json`), or return the URL unchanged when it
     * already names a `.json` file or is not a recognised GitHub repository URL.
     *
     * @param string $url  The raw repository/source URL.
     * @param string $file Repo-relative file to resolve to (e.g. `plugin.json`
     *                     for a plugin's own manifest, used by update checks).
     *
     * @return string A raw URL the caller can fetch.
     *
     * @since 0.33.0
     */
    public static function normalize(string $url, string $file = self::CATALOG_FILE): string
    {
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
        $ref = $ref ?? 'HEAD';

        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/%s',
            $owner,
            $name,
            $ref,
            ltrim($file, '/'),
        );
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
