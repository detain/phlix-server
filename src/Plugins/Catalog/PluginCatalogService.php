<?php

/**
 * Phlix media server component: Catalog.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugins\Catalog;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Net\SsrfGuard;

/**
 * Fetches and aggregates plugin **catalogs** for the admin Plugins section.
 *
 * A catalog is a `plugins.json` document (see
 * https://github.com/detain/phlix-plugins) listing installable plugins. The
 * server fetches catalogs *server-side* — not from the browser — both to
 * dodge cross-origin restrictions (GitHub raw does not send permissive CORS
 * headers) and to keep a single, auditable egress path.
 *
 * Sources are layered:
 *
 *  - the **default** catalog (`plugins.catalog.default_source`, shipped as
 *    `config/plugins.php` → `detain/phlix-plugins`), always present and not
 *    removable; plus
 *  - any **extra** catalogs the operator adds, persisted as a JSON override
 *    under `plugins.catalog.sources` in `server_settings`.
 *
 * {@see CatalogSourceResolver} turns each repository URL into a raw
 * `plugins.json` URL before fetching. Fetch/parse failures raise
 * {@see CatalogFetchException}, which {@see aggregate()} catches per-source so
 * one dead catalog cannot blank the page.
 *
 * The network fetch is injectable (`$fetcher`) so tests run offline.
 *
 * @package Phlix\Plugins\Catalog
 * @since 0.33.0
 */
final class PluginCatalogService
{
    /**
     * Settings key (dotted) for the immutable default catalog source. Its
     * value lives in `config/plugins.php` and may be overridden per-install.
     */
    public const KEY_DEFAULT_SOURCE = 'plugins.catalog.default_source';

    /** Settings key (dotted) for the operator-added extra catalog sources. */
    public const KEY_SOURCES = 'plugins.catalog.sources';

    /** Settings key (dotted) for the per-fetch timeout in seconds. */
    public const KEY_TIMEOUT = 'plugins.catalog.fetch_timeout';

    /** Settings key (dotted) for the auto-update toggle. */
    public const KEY_AUTO_UPDATE = 'plugins.auto_update';

    /**
     * Hard-coded fallback used only when `config/plugins.php` is absent (e.g.
     * a partial checkout), so the section is never sourceless.
     */
    public const FALLBACK_DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';

    /** Default fetch timeout (seconds) when the config omits one. */
    private const DEFAULT_TIMEOUT = 10;

    /**
     * @var callable(string, int): string
     * A fetcher mapping `(url, timeoutSeconds)` to a response body, throwing
     * {@see \RuntimeException} on transport failure.
     */
    private $fetcher;

    /**
     * @param SettingsRepository                  $settings Override/default store.
     * @param (callable(string, int): string)|null $fetcher  Network fetcher;
     *        defaults to a `file_get_contents` implementation. Injectable for
     *        deterministic, offline tests.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        ?callable $fetcher = null,
    ) {
        $this->fetcher = $fetcher ?? self::defaultFetcher();
    }

    /**
     * The immutable default catalog source URL.
     *
     * @since 0.33.0
     */
    public function defaultSource(): string
    {
        $value = $this->settings->getEffective(self::KEY_DEFAULT_SOURCE);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return self::FALLBACK_DEFAULT_SOURCE;
    }

    /**
     * Whether plugins should be auto-updated by the background worker.
     *
     * @since 0.39.0
     */
    public function autoUpdateEnabled(): bool
    {
        return $this->settings->getEffective(self::KEY_AUTO_UPDATE) === true;
    }

    /**
     * Persist the auto-update toggle.
     *
     * @since 0.39.0
     */
    public function setAutoUpdate(bool $enabled): void
    {
        $this->settings->set(self::KEY_AUTO_UPDATE, $enabled, 'bool');
    }

