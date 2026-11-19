<?php

/**
 * Phlix media server component: Fs.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Fs;

use Phlix\Common\Fs\Mime;
use Phlix\Dlna\DlnaMimeTypes;
use Phlix\Media\Storage\AvatarStorage;
use Phlix\Media\Storage\ImageResizer;
use Phlix\Server\Workerman\HttpHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * E-17 pin matrix: every value the four legacy MIME sites could ever answer,
 * frozen as literals, cross-checked against the consolidated table.
 *
 * The test runs on BOTH sides of the consolidation by design: it asks the
 * legacy surfaces (HttpHandler::mimeFor, DlnaMimeTypes::forPath, the two
 * getRealMime twins — reached by reflection, which is exactly how they were
 * exercised before) for their answer AND asks the shared table for its answer,
 * and pins both to the same literal. Green here pre-refactor records the
 * legacy matrix; green again after the sites delegate proves the refactor
 * moved storage without moving a single resolved value.
 *
 * The web/media view asymmetry is load-bearing and frozen BOTH ways: the same
 * extension that the static server types (avif, svg, fonts…) is the DLNA
 * surface's FALLBACK, and the containers DLNA direct-plays (mkv, mov, flac…)
 * are what the static server used to hand libmagic or the generic default.
 */
final class MimePinMatrixTest extends TestCase
{
    /** Missing-file path under an untouched directory: extension answers only. */
    private const NOWHERE = '/phlix-pin-matrix-nowhere';

