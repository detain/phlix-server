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
 * Process-scoped registry of validated, plugin-provided token-map themes.
 *
 * ## Role
 *
 * The theming analogue of
 * {@see \Phlix\Media\Subtitles\SubtitleSourceRegistry}. It holds the
 * {@see TokenTheme}s contributed by plugins whose entry class implements
 * {@see ThemeSourceInterface}. {@see \Phlix\Plugins\PluginLoader} calls
 * {@see register()} on plugin-enable and {@see deregisterInstance()} on
 * plugin-disable, sniff-free, exactly mirroring the metadata- and
 * subtitle-source wiring.
 *
 * ## Fail-closed, all-or-nothing registration
 *
 * {@see register()} validates EVERY payload a source offers before it commits
 * ANY of them. A source with one bad token registers nothing and throws — a
 * partially-registered theme plugin is never a reachable state, and a
 * rejection is never silent. (`PluginLoader` turns that into a failed enable.)
 *
 * ## Resident-memory lifecycle (no leak)
 *
 * Phlix runs as a resident-memory Workerman process, so both maps are keyed
 * and bounded: themes by {@see TokenTheme::$id}, provenance by
 * {@see ThemeSourceInterface::themeSourceName()}. Re-registering a source
 * REPLACES its previous contribution (drop-then-add, never append), and
 * {@see deregister()} truly removes both the themes and the provenance entry,
 * so a symmetric enable → disable cycle leaves `count(all()) === 0`.
 *
 * The registry is a single container-scoped instance (wired in
 * {@see \Phlix\Common\Container\Providers\ThemingServicesProvider}); it is NOT
 * a global static singleton and carries no per-request state.
 *
 * @package Phlix\Theming
 * @since 0.44.0
 */
final class ThemeSourceRegistry
{
    /**
     * Hard ceiling on the `extends` chain length walked by
     * {@see resolveTokens()}. A cycle is already impossible (a visited-set
     * guards it); this additionally bounds a legitimate but absurdly deep
     * chain so the walk is always O(1)-ish in a resident worker.
     */
    public const MAX_EXTENDS_DEPTH = 8;

    /**
     * Registered themes keyed by theme id.
     *
     * @var array<string, TokenTheme>
     */
    private array $themes = [];

    /**
     * Which theme ids each source contributed, keyed by canonical source
     * name. Lets {@see deregister()} remove exactly that source's themes.
     *
     * @var array<string, list<string>>
     */
    private array $idsBySource = [];

    /**
     * Register (or re-register) every theme a source provides.
     *
     * Validation happens up front and in full: nothing is written to either
     * map unless all of the source's payloads pass. Re-registering the same
     * source name first drops its previous themes, so the maps never grow
     * across repeated enable cycles.
     *
     * @param ThemeSourceInterface $source The plugin-provided theme source.
     * @return list<string> The theme ids now registered for this source.
     *
     * @throws InvalidThemeDefinition When the source name is malformed, a
     *         payload fails validation, or a theme id is already owned by a
     *         DIFFERENT source (id hijacking).
     *
     * @since 0.44.0
     */
    public function register(ThemeSourceInterface $source): array
    {
        $sourceName = $source->themeSourceName();

        if (preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?\z/', $sourceName) !== 1) {
            throw new InvalidThemeDefinition(sprintf(
                'Theme source name "%s" is not a lowercase slug.',
                $sourceName,
            ));
        }

        // A re-register replaces; free this source's own ids first so its
        // unchanged themes do not collide with themselves below.
        $previous = $this->idsBySource[$sourceName] ?? [];
        $survivors = $this->themes;
        foreach ($previous as $previousId) {
            unset($survivors[$previousId]);
        }

        /** @var array<string, TokenTheme> $staged */
        $staged = [];

        foreach ($source->providedThemes() as $payload) {
            $theme = ThemeTokenValidator::validate($payload, $sourceName);

            if (isset($survivors[$theme->id]) || isset($staged[$theme->id])) {
                throw new InvalidThemeDefinition(sprintf(
                    'Theme source "%s" registers theme id "%s", which is already registered by "%s".',
                    $sourceName,
                    $theme->id,
                    $survivors[$theme->id]->sourceName ?? $sourceName,
                ));
            }

            $staged[$theme->id] = $theme;
        }

        // Commit — only now is anything mutated.
        $this->themes = $survivors + $staged;
        $this->idsBySource[$sourceName] = array_keys($staged);

        return array_keys($staged);
    }