    /**
     * Operator-added extra catalog sources (excludes the default).
     *
     * @return list<string>
     *
     * @since 0.33.0
     */
    public function extraSources(): array
    {
        $value = $this->settings->getEffective(self::KEY_SOURCES);
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $out[] = trim($entry);
            }
        }
        return $out;
    }

    /**
     * All catalog sources: the default first, then extras, de-duplicated.
     *
     * @return list<string>
     *
     * @since 0.33.0
     */
    public function sources(): array
    {
        $sources = [$this->defaultSource()];
        foreach ($this->extraSources() as $extra) {
            if (!in_array($extra, $sources, true)) {
                $sources[] = $extra;
            }
        }
        return $sources;
    }

    /**
     * Add an extra catalog source.
     *
     * Validates that the URL is a non-empty `http(s)` URL, normalises it, and
     * — unless it duplicates the default or an existing extra — persists it.
     *
     * @param string $url The catalog repository or `plugins.json` URL.
     *
     * @return list<string> The full source list after the add.
     *
     * @throws \InvalidArgumentException When the URL is empty or not http(s).
     *
     * @since 0.33.0
     */
    public function addSource(string $url): array
    {
        $clean = self::requireHttpUrl($url);
        // Compare CANONICAL fetch URLs so trailing-slash / .git / ref variants of
        // the same repo are recognised (github.com/detain/phlix-plugins,
        // …/phlix-plugins/, …/phlix-plugins.git all normalise to one raw URL).
        $normalized = CatalogSourceResolver::normalize($clean);

        // Adding the built-in default (the exact "silent no-op" users hit) — tell
        // them clearly instead of returning 200 with no visible change.
        if ($normalized === CatalogSourceResolver::normalize($this->defaultSource())) {
            throw new \InvalidArgumentException(
                'That is the built-in default catalog — it is always available and does not need to be added.',
                409,
            );
        }

        $extras = $this->extraSources();
        foreach ($extras as $existing) {
            if (CatalogSourceResolver::normalize($existing) === $normalized) {
                throw new \InvalidArgumentException('That catalog is already in your list.', 409);
            }
        }

        $extras[] = $clean;
        $this->settings->set(self::KEY_SOURCES, array_values($extras), 'json');

        return $this->sources();
    }

    /**
     * Remove an extra catalog source. The default source cannot be removed
     * (the call is a no-op for it).
     *
     * @param string $url The source URL to remove.
     *
     * @return list<string> The full source list after the remove.
     *
     * @since 0.33.0
     */
    public function removeSource(string $url): array
    {
        $clean = trim($url);
        $extras = array_values(array_filter(
            $this->extraSources(),
            static fn (string $s): bool => $s !== $clean,
        ));
        $this->settings->set(self::KEY_SOURCES, $extras, 'json');

        return $this->sources();
    }

    /**
     * Fetch and parse a single catalog.
     *
     * @param string $source The catalog repository or `plugins.json` URL.
     *
     * @return array{source: string, name: string, plugins: list<CatalogEntry>}
     *
     * @throws CatalogFetchException On transport failure, non-JSON body, or a
     *         document missing a `plugins` array.
     *
     * @since 0.33.0
     */
    public function fetchCatalog(string $source): array
    {
        $source = trim($source);
        if ($source === '') {
            throw new CatalogFetchException($source, 'Catalog source URL is empty.');
        }

        $url = CatalogSourceResolver::normalize($source);

        // SSRF guard at fetch time on the resolved raw URL: the normalizer can
        // rewrite a repo URL into a different host, so re-validate before the
        // server-side fetch. Catalog fetch runs in the admin Plugins section,
        // off the media-serving hot path.
        try {
            SsrfGuard::assertPublicUrl($url);
        } catch (\InvalidArgumentException $e) {
            throw new CatalogFetchException($source, 'Catalog URL is not allowed: ' . $e->getMessage());
        }

        try {
            $body = ($this->fetcher)($url, $this->timeout());
        } catch (\Throwable $e) {
            throw new CatalogFetchException($source, 'Could not fetch catalog: ' . $e->getMessage());
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new CatalogFetchException($source, 'Catalog response was not valid JSON.');
        }

        if (!isset($decoded['plugins']) || !is_array($decoded['plugins'])) {
            throw new CatalogFetchException($source, 'Catalog JSON is missing a "plugins" array.');
        }

        $name = isset($decoded['name']) && is_string($decoded['name']) && trim($decoded['name']) !== ''
            ? trim($decoded['name'])
            : $source;

        $plugins = [];
        foreach ($decoded['plugins'] as $raw) {
            $entry = CatalogEntry::fromArray($raw);
            if ($entry !== null) {
                $plugins[] = $entry;
            }
        }

        return ['source' => $source, 'name' => $name, 'plugins' => $plugins];
    }

    /**
     * Fetch every configured catalog, returning the successful catalogs and a
     * per-source error list (so the caller can render both).
     *
     * @return array{
     *     sources: list<string>,
     *     catalogs: list<array{source: string, name: string, plugins: list<CatalogEntry>}>,
     *     errors: list<array{source: string, error: string}>
     * }
     *
     * @since 0.33.0
     */
    public function aggregate(): array
    {
        $sources = $this->sources();
        $catalogs = [];
        $errors = [];

        foreach ($sources as $source) {
            try {
                $catalogs[] = $this->fetchCatalog($source);
            } catch (CatalogFetchException $e) {
                $errors[] = ['source' => $source, 'error' => $e->getMessage()];
            }
        }

        return ['sources' => $sources, 'catalogs' => $catalogs, 'errors' => $errors];
    }

    /**
     * Resolve the pinned trust metadata (`ref` + `artifactSha256`) for a
     * catalog entry, looked up by its `repo` URL or `name` (SV-S1b/SV-S2b).
     *
     * Callers that drive a catalog install thread the returned pin into
     * {@see PluginLoader::install()} so the installer downloads the pinned
     * commit and verifies the artifact digest. Returns `[null, null]` when the
     * identifier is not found in any catalog or the matched entry is un-pinned
     * (schemaVersion 1) — which leaves the install on the default-deny path.
     *
     * @param string $repoOrName A catalog entry `repo` URL or manifest `name`.
     *
     * @return array{0: ?string, 1: ?string} `[artifactSha256, ref]` or `[null, null]`.
     *
     * @since 0.40.0
     */
    public function pinFor(string $repoOrName): array
    {
        $needle = trim($repoOrName);
        if ($needle === '') {
            return [null, null];
        }

        foreach ($this->aggregate()['catalogs'] as $catalog) {
            foreach ($catalog['plugins'] as $entry) {
                if (($entry->repo === $needle || $entry->name === $needle) && $entry->verified()) {
                    return [$entry->artifactSha256, $entry->ref];
                }
            }
        }

        return [null, null];
    }

    /**
     * The configured per-fetch timeout in seconds (≥1).
     */
    private function timeout(): int
    {
        $value = $this->settings->getEffective(self::KEY_TIMEOUT);
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }
        return self::DEFAULT_TIMEOUT;
    }

    /**
     * Validate + trim a catalog source URL, requiring an `http`/`https`
     * scheme (catalogs are network documents).
     *
     * @throws \InvalidArgumentException
     */
    private static function requireHttpUrl(string $url): string
    {
        $clean = trim($url);
        if ($clean === '') {
            throw new \InvalidArgumentException('A catalog URL is required.');
        }

        $scheme = strtolower((string) (parse_url($clean, PHP_URL_SCHEME) ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('Catalog URL must be an http:// or https:// URL.');
        }

        // SSRF guard at config time: reject loopback/link-local/private/metadata
        // hosts. Admin "add source" action, off the media hot path.
        SsrfGuard::assertPublicUrl($clean);

        return $clean;
    }

    /**
     * Build the default catalog fetcher.
     *
     * The GET is issued with **cURL** rather than `file_get_contents` + a
     * `stream_context_create()` SSL/HTTP context. Under Swoole's coroutine
     * runtime hooks the custom stream-context path marshals through the
     * `Swoole\RemoteObject` bridge and throws `Swoole\RemoteObject\Exception`
     * (`@swoole/library/core/RemoteObject/Client.php`), which crashes the
     * catalog fetch — including the always-present default catalog, blanking
     * the Plugins listings. cURL is coroutine-safe under
     * `SWOOLE_HOOK_NATIVE_CURL` (in the runtime allowlist) and remains correct
     * in plain CLI, so it is the primary path.
     *
     * Sends a Phlix User-Agent + `Accept: application/json`, follows up to 3
     * redirects (restricted to http/https so a redirect cannot downgrade to
     * `file://` etc.), and verifies TLS. When ext-curl is unavailable the
     * fetcher falls back to the previous stream-context implementation so
     * curl-less environments still work.
     *
     * @return callable(string, int): string
     */
    public static function defaultFetcher(): callable
    {
        return static function (string $url, int $timeout): string {
            if (function_exists('curl_init')) {
                return self::curlFetch($url, $timeout);
            }
            return self::streamFetch($url, $timeout);
        };
    }

    /**
     * Synchronous HTTP GET using native cURL. Follows ≤3 http/https redirects, verifies TLS.
     *
     * Uses native PHP cURL for synchronous catalog fetching. Under Swoole's
     * coroutine runtime, native curl is safe via `SWOOLE_HOOK_NATIVE_CURL`.
     *
     * @throws \RuntimeException On transport failure or an HTTP status ≥ 400.
     */
    private static function curlFetch(string $url, int $timeout): string
    {
        if ($url === '') {
            throw new \RuntimeException('URL cannot be empty');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Phlix-PluginCatalog',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('request failed or timed out: ' . ($curlError ?: 'Unknown error'));
        }

        if ($status >= 400) {
            throw new \RuntimeException('HTTP ' . $status);
        }

        return is_string($body) ? $body : '';
    }

    /**
     * Fallback `file_get_contents` + stream-context GET, used only when
     * ext-curl is unavailable. Follows ≤3 redirects and verifies TLS.
     *
     * @throws \RuntimeException On transport failure or an HTTP status ≥ 400.
     */
    private static function streamFetch(string $url, int $timeout): string
    {
        $headers = "User-Agent: Phlix-PluginCatalog\r\nAccept: application/json\r\n";
        $http = [
            'timeout'        => $timeout,
            'follow_location' => 1,
            'max_redirects'  => 3,
            'header'         => $headers,
            'ignore_errors'  => false,
        ];
        $context = stream_context_create([
            'http'  => $http,
            'https' => $http,
            'ssl'   => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            // `$http_response_header` is populated by the HTTP stream
            // wrapper only when a response was received; read it via
            // get_defined_vars() so it is absent (not assumed-set) when
            // the failure was at the transport layer.
            $status = self::lastHttpStatus(get_defined_vars()['http_response_header'] ?? null);
            throw new \RuntimeException(
                $status !== null ? 'HTTP ' . $status : 'request failed or timed out',
            );
        }
        return $body;
    }

    /**
     * Extract the numeric status from a `$http_response_header` array, if any.
     *
     * @param mixed $headers The magic `$http_response_header` local.
     */
    private static function lastHttpStatus(mixed $headers): ?int
    {
        if (!is_array($headers)) {
            return null;
        }
        foreach ($headers as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        return null;
    }
}
