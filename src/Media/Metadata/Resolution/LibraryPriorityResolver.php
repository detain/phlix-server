<?php

declare(strict_types=1);

namespace Phlix\Media\Metadata\Resolution;

/**
 * Builds the EFFECTIVE {@see PriorityConfig} for a single library by layering
 * the library's own `options.metadata_priority` override OVER the global
 * `metadata.provider_priority` default (item 5, per-library provider priority).
 *
 * ## Merge semantics (REPLACE-not-deep-merge, per type)
 *
 * Mirrors {@see \Phlix\Common\Container\Providers\MediaServicesProvider}'s
 * global default <- admin-override merge: a media type the library override
 * names REPLACES that type's global list outright; a type ABSENT from the
 * override keeps the global list. An empty/absent override leaves the global
 * config entirely intact (it never blanks the map). The global `genresMode()`
 * is always preserved — the per-library override only re-orders sources.
 *
 * ## Resident-memory (Workerman) safety
 *
 * The resolver holds only the injected immutable global {@see PriorityConfig};
 * it performs NO I/O and keeps NO mutable static/global state. Each call to
 * {@see effectiveFor()} returns either the shared global config (when there is
 * no override) or a fresh immutable {@see PriorityConfig} — safe to share and
 * to call per scan/match without leaking.
 *
 * @package Phlix\Media\Metadata\Resolution
 * @since   Feature 5 (per-library provider priority)
 */
final class LibraryPriorityResolver
{
    /** Global (settings-merged) effective priority config — the fallback base. */
    private PriorityConfig $globalPriority;

    /**
     * @param PriorityConfig $globalPriority The global effective priority config
     *     (config default <- admin override), built once by
     *     {@see \Phlix\Common\Container\Providers\MediaServicesProvider}. Used
     *     as the base every library override is layered over.
     */
    public function __construct(PriorityConfig $globalPriority)
    {
        $this->globalPriority = $globalPriority;
    }

    /**
     * The effective priority config for a library given its optional override.
     *
     * @param array<string, list<string>>|null $libraryPriority The library's
     *     well-formed `options.metadata_priority` map (see
     *     {@see \Phlix\Media\Library\Dto\LibraryRow::metadataPriority()}), or
     *     null/empty when the library has no override.
     *
     * @return PriorityConfig The global config UNCHANGED when there is no
     *     override; otherwise a NEW config whose per-type map is the override
     *     layered over the global map (per-type REPLACE-merge), preserving the
     *     global genres mode.
     */
    public function effectiveFor(?array $libraryPriority): PriorityConfig
    {
        if ($libraryPriority === null || $libraryPriority === []) {
            return $this->globalPriority;
        }

        // Start from the global per-type map, then REPLACE each type the
        // override names — mirroring the global default <- override merge.
        $merged = $this->globalPriority->toArray();
        foreach ($libraryPriority as $type => $order) {
            $merged[$type] = array_values($order);
        }

        return new PriorityConfig($merged, $this->globalPriority->genresMode());
    }
}