    /**
     * Remove every theme a source contributed, plus its provenance entry.
     *
     * A no-op when the source name is not registered.
     *
     * @param string $sourceName Canonical source name.
     * @return void
     *
     * @since 0.44.0
     */
    public function deregister(string $sourceName): void
    {
        foreach ($this->idsBySource[$sourceName] ?? [] as $id) {
            unset($this->themes[$id]);
        }

        unset($this->idsBySource[$sourceName]);
    }

    /**
     * Deregister by instance — the convenience the plugin-disable path uses,
     * mirroring {@see \Phlix\Media\Subtitles\SubtitleSourceRegistry::deregisterInstance()}.
     *
     * @param ThemeSourceInterface $source The source instance to remove.
     * @return void
     *
     * @since 0.44.0
     */
    public function deregisterInstance(ThemeSourceInterface $source): void
    {
        $this->deregister($source->themeSourceName());
    }

    /**
     * Whether a theme id is registered.
     *
     * @param string $id Theme id.
     * @return bool
     *
     * @since 0.44.0
     */
    public function has(string $id): bool
    {
        return isset($this->themes[$id]);
    }

    /**
     * Fetch a registered theme.
     *
     * @param string $id Theme id.
     * @return TokenTheme|null The theme, or null when not registered.
     *
     * @since 0.44.0
     */
    public function get(string $id): ?TokenTheme
    {
        return $this->themes[$id] ?? null;
    }

    /**
     * All registered themes keyed by theme id.
     *
     * @return array<string, TokenTheme>
     *
     * @since 0.44.0
     */
    public function all(): array
    {
        return $this->themes;
    }

    /**
     * The ids of all registered themes.
     *
     * @return list<string>
     *
     * @since 0.44.0
     */
    public function ids(): array
    {
        return array_keys($this->themes);
    }

    /**
     * The canonical names of all sources currently contributing themes.
     *
     * @return list<string>
     *
     * @since 0.44.0
     */
    public function sourceNames(): array
    {
        return array_keys($this->idsBySource);
    }

    /**
     * A theme's tokens with its `extends` chain flattened underneath it.
     *
     * Nearer themes win: the requested theme's own tokens override its base's,
     * which override its base's base, and so on. A base named by `extends`
     * that is NOT registered here (e.g. one of the SPA's built-in ids) simply
     * ends the walk — the caller layers the result over that built-in itself.
     *
     * Cycle-safe by construction: a visited set stops a `a → b → a` chain, and
     * {@see MAX_EXTENDS_DEPTH} bounds the walk regardless.
     *
     * @param string $id Theme id to resolve.
     * @return array<string, string> Flattened token map; empty when the id is
     *         not registered.
     *
     * @since 0.44.0
     */
    public function resolveTokens(string $id): array
    {
        $chain = [];
        $visited = [];
        $cursor = $id;

        for ($depth = 0; $depth < self::MAX_EXTENDS_DEPTH; $depth++) {
            if (isset($visited[$cursor])) {
                break;
            }

            $theme = $this->themes[$cursor] ?? null;
            if ($theme === null) {
                break;
            }

            $visited[$cursor] = true;
            $chain[] = $theme->tokens;

            if ($theme->extends === null) {
                break;
            }

            $cursor = $theme->extends;
        }

        $resolved = [];
        // Walk base-first so nearer themes overwrite further ones.
        foreach (array_reverse($chain) as $tokens) {
            $resolved = array_merge($resolved, $tokens);
        }

        return $resolved;
    }
}
