<?php

/**
 * Phlix media server component: Theming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Theming;

use Phlix\Theming\Exception\InvalidThemeDefinition;

/**
 * Validates a plugin-supplied theme payload into a {@see TokenTheme}.
 *
 * ## Threat model
 *
 * A plugin-supplied token value ends up as the value half of a CSS custom
 * property (`el.style.setProperty(key, value)` in the SPA, per S86). Anything
 * that escapes the value position can inject arbitrary CSS — an exfiltrating
 * `url(...)`, an extra declaration after a `;`, or a `}` that closes the rule
 * early and starts a new selector. So both halves of every entry are checked:
 *
 *  - **Key** — exact, case-sensitive membership in
 *    {@see ThemeTokenAllowlist}. Nothing else is settable.
 *  - **Value** — must match ONE of four narrow grammars in full
 *    (hex colour, `rgb()/rgba()/hsl()/hsla()` with numeric arguments only,
 *    a bare number, or the keywords `transparent` / `currentColor`).
 *
 * ## Why an allowlist rather than a denylist of `url(` / `expression` / `;`
 *
 * A denylist has to enumerate every spelling of an attack, and CSS gives an
 * attacker many: case (`URL(`), CSS identifier escapes (`\75 rl(`), comment
 * splitting (`u/**\/rl(`), newline splitting, unicode escapes. A grammar that
 * accepts only `#rrggbb`, `rgba(1, 2, 3, 0.4)`, `0.05`, `transparent` and
 * `currentColor` rejects **all** of those by simply not matching — including
 * the ones nobody thought of. There is no substring blocklist in this class,
 * on purpose: nothing here can be bypassed by finding a new spelling.
 *
 * Three supporting details:
 *
 *  - **Full-string anchoring is what does the work.** Every pattern runs from
 *    `^` to the end, so a value is accepted only if ALL of it parses. Drop the
 *    anchors and `#fff}body{background:url(...)` matches on its `#fff` prefix.
 *  - The end anchor is `\z`, not `$`. PCRE's `$` also matches immediately
 *    before a trailing newline, which would make `"#ffffff\n"` a match. With
 *    the trim below that is belt-and-braces rather than a hole being closed —
 *    the point is that the grammar stays correct on its own terms even if the
 *    trim is ever removed. It is NOT what stops newline splitting; a payload
 *    like `"#fff\n;color:red"` is refused because the grammar has no
 *    production for `;` at all, anchors or no anchors.
 *  - Interior whitespace is `[ ]` (literal space), not `\s`, so an accepted
 *    value can never carry a newline, tab or form feed between function
 *    arguments.
 *
 * Values are trimmed before matching and the TRIMMED form is what gets
 * stored, so surrounding whitespace is normalised away rather than preserved.
 *
 * All methods are static and the class holds no state: it is safe to call
 * from a resident-memory (Workerman) worker with no per-request storage.
 *
 * @package Phlix\Theming
 * @since 0.44.0
 */
final class ThemeTokenValidator
{
    /**
     * The complete set of payload keys. Anything else is a typo or an
     * attempt to smuggle an unhandled field, and is rejected.
     *
     * @var list<string>
     */
    public const PAYLOAD_KEYS = ['id', 'name', 'dark', 'extends', 'tokens'];

    /**
     * Theme ids the built-in Nocturne token set owns. A plugin may not claim
     * one — doing so would silently replace a shipped theme for every user.
     *
     * @var list<string>
     */
    public const RESERVED_IDS = ['nocturne', 'daylight', 'midnight'];

    /**
     * Theme / base-theme identifier: a lowercase ASCII slug.
     */
    private const ID_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?\z/';

    /**
     * Longest accepted human-readable theme name.
     */
    private const MAX_NAME_LENGTH = 64;

    /**
     * Longest accepted token value. Every legitimate value is far shorter
     * (`rgba(255, 255, 255, 0.55)` is 25 characters); the cap bounds the work
     * the value patterns can be asked to do.
     */
    private const MAX_VALUE_LENGTH = 128;

