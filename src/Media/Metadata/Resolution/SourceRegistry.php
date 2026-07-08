<?php

/**
 * Phlix media server component: Resolution.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

use Phlix\Shared\Metadata\MetadataSourceInterface;

/**
 * Process-scoped registry of plugin-provided metadata sources.
 *
 * ## Role
 *
 * This registry holds the {@see MetadataSourceInterface} instances contributed
 * by **plugins** (e.g. `phlix-plugin-anidb`, `phlix-plugin-myanimelist`). It is
 * the first-class replacement for the brittle, per-plugin
 * `method_exists()` / FQCN-sniffing convention those plugins used to register
 * themselves against the host {@see \Phlix\Media\Metadata\MetadataManager}:
 *
 *  - the host {@see \Phlix\Plugins\PluginLoader} now {@see register()}s any
 *    enabled plugin entry instance that implements the shared typed contract
 *    {@see MetadataSourceInterface}, and
 *  - {@see deregister()}s it on plugin-disable.
 *
 * The built-in providers (`tmdb`, `imdb`, `tvdb`, `fanart`, `local`, …) keep
 * using the server-private {@see \Phlix\Media\Metadata\MetadataProviderInterface}
 * and {@see \Phlix\Media\Metadata\MetadataManager}; only PLUGIN sources flow
 * through this registry.
 *
 * ## Resident-memory lifecycle (no leak)
 *
 * Phlix runs as a resident-memory Workerman process, so an unbounded, never-
 * cleared map would be a memory leak. The contract here is strict:
 *
 *  - {@see register()} is keyed by {@see MetadataSourceInterface::sourceName()}
 *    and is **idempotent** — re-registering the same source name replaces the
 *    prior instance (it never grows the map).
 *  - {@see deregister()} (by name or instance) **actually removes** the entry,
 *    so an enable → disable cycle leaves the registry exactly as it started.
 *    The {@see PluginLoaderSourceRegistryTest}/{@see SourceRegistryTest} prove
 *    `count(all()) == 0` after a symmetric enable/disable.
 *
 * The registry is a single container-scoped instance (wired in
 * {@see \Phlix\Common\Container\Providers\MediaServicesProvider}); it is NOT a
 * global static singleton and carries no per-request state — it only mirrors
 * the set of currently-enabled metadata-source plugins.
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since 0.15.0
 */
final class SourceRegistry
{
    /**
     * Currently-registered sources keyed by canonical source name.
     *
     * @var array<string, MetadataSourceInterface>
     */
    private array $sources = [];

    /**
     * Register (or replace) a metadata source.
     *
     * Keyed by {@see MetadataSourceInterface::sourceName()}. Re-registering a
     * source whose name is already present **replaces** the existing instance
     * rather than appending — the map never grows unbounded, which keeps the
     * resident worker leak-free across repeated enable cycles.
     *
     * @param MetadataSourceInterface $source The source contributed by a plugin.
     * @return void
     *
     * @since 0.15.0
     */
    public function register(MetadataSourceInterface $source): void
    {
        $this->sources[$source->sourceName()] = $source;
    }

    /**
     * Deregister a source by its canonical name.
     *
     * Truly removes the entry (no tombstone, no growth) so a plugin
     * enable → disable cycle leaves the registry as it was before enable.
     * A no-op when the name is not registered.
     *
     * @param string $sourceName Canonical source name (e.g. `anidb`).
     * @return void
     *
     * @since 0.15.0
     */
    public function deregister(string $sourceName): void
    {
        unset($this->sources[$sourceName]);
    }

    /**
     * Deregister a source by instance.
     *
     * Convenience for the plugin-disable path, which holds the live source
     * instance. Delegates to {@see deregister()} using the instance's
     * {@see MetadataSourceInterface::sourceName()}.
     *
     * @param MetadataSourceInterface $source The source instance to remove.
     * @return void
     *
     * @since 0.15.0
     */
    public function deregisterInstance(MetadataSourceInterface $source): void
    {
        $this->deregister($source->sourceName());
    }

    /**
     * Whether a source with the given name is registered.
     *
     * @param string $sourceName Canonical source name.
     * @return bool
     *
     * @since 0.15.0
     */
    public function has(string $sourceName): bool
    {
        return isset($this->sources[$sourceName]);
    }

    /**
     * Fetch a registered source by name.
     *
     * @param string $sourceName Canonical source name.
     * @return MetadataSourceInterface|null The source, or null when not registered.
     *
     * @since 0.15.0
     */
    public function get(string $sourceName): ?MetadataSourceInterface
    {
        return $this->sources[$sourceName] ?? null;
    }

    /**
     * All registered sources keyed by canonical source name.
     *
     * @return array<string, MetadataSourceInterface>
     *
     * @since 0.15.0
     */
    public function all(): array
    {
        return $this->sources;
    }

    /**
     * The canonical names of all registered sources.
     *
     * @return list<string>
     *
     * @since 0.15.0
     */
    public function names(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Registered sources that declare support for the given media type.
     *
     * Filters {@see all()} by {@see MetadataSourceInterface::supportedMediaTypes()}.
     * The returned map preserves registration order; callers that want a
     * specific priority order should order the names themselves (e.g. via the
     * admin `metadata.provider_priority` list).
     *
     * @param string $mediaType Host media-type slug (e.g. `anime`, `series`).
     * @return array<string, MetadataSourceInterface> Matching sources keyed by name.
     *
     * @since 0.15.0
     */
    public function forMediaType(string $mediaType): array
    {
        $matches = [];
        foreach ($this->sources as $name => $source) {
            if (in_array($mediaType, $source->supportedMediaTypes(), true)) {
                $matches[$name] = $source;
            }
        }

        return $matches;
    }
}
