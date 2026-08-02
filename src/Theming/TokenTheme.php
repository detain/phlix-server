<?php

/**
 * Phlix media server component: Theming.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Theming;

/**
 * A validated, token-map theme.
 *
 * This is the modern counterpart of {@see Theme}: instead of pointing at a
 * CSS **file** it carries the CSS custom properties themselves, already
 * checked against {@see ThemeTokenAllowlist} and
 * {@see ThemeTokenValidator}'s value grammar. That is what lets the SPA apply
 * a plugin theme with `el.style.setProperty()` instead of injecting a
 * `<style>` tag, so no `unsafe-inline` CSP relaxation is needed.
 *
 * Instances are only ever produced by {@see ThemeTokenValidator::validate()},
 * so an instance existing is itself the proof that its tokens are safe.
 *
 * @package Phlix\Theming
 * @since 0.44.0
 */
final class TokenTheme
{
    /**
     * @param string $id Stable lowercase slug, unique across the registry.
     * @param string $name Human-readable label.
     * @param bool $dark Whether the theme is dark (drives `color-scheme`).
     * @param string|null $extends Id of a base theme whose tokens this one
     *        layers over, or null when the theme is standalone.
     * @param array<string, string> $tokens Allowlisted custom property =>
     *        sanitised value.
     * @param string|null $sourceName Canonical name of the plugin theme
     *        source that contributed this theme; null when host-registered.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $dark,
        public readonly ?string $extends,
        public readonly array $tokens,
        public readonly ?string $sourceName = null,
    ) {
    }

    /**
     * Wire shape of the theme.
     *
     * @return array{
     *     id: string,
     *     name: string,
     *     dark: bool,
     *     extends: string|null,
     *     tokens: array<string, string>,
     *     source: string|null
     * }
     *
     * @since 0.44.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'dark' => $this->dark,
            'extends' => $this->extends,
            'tokens' => $this->tokens,
            'source' => $this->sourceName,
        ];
    }
}
