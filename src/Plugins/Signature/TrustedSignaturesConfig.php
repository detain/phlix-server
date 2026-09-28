<?php

/**
 * Phlix media server component: Signature.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Signature;

use InvalidArgumentException;
use RuntimeException;

/**
 * Parses the operator-configured trusted-signature allowlist fed to
 * {@see SignatureVerifier}.
 *
 * Before this class existed the container wiring passed a hardcoded
 * empty array into the verifier, making its allowlist arm dead code:
 * signed-but-unknown digests were optimistically accepted whenever
 * `PHLIX_PLUGINS_REQUIRE_SIGNATURE` was off (the shipped default).
 * The allowlist is now operator-configurable through two env seams:
 *
 *  - `PHLIX_PLUGINS_TRUSTED_SIGNATURES`      — comma-separated list of
 *    `sha256:<64-hex>` digests; surrounding whitespace is ignored and
 *    empty segments (stray commas) are dropped.
 *  - `PHLIX_PLUGINS_TRUSTED_SIGNATURES_FILE` — path to a JSON file
 *    holding an array of the same `sha256:<64-hex>` strings.
 *
 * Both sources merge (deduplicated, env-list first). An entry that is
 * not a well-formed digest throws — a typo in a SECURITY allowlist must
 * halt the boot, not silently drop a trusted key. The lowercase
 * `sha256:` prefix is required exactly as the manifest JSON Schema
 * emits it; the hex digits are case-insensitive because the verifier
 * digest-compares case-normalized.
 *
 * Neither seam set -> empty allowlist -> the verifier keeps its
 * documented legacy behavior (optimistic content-match accept while
 * `PHLIX_PLUGINS_REQUIRE_SIGNATURE` is false).
 *
 * @package Phlix\Plugins\Signature
 * @since 0.75.0
 */
final class TrustedSignaturesConfig
{
    public const ENV_LIST = 'PHLIX_PLUGINS_TRUSTED_SIGNATURES';

    public const ENV_FILE = 'PHLIX_PLUGINS_TRUSTED_SIGNATURES_FILE';

    /** Canonical manifest form: lowercase `sha256:` prefix, 64 hex digits. */
    private const ENTRY_PATTERN = '/^sha256:[0-9a-fA-F]{64}$/';

    /**
     * Read and parse both env seams.
     *
     * @return list<string> merged, deduplicated allowlist entries
     *
     * @throws InvalidArgumentException when an entry is malformed
     * @throws RuntimeException         when the JSON file seam is unreadable
     *                                  or does not hold a JSON string array
     */
    public static function fromEnv(): array
    {
        return self::deduplicate([
            ...self::parseList(getenv(self::ENV_LIST)),
            ...self::parseFile(getenv(self::ENV_FILE)),
        ]);
    }

    /**
     * @param string|false $raw comma-separated entries, or nothing
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function parseList(string|false $raw): array
    {
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $entries = [];
        foreach (explode(',', $raw) as $segment) {
            $entry = trim($segment);
            if ($entry === '') {
                continue;
            }
            self::assertValidEntry($entry, self::ENV_LIST);
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param string|false $raw path to a JSON array of entries, or nothing
     *
     * @return list<string>
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public static function parseFile(string|false $raw): array
    {
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $path = trim($raw);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(
                self::ENV_FILE . ' points at an unreadable file: ' . $path
            );
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException(
                self::ENV_FILE . ' could not be read: ' . $path
            );
        }

        return self::parseJsonList($bytes, $path);
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public static function parseJsonList(string $bytes, string $origin): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException(
                self::ENV_FILE . ' is not valid JSON (' . $origin . '): ' . $e->getMessage()
            );
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException(
                self::ENV_FILE . ' must contain a JSON array of "sha256:<64-hex>" strings (' . $origin . ').'
            );
        }

        $entries = [];
        foreach ($decoded as $index => $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    self::ENV_FILE . ' entry #' . (int) $index . ' is not a string (' . $origin . ').'
                );
            }
            self::assertValidEntry($value, $origin . '[' . (int) $index . ']');
            $entries[] = $value;
        }

        return $entries;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertValidEntry(string $entry, string $origin): void
    {
        if (preg_match(self::ENTRY_PATTERN, $entry) !== 1) {
            throw new InvalidArgumentException(
                'Malformed trusted-signature entry "' . $entry . '" from ' . $origin
                . '; expected sha256:<64-hex>.'
            );
        }
    }

    /**
     * @param list<string> $entries
     *
     * @return list<string>
     */
    private static function deduplicate(array $entries): array
    {
        return array_values(array_unique($entries));
    }
}