    /**
     * `#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`.
     */
    private const HEX_PATTERN =
        '/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{4}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})\z/';

    /**
     * A bare number: `0`, `1`, `0.05`, `.5`, `-0.2`. Used by
     * `--grain-opacity`, and harmless anywhere else (an out-of-grammar
     * number simply makes the property invalid at parse time in the browser).
     */
    private const NUMBER_PATTERN = '/^[+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)\z/';

    /**
     * `rgb()` / `rgba()` / `hsl()` / `hsla()` over 3 or 4 numeric arguments
     * separated by `,` or `/`, each optionally suffixed `%` or `deg`. The
     * function name is matched case-insensitively (CSS function names are);
     * the argument grammar is not relaxed by that.
     */
    private const FUNCTION_PATTERN =
        '/^(?i:rgba?|hsla?)\((?:[ ]*)' . self::ARGUMENT
        . '(?:(?:[ ]*)[,\/](?:[ ]*)' . self::ARGUMENT . '){2,3}(?:[ ]*)\)\z/';

    /**
     * One numeric argument of a colour function.
     */
    private const ARGUMENT = '[+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:%|(?i:deg))?';

    /**
     * Bare keywords accepted as a colour value, compared lowercased.
     *
     * @var list<string>
     */
    private const KEYWORDS = ['transparent', 'currentcolor'];