    /**
     * The 30 web-view keys and their frozen Content-Type values, transcribed
     * verbatim from the legacy private table in HttpHandler::mimeFor().
     *
     * @return array<int, array{0: string}>
     */
    public static function webViewRows(): array
    {
        return [
            ['text/css; charset=utf-8'],
            ['application/javascript; charset=utf-8'],
            ['application/javascript; charset=utf-8'],
            ['application/json; charset=utf-8'],
            ['application/json; charset=utf-8'],
            ['text/html; charset=utf-8'],
            ['text/html; charset=utf-8'],
            ['text/plain; charset=utf-8'],
            ['application/xml; charset=utf-8'],
            ['image/svg+xml'],
            ['application/manifest+json'],
            ['image/x-icon'],
            ['image/png'],
            ['image/jpeg'],
            ['image/jpeg'],
            ['image/gif'],
            ['image/webp'],
            ['image/avif'],
            ['font/woff'],
            ['font/woff2'],
            ['font/ttf'],
            ['font/otf'],
            ['application/vnd.ms-fontobject'],
            ['audio/mpeg'],
            ['audio/mp4'],
            ['audio/ogg'],
            ['video/mp4'],
            ['video/webm'],
            ['application/pdf'],
            ['application/wasm'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function webViewProvider(): array
    {
        $extensions = [
            'css', 'js', 'mjs', 'json', 'map', 'html', 'htm', 'txt', 'xml', 'svg',
            'webmanifest', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif',
            'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'm4a', 'ogg', 'mp4',
            'webm', 'pdf', 'wasm',
        ];

        $rows = [];
        foreach ($extensions as $index => $ext) {
            $rows[$ext] = [$ext, self::webViewRows()[$index][0]];
        }

        return $rows;
    }

    /**
     * The 36 media-view keys and their frozen values, transcribed verbatim
     * from the legacy EXTENSION_MAP in DlnaMimeTypes.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function mediaViewProvider(): array
    {
        return [
            ['mp4', 'video/mp4'],
            ['m4v', 'video/mp4'],
            ['mkv', 'video/x-matroska'],
            ['webm', 'video/webm'],
            ['avi', 'video/x-msvideo'],
            ['mov', 'video/quicktime'],
            ['wmv', 'video/x-ms-wmv'],
            ['flv', 'video/x-flv'],
            ['mpg', 'video/mpeg'],
            ['mpeg', 'video/mpeg'],
            ['m2v', 'video/mpeg'],
            ['ts', 'video/mp2t'],
            ['m2ts', 'video/mp2t'],
            ['mts', 'video/mp2t'],
            ['3gp', 'video/3gpp'],
            ['ogv', 'video/ogg'],
            ['mp3', 'audio/mpeg'],
            ['m4a', 'audio/mp4'],
            ['m4b', 'audio/mp4'],
            ['aac', 'audio/aac'],
            ['flac', 'audio/flac'],
            ['wav', 'audio/wav'],
            ['ogg', 'audio/ogg'],
            ['oga', 'audio/ogg'],
            ['opus', 'audio/opus'],
            ['wma', 'audio/x-ms-wma'],
            ['aiff', 'audio/aiff'],
            ['aif', 'audio/aiff'],
            ['jpg', 'image/jpeg'],
            ['jpeg', 'image/jpeg'],
            ['png', 'image/png'],
            ['gif', 'image/gif'],
            ['bmp', 'image/bmp'],
            ['webp', 'image/webp'],
            ['tif', 'image/tiff'],
            ['tiff', 'image/tiff'],
        ];
    }

    /**
     * Sizes of the two legacy views, as a freeze of the transcription itself:
     * change a table and these constants must rotate together with the rows.
     */
    public function testTheTwoViewsHaveTheirFrozenCardinality(): void
    {
        $this->assertCount(30, self::webViewProvider(), 'web view: the HttpHandler table had exactly 30 keys');
        $this->assertCount(36, self::mediaViewProvider(), 'media view: the DLNA table had exactly 36 keys');
    }

    /**
     * Web leg, one per key: legacy mimeFor (pre-delegation, via reflection)
     * AND the consolidated answer agree with the literal.
     */
    public function testWebViewIsFrozenLegByLeg(): void
    {
        foreach (self::webViewProvider() as $ext => [$extName, $expected]) {
            $path = self::NOWHERE . '/pin.' . $ext;

            $this->assertSame(
                $expected,
                $this->legacyMimeFor($path),
                "legacy HttpHandler::mimeFor() answer for .$ext"
            );
            $this->assertSame(
                $expected,
                Mime::responseTypeForFile($path),
                "consolidated responseTypeForFile() answer for .$ext"
            );
            $this->assertSame(
                $extName,
                $ext,
                'provider self-discipline: key and value must carry the same extension'
            );
        }
    }

    /**
     * Media leg, one per key: legacy forPath AND the consolidated answer
     * agree with the literal.
     */
    public function testMediaViewIsFrozenLegByLeg(): void
    {
        foreach (self::mediaViewProvider() as [$ext, $expected]) {
            $path = self::NOWHERE . '/pin.' . $ext;

            $this->assertSame(
                $expected,
                DlnaMimeTypes::forPath($path),
                "legacy DlnaMimeTypes::forPath() answer for .$ext"
            );
            $this->assertSame(
                $expected,
                Mime::mediaTypeFromPath($path),
                "consolidated mediaTypeFromPath() answer for .$ext"
            );
        }
    }

    /**
     * The asymmetry arms, frozen BOTH ways (naive union would change one side):
     * web-only types are the media view's FALLBACK, and media-only containers
     * are the static server's sniff-gate/ generic default for missing files.
     */
    public function testViewDisagreementsAreFrozenBothWays(): void
    {
        // Web-only keys must NOT leak into the media view.
        $webOnlyKeys = [
            'avif', 'svg', 'css', 'js', 'json', 'html', 'woff2', 'pdf', 'wasm',
            'ico', 'webmanifest', 'eot', 'txt', 'xml', 'mjs', 'map', 'htm',
            'ttf', 'otf',
        ];
        foreach ($webOnlyKeys as $ext) {
            $path = self::NOWHERE . '/x.' . $ext;
            $this->assertSame(
                DlnaMimeTypes::FALLBACK,
                DlnaMimeTypes::forPath($path),
                "media view must keep answering FALLBACK for web-only .$ext"
            );
            $this->assertSame(
                Mime::FALLBACK,
                Mime::mediaTypeFromPath($path),
                "consolidated media view must keep answering FALLBACK for web-only .$ext"
            );
            $this->assertNull(
                Mime::forExtension($ext, null, true),
                "the seam itself must refuse web-only .$ext in the media view"
            );
        }

        // Media-only containers must NOT leak into the web table hit.
        foreach (['mkv', 'mov', 'avi', 'flac', 'bmp', 'm4v', 'wmv', 'flv', 'ts', 'opus'] as $ext) {
            $path = self::NOWHERE . '/x.' . $ext;
            $this->assertNull(
                Mime::webTypeFromPath($path),
                "web view must not table-hit media-only .$ext (legacy fell through to the sniff chain)"
            );
            // With the file missing, the legacy chain is deterministic: no
            // sniff (is_file guard) → generic default.
            $this->assertSame(
                'application/octet-stream',
                $this->legacyMimeFor($path),
                "legacy static answer for missing .$ext"
            );
            $this->assertSame(
                'application/octet-stream',
                Mime::responseTypeForFile($path),
                "consolidated static answer for missing .$ext"
            );
        }

        // The web answer for a table hit precedes the is_file() gate: an
        // avif with NO file on disk still types image/avif (legacy law).
        $this->assertSame('image/avif', $this->legacyMimeFor(self::NOWHERE . '/gone.avif'));
        $this->assertSame('image/avif', Mime::responseTypeForFile(self::NOWHERE . '/gone.avif'));
    }

    /**
     * Keys the two legacy tables SHARE resolved identically — that is what
     * lets one row carry both flags.
     */
    public function testSharedKeysResolveIdenticallyInBothViews(): void
    {
        $shared = ['mp4', 'webm', 'mp3', 'm4a', 'ogg', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($shared as $ext) {
            $path = self::NOWHERE . '/s.' . $ext;
            $this->assertSame(
                $this->legacyMimeFor($path),
                DlnaMimeTypes::forPath($path),
                "shared key .$ext must have had one value in both legacy tables"
            );
            $this->assertSame(
                Mime::responseTypeForFile($path),
                Mime::mediaTypeFromPath($path),
                "shared key .$ext must resolve identically through the consolidated views"
            );
        }
    }

    /**
     * Case-folding leg: the legacy sites lower-cased before lookup and so
     * must the consolidation, for both views.
     */
    public function testExtensionCaseFoldingIsPreserved(): void
    {
        $this->assertSame('image/png', Mime::responseTypeForFile(self::NOWHERE . '/UPPER.PNG'));
        $this->assertSame('image/png', $this->legacyMimeFor(self::NOWHERE . '/UPPER.PNG'));
        $this->assertSame('video/x-matroska', Mime::mediaTypeFromPath(self::NOWHERE . '/UPPER.MKV'));
        $this->assertSame('video/x-matroska', DlnaMimeTypes::forPath(self::NOWHERE . '/UPPER.MKV'));
    }

    /**
     * The finfo twins, pinned against the consolidated sniffer on the same
     * real inputs (a canonical minimal PNG planted under an unknown
     * extension, and a plain-text file). NOTE: like the legacy twins, the
     * sniffer is only ever reached for existing files — the server chain
     * gates on is_file() before it (pinned by the missing-file arm below),
     * because finfo_file() emits a host "Failed to open stream" warning on a
     * non-existent path and this repo's runner fails on warnings. Feeding a
     * missing path here would pin a shape no production caller can produce.
     */
    public function testSniffTwinsAndConsolidatedSnifferAnswerIdentically(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-mime-pins-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o777, true));

        $pngPath = $dir . '/planted.zzq';      // unknown ext, PNG magic
        $txtPath = $dir . '/planted.zztxt';    // unknown ext, text bytes

        try {
            $this->assertNotFalse(
                file_put_contents($pngPath, (string) base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                    true
                ))
            );
            $this->assertNotFalse(file_put_contents($txtPath, "plain text for the sniffer\n"));

            $avatar = new ReflectionMethod(AvatarStorage::class, 'getRealMime');
            $resizer = new ReflectionMethod(ImageResizer::class, 'getRealMime');
            $avatarTarget = (new ReflectionClass(AvatarStorage::class))->newInstanceWithoutConstructor();
            $resizerTarget = (new ReflectionClass(ImageResizer::class))->newInstanceWithoutConstructor();

            foreach ([$pngPath, $txtPath] as $path) {
                $twinA = $avatar->invoke($avatarTarget, $path);
                $twinB = $resizer->invoke($resizerTarget, $path);
                $consolidated = Mime::sniffFile($path);

                $this->assertSame($twinA, $twinB, "the two legacy twins must agree on $path");
                $this->assertSame($twinA, $consolidated, "the consolidated sniffer must match the twins on $path");
            }

            // The twins are content-based; a canonical PNG magic answers
            // image/png even under a made-up extension.
            $this->assertSame('image/png', Mime::sniffFile($pngPath));
        } finally {
            @unlink($pngPath);
            @unlink($txtPath);
            @rmdir($dir);
        }
    }

    /**
     * Static-server chain end-to-end for an unknown-extension REAL file:
     * no table hit → sniff → the libmagic answer (relationship-pinned: mime_
     * content_type and finfo are the same libmagic, so this is host-stable
     * without trusting a literal); and the same chain for a missing file
     * falls to FALLBACK without ever invoking the sniffer (is_file gate).
     */
    public function testResponseChainSniffArmAndMissingFileArm(): void
    {
        $dir = sys_get_temp_dir() . '/phlix-mime-chain-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o777, true));
        $pngPath = $dir . '/real.zzq';

        try {
            file_put_contents($pngPath, (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
                true
            ));

            $legacy = $this->legacyMimeFor($pngPath);
            $this->assertSame($legacy, Mime::responseTypeForFile($pngPath));
            $this->assertSame('image/png', $legacy, 'chain leg: unknown ext + PNG magic answers image/png');

            $missing = $dir . '/gone.zzzq';
            $this->assertSame('application/octet-stream', $this->legacyMimeFor($missing));
            $this->assertSame('application/octet-stream', Mime::responseTypeForFile($missing));
        } finally {
            @unlink($pngPath);
            @rmdir($dir);
        }
    }

    /**
     * The one-line law, planted: a synthetic single row (mime + both flags)
     * feeds BOTH views through the pure seam — and the default table carries
     * no such key before the row exists (no secret second copy).
     */
    public function testOneRowFeedsBothViews(): void
    {
        $oneRow = ['xqz' => ['mime' => 'video/x-qz', 'web' => true, 'media' => true]];

        $this->assertSame('video/x-qz', Mime::forExtension('xqz', $oneRow, false));
        $this->assertSame('video/x-qz', Mime::forExtension('xqz', $oneRow, true));

        // Flag-selective row: a single line still decides view membership.
        $webOnly = ['xqz' => ['mime' => 'video/x-qz', 'web' => true, 'media' => false]];
        $this->assertSame('video/x-qz', Mime::forExtension('xqz', $webOnly, false));
        $this->assertNull(Mime::forExtension('xqz', $webOnly, true));

        // Negative control on the real table: the probe key exists nowhere.
        $this->assertNull(Mime::forExtension('xqz'));
        $this->assertNull(Mime::forExtension('xqz', null, true));
    }

    /**
     * Table hygiene: every canonical row is exactly {mime, web, media}, the
     * mime is a non-empty lowercase token, at least one view admits it (a
     * no-flag row is dead weight masquerading as a mapping), and the union of
     * the two view-sets is exactly the transcribed 56-key pin domain.
     */
    public function testTableHygieneAndDomainIdentity(): void
    {
        $table = (new ReflectionClass(Mime::class))->getConstant('EXTENSIONS');
        $this->assertIsArray($table);

        $webKeys = [];
        $mediaKeys = [];
        foreach ($table as $ext => $row) {
            $this->assertSame(
                ['mime', 'web', 'media'],
                array_keys($row),
                "row .$ext must be exactly {mime, web, media}"
            );
            $this->assertIsString($row['mime']);
            $this->assertNotSame('', $row['mime']);
            $this->assertIsBool($row['web']);
            $this->assertIsBool($row['media']);
            $this->assertTrue($row['web'] || $row['media'], "row .$ext must be admitted by at least one view");
            $this->assertSame(strtolower($ext), $ext, 'table keys are stored lower-case');
            if ($row['web']) {
                $webKeys[] = $ext;
            }
            if ($row['media']) {
                $mediaKeys[] = $ext;
            }
        }

        $this->assertEqualsCanonicalizing(
            array_keys(self::webViewProvider()),
            $webKeys,
            'the web view of the canonical table must be exactly the frozen 30-key domain'
        );
        $this->assertEqualsCanonicalizing(
            array_column(self::mediaViewProvider(), 0),
            $mediaKeys,
            'the media view of the canonical table must be exactly the frozen 36-key domain'
        );
    }

    /**
     * Call the private static HttpHandler::mimeFor() exactly as the server
     * does (pre- and post-delegation alike — the signature never moves).
     */
    private function legacyMimeFor(string $path): string
    {
        $answer = (new ReflectionMethod(HttpHandler::class, 'mimeFor'))->invoke(null, $path);
        $this->assertIsString($answer);

        return $answer;
    }
}
