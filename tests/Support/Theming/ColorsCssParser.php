<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Support\Theming;

/**
 * Parses the REAL `@phlix/tokens/src/css/colors.css` into per-theme token maps
 * with every `var()` chain resolved to its literal value.
 *
 * S228. This class exists so the built-in theme transcription
 * ({@see \Phlix\Theming\BuiltInThemes}) and the token allowlist
 * ({@see \Phlix\Theming\ThemeTokenAllowlist}) can be diffed against the shipped
 * stylesheet itself instead of against a second hand transcription kept inside
 * a test file. The package is delivered into `vendor/` by a dev-only,
 * commit-SHA-pinned composer dist entry (see the `repositories` block in
 * composer.json); a hex edit to either side therefore reddens a test on CI.
 *
 * Parsing is deliberately strict — it fails loudly on anything it cannot
 * prove: a missing theme block, a duplicate declaration, a dangling `var()`
 * reference, a reference cycle, or a composite `var()` (the file today uses
 * only whole-value references; the day it does not, this parser says so rather
 * than resolving a partial value to a wrong literal).
 */
final class ColorsCssParser
{
    /** The three theme ids, in colors.css declaration order. */
    public const THEME_IDS = ['nocturne', 'daylight', 'midnight'];

    /**
     * Raw (unresolved) declarations per theme, keyed in declaration order.
     * `color-scheme` is held separately — see {@see self::$colorSchemes}.
     *
     * @var array<string, array<string, string>>
     */
    private array $themeDeclarations = [];

    /**
     * `color-scheme` value per theme id (real CSS property, not a custom one).
     *
     * @var array<string, string>
     */
    private array $colorSchemes = [];

    /**
     * The standalone `:root` block's declarations — the theme-invariant
     * `--amber-*` ramp and `--accent-contrast` that theme blocks reference
     * through `var()`.
     *
     * @var array<string, string>
     */
    private array $rootDeclarations = [];

    /**
     * Parse the stylesheet from a file path.
     *
     * @throws \RuntimeException When the file is unreadable or unparsable.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                "colors.css is not present at {$path}. The S228 parity guard reads the REAL stylesheet "
                . 'shipped by the dev-only composer require `detain/phlix-tokens` — run `composer install`. '
                . 'If it is still absent, the pin in composer.json/composer.lock is broken; fix the pin, '
                . 'do not skip the guard.'
            );
        }

        $css = file_get_contents($path);
        if ($css === false) {
            throw new \RuntimeException("colors.css at {$path} could not be read.");
        }

        return self::fromCss($css, $path);
    }

    /**
     * Parse the stylesheet from a raw CSS string.
     *
     * @param string $css    The file contents.
     * @param string $origin Human-readable origin for error messages.
     *
     * @throws \RuntimeException On any shape the parser cannot prove correct.
     */
    public static function fromCss(string $css, string $origin = '<string>'): self
    {
        $parser = new self();
        $parser->parse($css, $origin);

        return $parser;
    }

    /**
     * Theme ids found, asserted to be exactly the three built-ins.
     *
     * @return list<string>
     */
    public function themeIds(): array
    {
        return array_keys($this->themeDeclarations);
    }

    /**
     * The custom properties declared by one theme block, in file order.
     *
     * @return list<string>
     */
    public function tokenOrder(string $themeId): array
    {
        $this->requireTheme($themeId);

        return array_keys($this->themeDeclarations[$themeId]);
    }

    /**
     * The block's `color-scheme` value (`dark` or `light`).
     */
    public function colorScheme(string $themeId): string
    {
        $this->requireTheme($themeId);

        if (!isset($this->colorSchemes[$themeId])) {
            throw new \RuntimeException("Theme block \"{$themeId}\" in {$this->origin()} declares no color-scheme.");
        }

        return $this->colorSchemes[$themeId];
    }

    /**
     * Every custom property of one theme block, with `var()` chains resolved
     * to literals, in declaration order.
     *
     * Resolution looks the referenced name up in the theme block first, then
     * in the invariant `:root` block — the same precedence a browser applies
     * for `[data-theme='...']` scopes inheriting from `:root`.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException On a dangling reference, a cycle, or a
     *         composite `var()` the strict whole-value contract does not allow.
     */
    public function resolvedTokens(string $themeId): array
    {
        $this->requireTheme($themeId);

        $resolved = [];
        foreach ($this->themeDeclarations[$themeId] as $token => $raw) {
            $resolved[$token] = $this->resolve($themeId, $token, $raw, []);
        }

        return $resolved;
    }

