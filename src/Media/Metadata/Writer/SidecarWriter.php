<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use DOMDocument;
use DOMElement;
use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Storage\ArtworkStorage;
use RuntimeException;

/**
 * The built-in S88 sidecar writer: writes `<basename>.nfo`, `poster.jpg` and
 * `fanart.jpg` next to the media file — the recommended-default, safer,
 * reversible member of the write-back family (updates.md #53). Non-destructive
 * and human-visible: it touches only sidecar files, never the media file
 * itself (embedded tags are S89), and every write lands atomically
 * (write-to-temp + rename) so a failure mid-write leaves the PREVIOUS sidecar
 * bytes intact, never a half-written file.
 *
 * Round-trips with the existing reader: the XML it emits is parsed by
 * {@see \Phlix\Media\Metadata\LocalNfoProvider} (`<?xml` header → `parseXmlNfo`
 * with the per-type root it recognises; images → `findLocalImages` poster /
 * backdrop filename patterns). The round-trip is pinned by tests in both
 * directions.
 *
 * Registered by the DI definition of {@see MetadataWriterRegistry} in
 * {@see \Phlix\Common\Container\Providers\MediaServicesProvider} (ruling R1),
 * so EVERY process that builds the container — the `metadata-write` worker
 * fork and any admin/status consumer — receives this writer at definition
 * time. The {@see \Phlix\Plugins\PluginLoader} capability arm stays reserved
 * for PLUGIN writers; the built-in never passes through it.
 *
 * Failure contract (ruling R2 — the S87 interface, not the superseded plan
 * wording): {@see self::write()} throws. The is_writable() pre-flight throws
 * the NAMED {@see SidecarNotWritableException} carrying item id + target dir +
 * reason, and {@see MetadataWriteWorker}'s per-writer `catch (Throwable) →
 * logger->warning` makes that warning line the operator-visible status. A
 * directory not being writable is exactly the known prior failure mode (the
 * `/var/artwork` sandbox incident: media dirs are NOT in any systemd
 * `ReadWritePaths` list), hence the remediation hint inside the exception
 * message.
 *
 * Image sidecars are opportunistic, never invented: they are written only when
 * the bytes already exist locally — the server's own artwork cache for the
 * poster ({@see ArtworkStorage::variantPath()}), or an operator-curated file
 * ALREADY INSIDE the item's own media directory referenced by `poster_path` /
 * `backdrop_path`. Remote TMDB paths (relative strings, URLs) and any path
 * outside the media directory resolve to "no source" and that image sidecar is
 * skipped: a sidecar writer must never become an arbitrary file-read primitive
 * driven by provider-controlled metadata.
 *
 * KNOWN LIMIT: fanart has no server-side byte source today — `ArtworkStorage`
 * caches posters only, so `fanart.jpg` is written only in the media-dir-local
 * case above. A cached-backdrop source belongs to a future artwork step, not
 * to S88.
 *
 * @since S88
 */
final class SidecarWriter implements MetadataWriterInterface
{
    /**
     * Generator marker emitted in the NFO header comment — identifies which
     * writer produced a sidecar (and survives tool round-trips through Kodi).
     */
    public const string GENERATOR_MARKER = 'S88SIDECARWRITERX7Q3';

    /** @var list<string> media_items.type discriminators this writer handles */
    private const SUPPORTED_TYPES = ['movie', 'episode', 'track'];

    public function __construct(
        private readonly ?ArtworkStorage $artworkStorage = null,
    ) {
    }

