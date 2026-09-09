<?php

/**
 * Phlix media server component: Metadata.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Media\Metadata\Writer;

use Phlix\Media\Library\MediaItem;
use Phlix\Media\Metadata\Dto\MetadataValue;
use Phlix\Media\Metadata\EmbeddedWritePolicy;
use Phlix\Media\Metadata\MetadataOverwritePolicy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The S89 embedded-tag writer: writes canonical metadata INTO the media file
 * itself — `getid3_writetags` for audio, `ffmpeg -metadata` copy-remux for
 * MP4/MKV — the riskier, destructive member of the write-back family
 * (updates.md #53). Everything here is strictly opt-in and atomic:
 *
 *  - **Default OFF proven at three layers.** No job is even enqueued unless the
 *    library opted into write-back (`LibraryRow::metadataWriteEnabled()`,
 *    default off — S87 gate); on top of that this writer re-checks its OWN
 *    dedicated global gate ({@see EmbeddedWritePolicy}, default off) before any
 *    file I/O, so flipping write-back on for the safe sidecar writer can NEVER
 *    implicitly start rewriting media files.
 *  - **Policy-deny is a skip, not a failure (ruling R1).** When embedded tags
 *    already exist and {@see MetadataOverwritePolicy::overwriteExisting()} says
 *    no, the writer returns normally with a logged reason; throws stay
 *    reserved for write failures per the shipped interface contract.
 *  - **Per-file curation outranks everything (ruling R2).** The global bool
 *    cannot see a hand-curated sidecar, so a concrete predicate runs IN FRONT
 *    of the policy: an existing Kodi-convention or stem sidecar NFO that does
 *    not carry `SidecarWriter::GENERATOR_MARKER` was not machine-generated —
 *    it is operator-curated NFO — and the embedded write is skipped for that
 *    file. (Marker-presence is the detectable candidate ruling R2 allowed;
 *    an NFO the sidecar writer itself emitted carries the marker — verified
 *    at `SidecarWriter::renderNfo()`.)
     *  - **Atomic-rename discipline.** Every mutation stages into an exclusively
     *    created (`fopen 'xb'`) sibling temp file in the media directory — the
     *    media itself must be a regular file; a symlink at the media path is
     *    refused rather than dereferenced — and only then `rename()`s over the
     *    original.
     *    A remux interrupted at any point — killed binary, codec refusal, failed
     *    publish — leaves the ORIGINAL bytes intact; the staged temp is removed
     *    best-effort and the failure throws (the worker logs it and continues).
 *
 * Registered through the SAME `MetadataWriterRegistry` DI seam S88 established
 * (ruling R4) — NOT the PluginLoader capability arm; zero routes, zero
 * migrations.
 *
 * KNOWN LIMITS (measured, not implied):
 *  - In the combined worker drain, {@see SidecarWriter} registered ahead of
 *    this writer re-stamps the stem sidecar NFO with its marker BEFORE this
 *    writer consults it, so in that ordering the curation predicate can only
 *    fire for the Kodi-convention names (`movie.nfo`/`tvshow.nfo`/`episode.nfo`)
 *    and for a stem sidecar the machine did not rewrite. Direct callers of
 *    `write()` (the path the AC pins) see the full predicate.
 *  - A hand-edited NFO that still carries the generator marker reads as
 *    machine-generated; a cheap presence check cannot tell them apart.
 *  - An audio stream inside an MP4 is tagged via the video (ffmpeg) arm; the
 *    getID3 arm covers the dedicated audio extensions listed in
 *    {@see self::AUDIO_EXTENSIONS}.
 *
 * @since S89
 */
final class EmbeddedMetadataWriter implements MetadataWriterInterface
{
    /**
     * Identity marker for this writer (survives in code, mirrors
     * {@see SidecarWriter::GENERATOR_MARKER}). Deliberately NOT stamped into
     * media files: unlike a sidecar header comment, embedded tag frames are
     * operator-owned data this writer must not pollute.
     */
    public const string GENERATOR_MARKER = 'S89EMBEDDEDTAGX5T8';

    /** @var list<string> media_items.type discriminators this writer handles */
    private const SUPPORTED_TYPES = ['movie', 'episode', 'track'];