    /**
     * Resolve one value; `$visiting` carries the reference path for cycle
     * detection. Recursion terminates because every chain in the file is a
     * DAG of whole-value references, and any cycle throws.
     *
     * @param array<string, string> $visiting token => true, on the current path
     */
    private function resolve(string $themeId, string $token, string $value, array $visiting): string
    {
        if (!str_contains($value, 'var(')) {
            return $value;
        }

        if (!preg_match('/^var\(\s*(--[a-zA-Z0-9-]+)\s*\)$/', $value, $m)) {
            throw new \RuntimeException(
                "Token \"{$token}\" of theme \"{$themeId}\" uses a composite var() value (\"{$value}\"). "
                . 'The parity parser only resolves whole-value references because only whole-value references '
                . 'can be flattened to a literal the way BuiltInThemes does. Resolve it upstream in colors.css '
                . 'or extend BuiltInThemes and this parser together — never silently.'
            );
        }

        $target = $m[1];
        if (isset($visiting[$token])) {
            throw new \RuntimeException(
                "Reference cycle at \"{$token}\" -> var({$target}) in theme \"{$themeId}\"."
            );
        }

        $next = $this->themeDeclarations[$themeId][$target]
            ?? $this->rootDeclarations[$target]
            ?? null;

        if ($next === null) {
            throw new \RuntimeException(
                "Token \"{$token}\" of theme \"{$themeId}\" references var({$target}), which is declared "
                . 'neither in that theme block nor in the invariant :root block.'
            );
        }

        $visiting[$token] = true;

        return $this->resolve($themeId, $target, $next, $visiting);
    }

    private function requireTheme(string $themeId): void
    {
        if (!isset($this->themeDeclarations[$themeId])) {
            throw new \RuntimeException(
                "No [data-theme='{$themeId}'] block found. Themes present: "
                . implode(', ', array_keys($this->themeDeclarations)) . '.'
            );
        }
    }

    /**
     * @throws \RuntimeException On structural surprises (see class docblock).
     */
    private function parse(string $css, string $origin): void
    {
        $this->originPath = $origin;

        $stripped = preg_replace('#/\*.*?\*/#s', '', $css);
        if ($stripped === null) {
            throw new \RuntimeException("Could not strip comments from {$origin}.");
        }

        if (!preg_match_all('#([^{}]*)\{([^{}]*)\}#s', $stripped, $blocks, PREG_SET_ORDER)) {
            throw new \RuntimeException(
                "No CSS blocks found in {$origin} at all — refusing to parse an empty rule set."
            );
        }

        foreach ($blocks as $block) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $block[1]));
            if ($selector === '') {
                continue; // leading text before the first rule (already comment-stripped)
            }

            if (preg_match("#\[data-theme\s*=\s*[\"']([a-z0-9-]+)[\"']#", $selector, $tm)) {
                $this->ingestThemeBlock($tm[1], $block[2], $origin);
                continue;
            }

            if ($selector === ':root') {
                $this->ingestRootBlock($block[2], $origin);
            }
            // Any other selector (element rules, media queries) carries no
            // custom-property definitions the parser is asked to know about.
        }

        $missing = array_diff(self::THEME_IDS, array_keys($this->themeDeclarations));
        if ($missing !== []) {
            throw new \RuntimeException(
                "Missing theme block(s): " . implode(', ', $missing) . " in {$origin}."
            );
        }

        if ($this->rootDeclarations === []) {
            throw new \RuntimeException(
                "The invariant :root block (--amber-* ramp, --accent-contrast) was not found in {$origin}; "
                . 'theme blocks resolve var() references through it, so its absence invalidates the parse.'
            );
        }
    }

    private function ingestThemeBlock(string $themeId, string $body, string $origin): void
    {
        if (isset($this->themeDeclarations[$themeId])) {
            throw new \RuntimeException("Duplicate [data-theme='{$themeId}'] block in {$origin}.");
        }

        foreach ($this->declarations($body, $origin, "[data-theme='{$themeId}']") as $prop => $value) {
            if ($prop === 'color-scheme') {
                $this->colorSchemes[$themeId] = $value;
                continue;
            }

            $this->themeDeclarations[$themeId][$prop] = $value;
        }
    }

    private function ingestRootBlock(string $body, string $origin): void
    {
        foreach ($this->declarations($body, $origin, ':root') as $prop => $value) {
            if ($prop === 'color-scheme') {
                continue; // the invariant block carries none today; harmless if one appears
            }

            $this->rootDeclarations[$prop] = $value;
        }
    }

    /**
     * Split a rule body into `prop => value`, in file order, strictly.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException On a malformed or duplicated declaration.
     */
    private function declarations(string $body, string $origin, string $where): array
    {
        $out = [];

        foreach (explode(';', $body) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue; // trailing semicolon (or multi-decl spacing) — normal
            }

            if (!preg_match('/^(--[a-zA-Z0-9-]+|color-scheme)\s*:\s*(.+)$/s', $chunk, $d)) {
                throw new \RuntimeException(
                    "Unparsable declaration \"{$chunk}\" inside {$where} of {$origin}."
                );
            }

            $prop = $d[1];
            $value = trim($d[2]);

            if (isset($out[$prop])) {
                throw new \RuntimeException("{$prop} declared twice inside {$where} of {$origin}.");
            }

            $out[$prop] = $value;
        }

        return $out;
    }

    private function origin(): string
    {
        return $this->originPath;
    }

    /** File origin kept for messages; empty when parsed from a string. */
    private string $originPath = '';

    /**
     * @internal Use {@see self::fromFile()} / {@see self::fromCss()}.
     */
    private function __construct()
    {
    }
}