    public function supports(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    /**
     * @param MediaItem            $item              The indexed item (path/name/type as persisted)
     * @param array<string, mixed> $canonicalMetadata The canonical metadata map (decoded `metadata_json`)
     * @param string               $mediaDir          Directory containing the media file (sidecar home)
     *
     * @throws SidecarNotWritableException Pre-flight: target dir missing or unwritable (named, R2).
     * @throws RuntimeException            I/O failure mid-write (previous on-disk bytes stay intact).
     */
    public function write(MediaItem $item, array $canonicalMetadata, string $mediaDir): void
    {
        $this->assertDirectoryWritable($item->id, $mediaDir);

        $nfoPath = $this->sidecarPath($item, $mediaDir, 'nfo');
        $this->writeAtomically($nfoPath, $this->renderNfo($item->type, $canonicalMetadata), $item->id);

        $posterSource = $this->resolvePosterSource($item, $canonicalMetadata, $mediaDir);
        $this->writeImageSidecar($mediaDir, 'poster.jpg', $posterSource, $item->id);

        $fanartSource = $this->resolveInMediaDirectoryFile($canonicalMetadata, 'backdrop_path', $mediaDir);
        $this->writeImageSidecar($mediaDir, 'fanart.jpg', $fanartSource, $item->id);
    }

    // ── pre-flight ────────────────────────────────────────────────────────

    /**
     * @throws SidecarNotWritableException
     */
    private function assertDirectoryWritable(string $itemId, string $mediaDir): void
    {
        if ($mediaDir === '' || $mediaDir === '.') {
            // dirname('') is '.' — a row with an empty path column would put
            // sidecars in the metadata-write fork's WORKING DIRECTORY, which is
            // not "next to the media file" and exactly the write-somewhere-
            // surprising failure mode this pre-flight exists to catch.
            throw SidecarNotWritableException::degenerateDirectory($itemId, $mediaDir);
        }

        if (!is_dir($mediaDir)) {
            throw SidecarNotWritableException::missingDirectory($itemId, $mediaDir);
        }

        if (!is_writable($mediaDir)) {
            throw SidecarNotWritableException::notWritable($itemId, $mediaDir);
        }
    }

    // ── NFO rendering (pure: same type + metadata ⇒ same bytes) ──────────

    /**
     * @param array<string, mixed> $metadata
     */
    private function renderNfo(string $type, array $metadata): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $dom->appendChild($dom->createComment(' generated by phlix SidecarWriter ' . self::GENERATOR_MARKER . ' '));

        $root = match ($type) {
            'episode' => $dom->createElement('episodedetails'),
            'track' => $dom->createElement('song'),
            default => $dom->createElement('movie'),
        };
        $dom->appendChild($root);

        match ($type) {
            'episode' => $this->appendEpisodeElements($dom, $root, $metadata),
            'track' => $this->appendTrackElements($dom, $root, $metadata),
            default => $this->appendMovieElements($dom, $root, $metadata),
        };

        $xml = $dom->saveXML();

        // saveXML() returns false only on an unrecoverable serialization error;
        // failing loud here keeps "write() threw OR wrote the bytes" true.
        if (!is_string($xml)) {
            throw new RuntimeException('SidecarWriter: NFO serialization failed');
        }

        return $xml;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function appendMovieElements(DOMDocument $dom, DOMElement $root, array $metadata): void
    {
        $this->appendText($dom, $root, 'title', MetadataValue::asString($metadata['name'] ?? null));
        $this->appendText($dom, $root, 'originaltitle', MetadataValue::asString($metadata['original_name'] ?? null));
        $this->appendText($dom, $root, 'plot', MetadataValue::asString($metadata['overview'] ?? null));
        $this->appendInt($dom, $root, 'year', $this->nullableInt($metadata['year'] ?? null));
        $this->appendText($dom, $root, 'premiered', MetadataValue::asString($metadata['premiered'] ?? null));
        $this->appendFloat($dom, $root, 'rating', $this->nullableFloat($metadata['vote_average'] ?? null));
        $this->appendInt($dom, $root, 'votes', $this->nullableInt($metadata['vote_count'] ?? null));
        $this->appendInt($dom, $root, 'runtime', $this->runtimeMinutes($metadata['runtime_ticks'] ?? null));
        $this->appendText($dom, $root, 'mpaa', MetadataValue::asString($metadata['official_rating'] ?? null));
        $this->appendText($dom, $root, 'tagline', MetadataValue::asString($metadata['tagline'] ?? null));
        $this->appendList($dom, $root, 'genre', $this->stringList($metadata['genres'] ?? null));
        $this->appendList($dom, $root, 'studio', $this->optionalList($metadata['studio'] ?? null));
        $this->appendText($dom, $root, 'director', MetadataValue::asString($metadata['director'] ?? null));
        $this->appendActors($dom, $root, $metadata['actors'] ?? null);
        $this->appendText($dom, $root, 'tmdbid', MetadataValue::asString($metadata['tmdb_id'] ?? null));
        $this->appendText($dom, $root, 'imdbid', MetadataValue::asString($metadata['imdb_id'] ?? null));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function appendEpisodeElements(DOMDocument $dom, DOMElement $root, array $metadata): void
    {
        $this->appendText($dom, $root, 'title', MetadataValue::asString($metadata['name'] ?? null));
        $this->appendText($dom, $root, 'plot', MetadataValue::asString($metadata['overview'] ?? null));
        $this->appendInt($dom, $root, 'season', $this->nullableInt($metadata['season'] ?? null));
        $this->appendInt($dom, $root, 'episode', $this->nullableInt($metadata['episode'] ?? null));
        $this->appendText($dom, $root, 'aired', MetadataValue::asString($metadata['aired'] ?? null));
        $this->appendFloat($dom, $root, 'rating', $this->nullableFloat($metadata['vote_average'] ?? null));
        $this->appendInt($dom, $root, 'runtime', $this->runtimeMinutes($metadata['runtime_ticks'] ?? null));
        $this->appendText($dom, $root, 'director', MetadataValue::asString($metadata['director'] ?? null));
        $this->appendText($dom, $root, 'credits', MetadataValue::asString($metadata['credits'] ?? null));
        $this->appendText($dom, $root, 'tmdbid', MetadataValue::asString($metadata['tmdb_id'] ?? null));
        $this->appendText($dom, $root, 'imdbid', MetadataValue::asString($metadata['imdb_id'] ?? null));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function appendTrackElements(DOMDocument $dom, DOMElement $root, array $metadata): void
    {
        $this->appendText($dom, $root, 'title', MetadataValue::asString($metadata['name'] ?? null));
        $this->appendText($dom, $root, 'artist', MetadataValue::asString($metadata['artist'] ?? null));
        $this->appendText($dom, $root, 'album', MetadataValue::asString($metadata['album'] ?? null));
        $this->appendInt($dom, $root, 'tracknumber', $this->nullableInt($metadata['track'] ?? null));
        $this->appendInt($dom, $root, 'year', $this->nullableInt($metadata['year'] ?? null));
    }

    /**
     * Append one element carrying a literal text value.
     *
     * The text MUST be created via createTextNode(): DOMDocument's two-arg
     * createElement() parses its value as an XML FRAGMENT, so an actor named
     * "Inception & Co" or a plot containing "<" would emit an "unterminated
     * entity reference" warning and SILENTLY DROP the element content
     * (measured). Numeric values cannot carry markup, so they stay on the
     * single-arg path.
     */
    private function appendText(DOMDocument $dom, DOMElement $root, string $element, string $value): void
    {
        if ($value === '') {
            return;
        }

        $node = $dom->createElement($element);
        $node->appendChild($dom->createTextNode($this->textNodeValue($value)));
        $root->appendChild($node);
    }

    private function appendInt(DOMDocument $dom, DOMElement $root, string $element, ?int $value): void
    {
        if ($value === null) {
            return;
        }

        $root->appendChild($dom->createElement($element, (string) $value));
    }

    private function appendFloat(DOMDocument $dom, DOMElement $root, string $element, ?float $value): void
    {
        if ($value === null) {
            return;
        }

        $root->appendChild($dom->createElement($element, $this->formatFloat($value)));
    }

    /**
     * @param list<string> $values
     */
    private function appendList(DOMDocument $dom, DOMElement $root, string $element, array $values): void
    {
        foreach ($values as $value) {
            $this->appendText($dom, $root, $element, $value);
        }
    }

    /**
     * @param mixed $actors Raw `actors` value: list of {name, role} maps
     */
    private function appendActors(DOMDocument $dom, DOMElement $root, mixed $actors): void
    {
        $order = 0;
        foreach (MetadataValue::asAssocList($actors) as $actor) {
            $name = MetadataValue::asString($actor['name'] ?? null);
            if ($name === '') {
                continue;
            }

            $element = $dom->createElement('actor');
            $this->appendText($dom, $element, 'name', $name);
            $this->appendText($dom, $element, 'role', MetadataValue::asString($actor['role'] ?? null));
            $element->appendChild($dom->createElement('order', (string) $order++));
            $root->appendChild($element);
        }
    }

    // ── boundary parsing of mixed canonical values ────────────────────────

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * runtime_ticks is the canonical duration (600000000 ticks per minute) →
     * Kodi's <runtime>. TRUNCATION, not rounding, mirrors the canonical
     * API-side mapper (FieldMappers::ticksToMinutes, runtime_ticks → runtime,
     * which casts) so the sidecar never shows a minute different from the UI
     * for the same row. A zero or negative tick count emits nothing.
     */
    private function runtimeMinutes(mixed $ticks): ?int
    {
        if (!is_numeric($ticks)) {
            return null;
        }

        $minutes = (int) (((float) $ticks) / 600000000);

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * A single canonical string (e.g. `studio`) or a list, normalised to a
     * list of non-blank strings for repeated NFO elements.
     *
     * @return list<string>
     */
    private function optionalList(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        return $this->stringList($value);
    }

    /**
     * Canonical genres arrive as `list<string>`; narrow any mixed list to the
     * non-empty strings it actually carries (order preserved).
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $out = [];
        foreach (MetadataValue::asList($value) as $entry) {
            $string = MetadataValue::asString($entry);
            if ($string !== '') {
                $out[] = $string;
            }
        }

        return $out;
    }

    /**
     * Escape a value for a DOM text node. DOMDocument escapes XML metachars
     * itself; the remaining hazard is control characters, which are illegal in
     * XML 1.0 at ALL (so a title with a stray control byte would make the
     * whole NFO unparseable). Strip exactly the characters XML cannot carry and
     * keep everything else byte-identical.
     */
    private function textNodeValue(string $value): string
    {
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return $sanitized ?? '';
    }

    /**
     * Render a float the way NFO consumers expect: no trailing ".0", full
     * precision otherwise (round-trips LocalNfoProvider::parseFloat()).
     */
    private function formatFloat(float $value): string
    {
        $rendered = (string) $value;

        return str_contains($rendered, '.') ? $rendered : $rendered . '.0';
    }

    // ── sidecar paths + atomic I/O ────────────────────────────────────────

    private function sidecarPath(MediaItem $item, string $mediaDir, string $extension): string
    {
        $stem = pathinfo(basename($item->path), PATHINFO_FILENAME);
        if ($stem === '') {
            // Malformed-row fallback (path column NULL/empty): the DB `name` is
            // provider-derived and NOT path-sanitized, so it must pass the same
            // jail doctrine as the metadata artwork refs — basename() strips any
            // directory component, and the dot guards keep the file inside
            // $mediaDir. The forced `.nfo` extension is the final constant.
            $stem = basename($item->name);
            if ($stem === '' || $stem === '.' || $stem === '..') {
                $stem = 'sidecar';
            }
        }

        return rtrim($mediaDir, '/') . '/' . $stem . '.' . $extension;
    }

    /**
     * Write via a sibling temp file + rename: readers never observe a partial
     * sidecar, and a failed write leaves the previous bytes in place.
     *
     * The temp name is unique per write (pid-prefixed, entropy-suffixed) so
     * two writers ever sharing a media directory — same-directory items today,
     * the bounded-parallel drain the worker docblock reserves for later —
     * cannot clobber each other's temp file mid-flight: pids distinguish
     * processes, and within one process uniqid's monotonic entropy suffix
     * distinguishes calls. (uniqid alone does NOT guarantee uniqueness across
     * fork siblings sharing an inherited LCG state — hence the prefix.)
     *
     * @throws RuntimeException On any I/O failure (temp cleaned up first).
     */
    private function writeAtomically(string $targetPath, string $contents, string $itemId): void
    {
        $tempPath = $targetPath . '.phlix-tmp-' . getmypid() . '-' . uniqid('', true);

        if (file_put_contents($tempPath, $contents) === false) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf(
                'SidecarWriter: could not write temp sidecar for item %s [%s]',
                $itemId,
                $targetPath,
            ));
        }

        // 0644: sidecars are human-visible library files, written by a service
        // user whose umask must not make them unreadable to the library owner.
        @chmod($tempPath, 0644);

        if (!rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf(
                'SidecarWriter: could not publish sidecar for item %s [%s]',
                $itemId,
                $targetPath,
            ));
        }
    }

    /**
     * @throws RuntimeException Propagated from {@see self::writeAtomically()}.
     */
    private function writeImageSidecar(string $mediaDir, string $sidecarName, ?string $sourcePath, string $itemId): void
    {
        if ($sourcePath === null) {
            return;
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return;
        }

        $targetPath = rtrim($mediaDir, '/') . '/' . $sidecarName;
        $sourceReal = realpath($sourcePath);
        $targetReal = realpath($targetPath);
        if ($sourceReal !== false && $targetReal !== false && $sourceReal === $targetReal) {
            // The canonical source IS already the standard sidecar file — nothing to copy.
            return;
        }

        $bytes = file_get_contents($sourcePath);
        if ($bytes === false) {
            return;
        }

        $this->writeAtomically($targetPath, $bytes, $itemId);
    }

    /**
     * Poster bytes, in preference order: an operator-curated image already in
     * the item's own media directory, else the server's artwork-cache original
     * (falling back through the stored widths). Null = no local source exists
     * (remote TMDB path, uncached item) → the sidecar is skipped.
     *
     * @param array<string, mixed> $metadata
     */
    private function resolvePosterSource(MediaItem $item, array $metadata, string $mediaDir): ?string
    {
        $local = $this->resolveInMediaDirectoryFile($metadata, 'poster_path', $mediaDir);
        if ($local !== null) {
            return $local;
        }

        if ($this->artworkStorage === null) {
            return null;
        }

        foreach (['original', 'w780', 'w500', 'w342', 'w185'] as $size) {
            $variant = $this->artworkStorage->variantPath($item->id, $size);
            if ($variant !== null) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Resolve a metadata artwork reference ONLY when it points at a readable
     * path inside the item's own media directory.
     *
     * Residual (accepted, S345 rule 4): the jail resolves with realpath() at
     * CHECK time; a local attacker who can already rename paths inside the
     * media directory could still swap the final component before the read —
     * an actor who can write sidecars there directly anyway. The threat model
     * this guard exists for (provider-controlled metadata strings) is fully
     * covered.
     *
     * Provider-controlled strings (TMDB relative paths like `/abc.jpg`, image
     * URLs) and absolute paths anywhere else on disk deliberately resolve to
     * null: the sidecar writer copies pictures the operator already keeps with
     * the media, never arbitrary files the metadata happens to name.
     *
     * @param array<string, mixed> $metadata
     */
    private function resolveInMediaDirectoryFile(array $metadata, string $key, string $mediaDir): ?string
    {
        $candidate = MetadataValue::asNullableString($metadata[$key] ?? null);
        if ($candidate === null || $candidate === '' || !str_starts_with($candidate, '/')) {
            return null;
        }

        $dirReal = realpath($mediaDir);
        $candidateReal = realpath($candidate);
        if ($dirReal === false || $candidateReal === false) {
            return null;
        }

        $insideMediaDir = $candidateReal === $dirReal
            || str_starts_with($candidateReal, $dirReal . '/');

        return $insideMediaDir ? $candidateReal : null;
    }
}
