<?php

declare(strict_types=1);

namespace Phlix\Plugins\Installer;

/**
 * Normalises an operator-supplied plugin *source* URL into a concrete
 * archive URL that {@see HttpInstaller} can download and extract.
 *
 * The admin "Install plugin" UI and the `plugin:install` CLI both invite a
 * **repository** URL — e.g. `https://github.com/detain/phlix-plugin-anidb` —
 * but the installer can only fetch a direct archive (`.zip`, `.tar.gz`,
 * `.tgz`) or a `.json` stub. Submitting a bare repository URL therefore
 * failed with "Unsupported plugin source extension". This resolver bridges
 * that gap by rewriting well-known git-host repository URLs to their
 * tarball:
 *
 * ```
 * https://github.com/OWNER/REPO          -> https://github.com/OWNER/REPO/archive/HEAD.tar.gz
 * https://github.com/OWNER/REPO.git      -> https://github.com/OWNER/REPO/archive/HEAD.tar.gz
 * https://github.com/OWNER/REPO/         -> https://github.com/OWNER/REPO/archive/HEAD.tar.gz
 * https://github.com/OWNER/REPO/tree/BR  -> https://github.com/OWNER/REPO/archive/BR.tar.gz
 * git@github.com:OWNER/REPO.git          -> https://github.com/OWNER/REPO/archive/HEAD.tar.gz
 * github.com/OWNER/REPO                  -> https://github.com/OWNER/REPO/archive/HEAD.tar.gz
 * ```
 *
 * `HEAD` resolves to the repository's default branch, so no GitHub API call
 * (and no User-Agent dance) is required. Anything that already looks like a
 * direct archive, a `file://` path, or any URL this resolver does not
 * recognise is returned **unchanged** — making the resolver a safe no-op for
 * every pre-existing caller and fully idempotent.
 *
 * @package Phlix\Plugins\Installer
 * @since 0.31.0
 */
final class SourceUrlResolver
{
    /**
     * Archive / stub suffixes {@see HttpInstaller::fetchInto()} already
     * understands. A URL ending in one of these is left untouched.
     */
    private const ARCHIVE_SUFFIXES = ['.zip', '.tar.gz', '.tgz', '.json'];

    /**
     * Path segments that mean the URL already points *inside* a repository
     * (a release asset, a raw blob, an archive, …) rather than at the
     * repository root, so it must not be rewritten.
     */
    private const NON_ROOT_SEGMENTS = ['archive', 'releases', 'raw', 'blob', 'tags', 'commit'];

    /**
     * Rewrite a repository URL to a downloadable tarball, or return the URL
     * unchanged when it is already an archive / not a recognised repo URL.
     *
     * @param string $url The raw source URL as typed by the operator.
     *
     * @return string A URL {@see HttpInstaller} can fetch and extract.
     *
     * @since 0.31.0
     */
    public static function normalize(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return $url;
        }

        // Already a direct archive or `.json` stub — never rewrite it.
        if (self::hasArchiveSuffix($trimmed)) {
            return $trimmed;
        }

        $repo = self::matchGitHubRepo($trimmed);
        if ($repo === null) {
            return $trimmed;
        }

        [$owner, $name, $ref] = $repo;
        $ref = $ref ?? 'HEAD';

        return sprintf('https://github.com/%s/%s/archive/%s.tar.gz', $owner, $name, $ref);
    }

    /**
     * Whether the URL's path already ends in a known archive/stub suffix.
     */
    private static function hasArchiveSuffix(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);
        $haystack = strtolower(is_string($path) && $path !== '' ? $path : $url);
        foreach (self::ARCHIVE_SUFFIXES as $suffix) {
            if (str_ends_with($haystack, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a GitHub repository URL into `[owner, repo, ref|null]`.
     *
     * Accepts `https://`, `http://`, scheme-less `github.com/…`, and the
     * `git@github.com:owner/repo.git` SSH form. Returns `null` for anything
     * that is not a GitHub repository-root URL.
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
            // GitHub host, so we never turn a local path into a remote fetch.
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

        // A third segment that names a sub-resource (release, blob, raw, …)
        // means the URL is not a plain repository root — leave it alone.
        if (isset($segments[2]) && $segments[2] !== '') {
            $kind = strtolower($segments[2]);
            if ($kind === 'tree' && isset($segments[3]) && $segments[3] !== '') {
                // /tree/<ref>[/path] — take the ref (may itself contain '/').
                $ref = implode('/', array_slice($segments, 3));
                return [$owner, $name, $ref];
            }
            if (in_array($kind, self::NON_ROOT_SEGMENTS, true)) {
                return null;
            }
            // Unknown trailing segment — be conservative and skip.
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
