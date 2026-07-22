<?php

/**
 * Phlix media server component: Subtitles.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Subtitles;

use Phlix\Shared\Subtitle\SubtitleSourceInterface;

/**
 * Process-scoped registry of plugin-provided subtitle sources.
 *
 * ## Role
 *
 * The subtitle analogue of {@see \Phlix\Media\Metadata\Resolution\SourceRegistry}.
 * It holds the {@see SubtitleSourceInterface} instances contributed by
 * **plugins** (e.g. `phlix-plugin-opensubtitles`). The host
 * {@see \Phlix\Plugins\PluginLoader} {@see register()}s any enabled plugin
 * entry instance that implements the shared typed contract on plugin-enable and
 * {@see deregister()}s it on plugin-disable, sniff-free — exactly mirroring the
 * metadata-source wiring.
 *
 * ## Resident-memory lifecycle (no leak)
 *
 * Phlix runs as a resident-memory Workerman process, so the map is keyed by
 * {@see SubtitleSourceInterface::getName()} and every {@see register()} is
 * idempotent (re-registering the same name REPLACES, never grows), while
 * {@see deregister()} truly removes the entry — a symmetric enable → disable
 * cycle leaves the registry exactly as it started (`count(all()) == 0`).
 *
 * The registry is a single container-scoped instance (wired in
 * {@see \Phlix\Common\Container\Providers\MediaServicesProvider}); it is NOT a
 * global static singleton and carries no per-request state.
 *
 * @package Phlix\Media\Subtitles
 * @since 0.43.0
 */
final class SubtitleSourceRegistry
{
    /**
     * Currently-registered sources keyed by canonical source name.
     *
     * @var array<string, SubtitleSourceInterface>
     */
    private array $sources = [];

    /**
     * Register (or replace) a subtitle source.
     *
     * Keyed by {@see SubtitleSourceInterface::getName()}. Re-registering a
     * source whose name is already present REPLACES the existing instance
     * rather than appending — the map never grows unbounded, keeping the
     * resident worker leak-free across repeated enable cycles.
     *
     * @param SubtitleSourceInterface $source The source contributed by a plugin.
     * @return void
     *
     * @since 0.43.0
     */
    public function register(SubtitleSourceInterface $source): void
    {
        $this->sources[$source->getName()] = $source;
    }

    /**
     * Deregister a source by its canonical name.
     *
     * Truly removes the entry (no tombstone, no growth) so a plugin
     * enable → disable cycle leaves the registry as it was before enable.
     * A no-op when the name is not registered.
     *
     * @param string $name Canonical source name (e.g. `opensubtitles`).
     * @return void
     *
     * @since 0.43.0
     */
    public function deregister(string $name): void
    {
        unset($this->sources[$name]);
    }

    /**
     * Deregister a source by instance.
     *
     * Convenience for the plugin-disable path, which holds the live source
     * instance. Delegates to {@see deregister()} using the instance's
     * {@see SubtitleSourceInterface::getName()}.
     *
     * @param SubtitleSourceInterface $source The source instance to remove.
     * @return void
     *
     * @since 0.43.0
     */
    public function deregisterInstance(SubtitleSourceInterface $source): void
    {
        $this->deregister($source->getName());
    }

    /**
     * Whether a source with the given name is registered.
     *
     * @param string $name Canonical source name.
     * @return bool
     *
     * @since 0.43.0
     */
    public function has(string $name): bool
    {
        return isset($this->sources[$name]);
    }

    /**
     * Fetch a registered source by name.
     *
     * @param string $name Canonical source name.
     * @return SubtitleSourceInterface|null The source, or null when not registered.
     *
     * @since 0.43.0
     */
    public function get(string $name): ?SubtitleSourceInterface
    {
        return $this->sources[$name] ?? null;
    }

    /**
     * All registered sources keyed by canonical source name.
     *
     * @return array<string, SubtitleSourceInterface>
     *
     * @since 0.43.0
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
     * @since 0.43.0
     */
    public function names(): array
    {
        return array_keys($this->sources);
    }

    /**
     * Registered sources in effective priority order.
     *
     * Ordering (mirrors how `metadata.provider_priority` layers over a source's
     * intrinsic weight):
     *
     *  1. Sources NAMED in `$priorityOrder` (the effective
     *     `subtitles.provider_priority` list) come first, in exactly that order,
     *     skipping any name that is not currently registered.
     *  2. The remaining registered sources follow, ordered by their own
     *     {@see SubtitleSourceInterface::getPriority()} ascending (LOWER runs
     *     first, per the contract), with the canonical name as a stable
     *     tie-break so the order is deterministic across workers.
     *
     * Empty registry ⇒ empty list (the RULE-7 no-op the fetch service relies on).
     *
     * @param list<string> $priorityOrder Effective admin priority list of source
     *        names, best-first. Empty means "use intrinsic getPriority() only".
     *
     * @return list<SubtitleSourceInterface> Sources best-priority first.
     *
     * @since 0.43.0
     */
    public function byPriority(array $priorityOrder = []): array
    {
        $ordered = [];
        $seen = [];

        // 1. Pinned sources, in the admin-configured order.
        foreach ($priorityOrder as $name) {
            if (!is_string($name) || isset($seen[$name])) {
                continue;
            }
            $source = $this->sources[$name] ?? null;
            if ($source !== null) {
                $ordered[] = $source;
                $seen[$name] = true;
            }
        }

        // 2. The rest, by intrinsic priority then name.
        $rest = [];
        foreach ($this->sources as $name => $source) {
            if (!isset($seen[$name])) {
                $rest[] = $source;
            }
        }
        usort(
            $rest,
            static fn (SubtitleSourceInterface $a, SubtitleSourceInterface $b): int =>
                ($a->getPriority() <=> $b->getPriority()) ?: strcmp($a->getName(), $b->getName())
        );

        return array_merge($ordered, $rest);
    }
}