    /**
     * Validate one raw theme payload.
     *
     * @param array<array-key, mixed> $payload Raw payload, shaped
     *        `{"id","name","dark"?,"extends"?,"tokens"}`.
     * @param string|null $sourceName Provenance recorded on the result and
     *        quoted in every error message; null for a host-registered theme.
     *
     * @return TokenTheme The validated, sanitised theme.
     *
     * @throws InvalidThemeDefinition On any unknown key, malformed field,
     *         non-allowlisted token name or non-conforming token value.
     *
     * @since 0.44.0
     */
    public static function validate(array $payload, ?string $sourceName = null): TokenTheme
    {
        $origin = $sourceName === null ? 'host' : "plugin source '{$sourceName}'";

        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, self::PAYLOAD_KEYS, true)) {
                throw new InvalidThemeDefinition(sprintf(
                    'Theme from %s carries an unknown payload key "%s"; allowed keys are: %s.',
                    $origin,
                    is_string($key) ? $key : (string) $key,
                    implode(', ', self::PAYLOAD_KEYS),
                ));
            }
        }

        $id = self::readId($payload['id'] ?? null, $origin);
        $name = self::readName($payload['name'] ?? null, $origin, $id);
        $dark = self::readDark($payload['dark'] ?? false, $origin, $id);
        $extends = self::readExtends($payload['extends'] ?? null, $origin, $id);
        $tokens = self::readTokens($payload['tokens'] ?? null, $origin, $id);

        return new TokenTheme($id, $name, $dark, $extends, $tokens, $sourceName);
    }

    /**
     * Whether a token VALUE conforms to the accepted grammar.
     *
     * Exposed so the boundary can be exercised directly (and mutated) by a
     * test without building a whole payload.
     *
     * @param string $value Raw, untrimmed candidate value.
     * @return bool True when the trimmed value is safe to emit into CSS.
     *
     * @since 0.44.0
     */
    public static function isSafeValue(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '' || strlen($trimmed) > self::MAX_VALUE_LENGTH) {
            return false;
        }

        if (in_array(strtolower($trimmed), self::KEYWORDS, true)) {
            return true;
        }

        return preg_match(self::HEX_PATTERN, $trimmed) === 1
            || preg_match(self::FUNCTION_PATTERN, $trimmed) === 1
            || preg_match(self::NUMBER_PATTERN, $trimmed) === 1;
    }

    /**
     * `id`: required, a lowercase slug, and not one of the reserved built-in
     * theme ids.
     *
     * @param mixed $raw The `id` field as supplied.
     *
     * @throws InvalidThemeDefinition
     */
    private static function readId(mixed $raw, string $origin): string
    {
        if (!is_string($raw) || preg_match(self::ID_PATTERN, $raw) !== 1) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme from %s has an invalid "id"; expected a lowercase slug matching %s.',
                $origin,
                self::ID_PATTERN,
            ));
        }

        if (in_array($raw, self::RESERVED_IDS, true)) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme from %s claims the reserved built-in theme id "%s"; reserved ids are: %s.',
                $origin,
                $raw,
                implode(', ', self::RESERVED_IDS),
            ));
        }

        return $raw;
    }

    /**
     * `name`: required, 1..64 characters, no control characters (it is a
     * label the SPA renders and the admin console logs).
     *
     * @param mixed $raw The `name` field as supplied.
     *
     * @throws InvalidThemeDefinition
     */
    private static function readName(mixed $raw, string $origin, string $id): string
    {
        $name = is_string($raw) ? trim($raw) : '';

        if (
            $name === ''
            || mb_strlen($name) > self::MAX_NAME_LENGTH
            || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
        ) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme "%s" from %s has an invalid "name"; expected 1..%d characters with no control characters.',
                $id,
                $origin,
                self::MAX_NAME_LENGTH,
            ));
        }

        return $name;
    }

    /**
     * `dark`: optional, strictly boolean. A truthy string or `1` is rejected
     * rather than coerced, so the light/dark signal can never be guessed
     * wrong from a sloppy manifest.
     *
     * @param mixed $raw The `dark` field as supplied (defaulted to false).
     *
     * @throws InvalidThemeDefinition
     */
    private static function readDark(mixed $raw, string $origin, string $id): bool
    {
        if (!is_bool($raw)) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme "%s" from %s has a non-boolean "dark" (%s); use true or false.',
                $id,
                $origin,
                get_debug_type($raw),
            ));
        }

        return $raw;
    }

    /**
     * `extends`: optional, null or the id of a base theme, and never the
     * theme's own id (a one-node cycle).
     *
     * @param mixed $raw The `extends` field as supplied.
     *
     * @throws InvalidThemeDefinition
     */
    private static function readExtends(mixed $raw, string $origin, string $id): ?string
    {
        if ($raw === null) {
            return null;
        }

        if (!is_string($raw) || preg_match(self::ID_PATTERN, $raw) !== 1) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme "%s" from %s has an invalid "extends"; expected null or a lowercase theme-id slug.',
                $id,
                $origin,
            ));
        }

        if ($raw === $id) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme "%s" from %s extends itself.',
                $id,
                $origin,
            ));
        }

        return $raw;
    }

    /**
     * `tokens`: required map of allowlisted custom-property name to a value
     * conforming to the accepted grammar. May be empty for a theme that only
     * re-labels the base it extends.
     *
     * @param mixed $raw The `tokens` field as supplied.
     *
     * @return array<string, string> Sanitised (trimmed) token map.
     *
     * @throws InvalidThemeDefinition
     */
    private static function readTokens(mixed $raw, string $origin, string $id): array
    {
        if (!is_array($raw)) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme "%s" from %s has a non-array "tokens" (%s).',
                $id,
                $origin,
                get_debug_type($raw),
            ));
        }

        $tokens = [];

        /** @var mixed $value */
        foreach ($raw as $key => $value) {
            if (!is_string($key) || !ThemeTokenAllowlist::allows($key)) {
                throw new InvalidThemeDefinition(sprintf(
                    'Theme "%s" from %s sets the non-allowlisted token "%s". '
                    . 'Only the %d semantic tokens of @phlix/tokens colors.css may be set.',
                    $id,
                    $origin,
                    is_string($key) ? $key : (string) $key,
                    count(ThemeTokenAllowlist::all()),
                ));
            }

            if (!is_string($value) || !self::isSafeValue($value)) {
                throw new InvalidThemeDefinition(sprintf(
                    'Theme "%s" from %s sets token "%s" to a value that is not a plain colour or number. '
                    . 'Accepted: #hex, rgb()/rgba()/hsl()/hsla() with numeric arguments, a bare number, '
                    . 'transparent, currentColor.',
                    $id,
                    $origin,
                    $key,
                ));
            }

            $tokens[$key] = trim($value);
        }

        return $tokens;
    }
}