    /** @var list<string> lower-case extensions written through getID3 */
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'ogg', 'oga', 'm4a', 'mp4a', 'opus'];

    /** @var list<string> lower-case extensions written through an ffmpeg copy-remux */
    private const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'mkv'];

    /**
     * NFO names whose presence without a generator marker means an operator
     * curated the sidecar by hand. Stem sidecar first — the exact path
     * {@see SidecarWriter} writes — then the Kodi conventions
     * {@see \Phlix\Media\Metadata\LocalNfoProvider} looks up.
     *
     * @var list<string>
     */
    private const CONVENTIONAL_NFO_NAMES = ['movie.nfo', 'tvshow.nfo', 'episode.nfo'];

    /**
     * Tag frame names (lower-case — measured: getID3 exposes frames as
     * `tags['id3v2']['title'] = ['Real Title']` on a real ffmpeg-tagged MP3,
     * name-matched case-insensitively below) that mean a human or tool
     * actually tagged this file. `encoder_settings` / `creation_time` style
     * muxer housekeeping is deliberately NOT in this set: its presence must
     * not turn a clean file into an overwrite-policy-gated one.
     *
     * @var list<string>
     */
    private const CONTENT_TAG_NAMES = [
        'title', 'artist', 'album', 'track', 'tracknumber', 'date', 'year',
        'genre', 'comment', 'synopsis', 'description', 'summary', 'lyrics',
        'albumartist', 'artists', 'performer', 'composer',
    ];

    private LoggerInterface $logger;

    public function __construct(
        private readonly EmbeddedWritePolicy $optIn,
        private readonly MetadataOverwritePolicy $overwritePolicy,
        private readonly ExternalCommandRunnerInterface $runner,
        private readonly string $ffmpegPath = '/usr/bin/ffmpeg',
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function supports(string $type): bool
    {
        return in_array($type, self::SUPPORTED_TYPES, true);
    }

    /**
     * @param MediaItem            $item              The indexed item (path/name/type as persisted)
     * @param array<string, mixed> $canonicalMetadata The canonical metadata map (decoded `metadata_json`)
     * @param string               $mediaDir          Directory containing the media file (from the worker)
     *
     * Guard order is load-bearing: gate → pre-flight → curation (R2, IN FRONT
     * of the policy) → overwrite policy (R1) → write. A skipped write always
     * means zero bytes touched.
     *
     * @throws EmbeddedWriteFailedException Pre-flight or write failure; the
     *         original file is intact at every throw site.
     */
    public function write(MediaItem $item, array $canonicalMetadata, string $mediaDir): void
    {
        // 1. Explicit opt-in (default off). This is the AC's "no write happens
        //    without explicit opt-in" gate; the enqueue gate sits upstream.
        //    Logged at DEBUG, not INFO: gate-off is the shipped steady state
        //    for every drained job, and an INFO line per item would bury the
        //    policy/curation skips that operators actually act on.
        if (!$this->optIn->embeddedWriteEnabled()) {
            $this->logger->debug('EmbeddedMetadataWriter: skipped, embedded writing not enabled', [
                'item_id' => $item->id,
                'setting' => EmbeddedWritePolicy::SETTING_KEY,
            ]);

            return;
        }

        $mediaPath = $this->assertMediaJail($item, $mediaDir);

        // 2. Per-file curation check — ruling R2, concrete predicate in front
        //    of the global policy bool.
        $curatedNfo = $this->detectOperatorCuratedNfo($item, $mediaDir);
        if ($curatedNfo !== null) {
            $this->logger->info('EmbeddedMetadataWriter: skipped, operator-curated NFO present', [
                'item_id' => $item->id,
                'nfo_path' => $curatedNfo,
            ]);

            return;
        }

        // 3. Overwrite policy — ruling R1: deny is a logged skip, NOT a throw.
        if ($this->hasExistingEmbeddedTags($mediaPath) && !$this->overwritePolicy->overwriteExisting()) {
            $this->logger->info('EmbeddedMetadataWriter: skipped, existing embedded tags and overwrite policy denies', [
                'item_id' => $item->id,
                'setting' => MetadataOverwritePolicy::SETTING_KEY,
            ]);

            return;
        }

        $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            $this->embedViaFfmpegRemux($item, $mediaPath, $canonicalMetadata);

            return;
        }

        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            $this->embedViaGetId3($item, $mediaPath, $canonicalMetadata, $extension);

            return;
        }

        // Not a container this writer rewrites (e.g. .avi, .wmv): normal return,
        // zero bytes touched. supports() is type-level by contract; container
        // scoping is decided here, per file.
        $this->logger->info('EmbeddedMetadataWriter: skipped, unsupported container extension', [
            'item_id' => $item->id,
            'extension' => $extension,
        ]);
    }

    // ── pre-flight ────────────────────────────────────────────────────────

    /**
     * Mirror of SidecarWriter's refusal taxonomy for the media-file case:
     * a degenerate dir, a missing file, an unwritable directory each throw the
     * named exception (the worker's per-writer catch logs it as the
     * operator-visible status). Returns the JAIL-resolved media path —
     * basename() of the row path under the worker-provided directory — which
     * is what every subsequent operation uses, so a hostile/stale path column
     * can never aim the writer outside $mediaDir.
     *
     * @throws EmbeddedWriteFailedException
     */
    private function assertMediaJail(MediaItem $item, string $mediaDir): string
    {
        if ($mediaDir === '' || $mediaDir === '.') {
            // dirname('') is '.' — same working-directory write this pre-flight
            // refuses on the sidecar path (S88 round 2).
            throw EmbeddedWriteFailedException::degenerateDirectory($item->id, $item->path);
        }

        $mediaPath = rtrim($mediaDir, '/') . '/' . basename($item->path);

        if (!is_file($mediaPath)) {
            throw EmbeddedWriteFailedException::missingMediaFile($item->id, $mediaPath);
        }

        // is_file() follows symlinks, so a valid link passes the check above —
        // and this writer is the DESTRUCTIVE arm: copy() would read through the
        // link and the publish rename would replace the operator's link with a
        // regular file containing (possibly out-of-tree) target content. Only a
        // real regular file is rewritten.
        if (is_link($mediaPath)) {
            throw EmbeddedWriteFailedException::symlinkRefused($item->id, $mediaPath);
        }

        if (!is_writable($mediaDir)) {
            throw EmbeddedWriteFailedException::directoryNotWritable($item->id, $mediaPath);
        }

        return $mediaPath;
    }

    // ── ruling R2: per-file operator-curated NFO predicate ────────────────

    /**
     * A sidecar NFO that EXISTS but does NOT carry
     * {@see SidecarWriter::GENERATOR_MARKER} was not emitted by the machine —
     * treat it as operator-curated and refuse to rewrite the media file beside
     * it. Returns the offending NFO path, or null when nothing is curated.
     *
     * An unreadable existing NFO counts as curated: when the writer cannot
     * prove a sidecar is machine-made, the destructive path must not run.
     */
    private function detectOperatorCuratedNfo(MediaItem $item, string $mediaDir): ?string
    {
        $candidates = [$this->sidecarNfoPath($item, $mediaDir)];
        foreach (self::CONVENTIONAL_NFO_NAMES as $name) {
            $candidates[] = rtrim($mediaDir, '/') . '/' . $name;
        }

        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            $contents = @file_get_contents($candidate);
            if ($contents === false || !str_contains($contents, SidecarWriter::GENERATOR_MARKER)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Stem sidecar path — IDENTICAL construction to SidecarWriter's own
     * `sidecarPath()` so the machine pair is exactly the file judged here.
     */
    private function sidecarNfoPath(MediaItem $item, string $mediaDir): string
    {
        $stem = pathinfo(basename($item->path), PATHINFO_FILENAME);
        if ($stem === '') {
            $stem = basename($item->name);
            if ($stem === '' || $stem === '.' || $stem === '..') {
                $stem = 'sidecar';
            }
        }

        return rtrim($mediaDir, '/') . '/' . $stem . '.nfo';
    }

    // ── ruling R1: existing-tag detection for the policy gate ─────────────

    /**
     * Does the file already carry CONTENT embedded tags? An analyze() that
     * THROWS or returns a non-array answers TRUE — fail-safe toward "there
     * might be operator tags here", never toward clobbering them. A file that
     * parses cleanly with zero frames answers FALSE (measured: getID3 does
     * not throw on junk bytes; the write arms then fail on them loudly).
     *
     * Measured traversal (real ffmpeg→getID3 round-trip): one level of format
     * key (`id3v2`, `quicktime`, `matrosk`, …) then lower-case frame names; a
     * second optional case-normalisation level (`raw`/`lossy`/…) is tolerated.
     */
    private function hasExistingEmbeddedTags(string $mediaPath): bool
    {
        try {
            $analysis = (new \getID3())->analyze($mediaPath);
        } catch (Throwable) {
            return true;
        }

        if (!is_array($analysis)) {
            return true;
        }

        /** @var mixed $tagSections */
        $tagSections = $analysis['tags'] ?? null;
        if (!is_array($tagSections)) {
            return false;
        }

        foreach ($tagSections as $formatSection) {
            if (!is_array($formatSection)) {
                continue;
            }
            foreach ($formatSection as $caseOrFrame => $caseOrValues) {
                if ($this->isContentFrameHit((string) $caseOrFrame, $caseOrValues)) {
                    return true;
                }

                if (is_array($caseOrValues)) {
                    foreach ($caseOrValues as $frame => $values) {
                        if ($this->isContentFrameHit((string) $frame, $values)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Name matches a content frame AND carries a non-empty value. List values
     * of only empty strings do not count as tagged content.
     */
    private function isContentFrameHit(string $name, mixed $values): bool
    {
        if (!in_array(strtolower($name), self::CONTENT_TAG_NAMES, true)) {
            return false;
        }

        $list = is_array($values) ? $values : [$values];
        foreach ($list as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    // ── write paths (all staged + atomic) ─────────────────────────────────

    /**
     * MP4/MKV: copy-remux with -metadata onto a staged sibling, publish by
     * rename. Stream copy (`-map 0 -c copy`) keeps audio/video bytes
     * identical — only the metadata container is rewritten.
     *
     * @param array<string, mixed> $canonicalMetadata
     *
     * @throws EmbeddedWriteFailedException
     */
    private function embedViaFfmpegRemux(MediaItem $item, string $mediaPath, array $canonicalMetadata): void
    {
        $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $flags = $this->metadataFlags($canonicalMetadata);
        if ($flags === []) {
            // Symmetric with the audio arm's empty-tag_data guard: a remux with
            // ZERO -metadata flags would be a pointless multi-GB rewrite of the
            // operator's file that changes nothing worth changing — refuse
            // before staging (and before any I/O on the original at all).
            throw EmbeddedWriteFailedException::stageFailed(
                $item->id,
                $mediaPath,
                'canonical metadata carries no embeddable video fields',
            );
        }

        $staged = '';
        try {
            $staged = $this->createStage($item, $mediaPath);

            // NO pre-stage copy(): ffmpeg -y creates the staged file itself, so
            // copying a multi-GB film first would double the I/O and buy
            // nothing — the original stays read-only either way.
            $arguments = [
                escapeshellarg($this->ffmpegPath),
                '-nostdin -hide_banner -loglevel error -y',
                '-i ' . escapeshellarg($mediaPath),
                '-map 0 -c copy',
            ];
            foreach ($flags as $flag) {
                $arguments[] = $flag;
            }
            // faststart only rebuilds the MP4 atom index; MKV would reject it.
            // The container itself is PINNED with -f: the staged sibling carries
            // no media extension by design (scanner-invisible), so extension
            // sniffing is not available and must not be relied on.
            if (in_array($extension, ['mp4', 'm4v'], true)) {
                $arguments[] = '-movflags +faststart -f mp4';
            } else {
                $arguments[] = '-f matroska';
            }
            $arguments[] = escapeshellarg($staged);

            $result = $this->runner->run(implode(' ', $arguments));
            $exitCode = (int) ($result['exitCode'] ?? -1);
            if ($exitCode !== 0) {
                // Covers the interrupted-remux shape (signal kill / negative
                // exit / non-zero codec refusal): the ORIGINAL was only ever
                // read, the staged sibling holds at best partial bytes.
                throw EmbeddedWriteFailedException::stageFailed(
                    $item->id,
                    $mediaPath,
                    sprintf('ffmpeg remux exited %d: %s', $exitCode, $this->tail((string) ($result['stderr'] ?? ''))),
                );
            }

            if (!is_file($staged) || (int) filesize($staged) === 0) {
                throw EmbeddedWriteFailedException::stageFailed(
                    $item->id,
                    $mediaPath,
                    'ffmpeg exited 0 but produced no usable staged file',
                );
            }

            $this->publish($item, $mediaPath, $staged);
        } catch (EmbeddedWriteFailedException $e) {
            $this->discardStaged($staged);

            throw $e;
        } catch (Throwable $e) {
            $this->discardStaged($staged);

            throw EmbeddedWriteFailedException::stageFailed(
                $item->id,
                $mediaPath,
                'unexpected remux failure: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Audio: rewrite tags through getID3 against the staged sibling —
     * getid3_writetags writes IN PLACE at ->filename, which is exactly why the
     * original is never handed to it. Instantiating \getID3 first is REQUIRED
     * (write.php throws otherwise; same load order as the shipped retag test).
     *
     * @param array<string, mixed> $canonicalMetadata
     *
     * @throws EmbeddedWriteFailedException
     */
    private function embedViaGetId3(
        MediaItem $item,
        string $mediaPath,
        array $canonicalMetadata,
        string $extension,
    ): void {
        $tagData = $this->audioTagData($canonicalMetadata);
        if ($tagData === []) {
            // Fail loud BEFORE any file is staged: rewriting the header with an
            // empty frame map would strip existing tags and add nothing.
            throw EmbeddedWriteFailedException::stageFailed(
                $item->id,
                $mediaPath,
                'canonical metadata carries no embeddable audio fields',
            );
        }

        $staged = '';
        try {
            $staged = $this->createStage($item, $mediaPath);

            // getID3 edits IN PLACE, so the copy must be complete before it
            // runs — an unchecked copy() could hand a PARTIAL stage to a tool
            // that happily tags it, and the publish would ship the truncation.
            if (!copy($mediaPath, $staged)) {
                throw EmbeddedWriteFailedException::stageFailed(
                    $item->id,
                    $mediaPath,
                    'could not stage a complete copy of the original beside it (disk full?)',
                );
            }

            $writer = new \getid3_writetags();
            $writer->filename = $staged;
            $writer->tagformats = $extension === 'mp3' ? ['id3v2.3'] : ['native'];
            $writer->overwrite_tags = true;
            $writer->remove_other_tags = false;
            $writer->tag_encoding = 'UTF-8';
            $writer->tag_data = $tagData;

            $wrote = $writer->WriteTags();
            if ($wrote === false) {
                $reason = empty($writer->errors)
                    ? 'no error reported'
                    : implode('; ', array_map('strval', $writer->errors));

                throw EmbeddedWriteFailedException::stageFailed(
                    $item->id,
                    $mediaPath,
                    'getid3_writetags failed: ' . $reason,
                );
            }

            $this->publish($item, $mediaPath, $staged);
        } catch (EmbeddedWriteFailedException $e) {
            $this->discardStaged($staged);

            throw $e;
        } catch (Throwable $e) {
            $this->discardStaged($staged);

            throw EmbeddedWriteFailedException::stageFailed(
                $item->id,
                $mediaPath,
                'unexpected tag write failure: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Publish the staged sibling over the original via rename: on POSIX the
     * rename is atomic within the directory, so a reader (or a power loss)
     * observes either the OLD file or the complete NEW file, never a mix.
     *
     * @throws EmbeddedWriteFailedException
     */
    private function publish(MediaItem $item, string $mediaPath, string $staged): void
    {
        // copy() preserves the source mode for a new target; the explicit
        // chmod only re-applies the ORIGINAL's permission bits in case the
        // staged file inherited a different umask mid-flight. The mask drops
        // setuid/setgid/sticky deliberately: a media file must never gain
        // special bits through a writer round-trip.
        $originalMode = @fileperms($mediaPath);
        if ($originalMode !== false) {
            @chmod($staged, $originalMode & 0777);
        }

        if (!rename($staged, $mediaPath)) {
            throw EmbeddedWriteFailedException::publishFailed($item->id, $mediaPath);
        }
    }

    /**
     * Unique staged-sibling path, same pid+entropy discipline as
     * SidecarWriter::writeAtomically() (pids separate processes, uniqid
     * entropy separates calls within one) — and deliberately NO media
     * extension:
     *  - a concurrent scan can never pick the stage up as a media candidate
     *    (no supported extension), and the `.tmp` in the name additionally
     *    matches the shipped `scanner.ignore_patterns` substring rule, so even
     *    an operator-listed pattern cannot let it through unnoticed;
     *  - the muxer is therefore PINNED on the command line (`-f mp4` /
     *    `-f matroska`), not inferred from a filename — measured on the real
     *    binary both ways: extension-less output without `-f` fails with
     *    "Error initializing the muxer", with `-f` it writes a tagged file
     *    that ffprobe reads back (S345 rule 2 — no fake runner could have
     *    caught the first shape).
     *
     * The file is created HERE with `fopen(...,'xb')` — exclusive: O_EXCL
     * refuses to open through a pre-existing symlink or clobber a pre-existing
     * path, so neither `ffmpeg -y` nor `copy()` below can ever be aimed at an
     * object the writer did not just mint.
     */
    private function createStage(MediaItem $item, string $mediaPath): string
    {
        $stage = $mediaPath . '.phlix-embed.tmp.' . getmypid() . '-' . uniqid('', true);

        $handle = @fopen($stage, 'xb');
        if ($handle === false) {
            // 'xb' fails when the path exists (ANY type — file, link, fifo) or
            // the directory went unwritable after the pre-flight. Nothing was
            // created, so there is nothing to discard; the message names both
            // input classes instead of claiming one.
            throw EmbeddedWriteFailedException::stageFailed(
                $item->id,
                $mediaPath,
                'could not exclusively create the stage file (path already present or directory '
                    . 'unwritable mid-flight): ' . $stage,
            );
        }
        fclose($handle);

        return $stage;
    }

    private function discardStaged(string $staged): void
    {
        if (is_file($staged)) {
            @unlink($staged);
        }
    }

    // ── canonical metadata → container fields ─────────────────────────────

    /**
     * ffmpeg `-metadata` flags (video containers). Values are shell-escaped
     * HERE — nothing provider-derived reaches the command line unquoted.
     *
     * @param array<string, mixed> $canonicalMetadata
     *
     * @return list<string>
     */
    private function metadataFlags(array $canonicalMetadata): array
    {
        $fields = [
            'title' => MetadataValue::asString($canonicalMetadata['name'] ?? null),
            'date' => MetadataValue::asString($canonicalMetadata['year'] ?? null),
            'synopsis' => MetadataValue::asString($canonicalMetadata['overview'] ?? null),
            'rating' => $this->formatFloat(MetadataValue::asNullableFloat($canonicalMetadata['vote_average'] ?? null)),
            'genre' => implode(' / ', $this->stringList($canonicalMetadata['genres'] ?? null)),
        ];

        $flags = [];
        foreach ($fields as $key => $value) {
            if ($value !== '') {
                $flags[] = sprintf('-metadata %s=%s', $key, escapeshellarg($value));
            }
        }

        return $flags;
    }

    /**
     * getID3 tag_data (audio). Frame keys are the getID3 canonical upper-case
     * names; values must be arrays of strings.
     *
     * @param array<string, mixed> $canonicalMetadata
     *
     * @return array<string, list<string>>
     */
    private function audioTagData(array $canonicalMetadata): array
    {
        $data = [];

        $title = MetadataValue::asString($canonicalMetadata['name'] ?? null);
        if ($title !== '') {
            $data['TITLE'] = [$title];
        }

        $artist = MetadataValue::asString($canonicalMetadata['artist'] ?? null);
        if ($artist !== '') {
            $data['ARTIST'] = [$artist];
        }

        $album = MetadataValue::asString($canonicalMetadata['album'] ?? null);
        if ($album !== '') {
            $data['ALBUM'] = [$album];
        }

        $track = MetadataValue::asNullableInt($canonicalMetadata['track'] ?? null);
        if ($track !== null) {
            $data['TRACK_NUMBER'] = [(string) $track];
        }

        $year = MetadataValue::asString($canonicalMetadata['year'] ?? null);
        if ($year !== '') {
            $data['YEAR'] = [$year];
        }

        $genres = $this->stringList($canonicalMetadata['genres'] ?? null);
        if ($genres !== []) {
            $data['GENRE'] = $genres;
        }

        return $data;
    }

    /**
     * @param mixed $value raw canonical value
     *
     * @return list<string> non-empty strings only
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return is_string($value) && $value !== '' ? [$value] : [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function formatFloat(?float $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * Last ~400 chars of a process' stderr for the log line, on ONE line so a
     * multi-line ffmpeg banner cannot forge additional log records.
     */
    private function tail(string $stderr): string
    {
        $trimmed = trim($stderr);
        if ($trimmed === '') {
            return 'no stderr output';
        }

        $short = strlen($trimmed) > 400 ? substr($trimmed, -400) : $trimmed;

        return strtr($short, ["\r" => ' ', "\n" => ' ']);
    }
}
