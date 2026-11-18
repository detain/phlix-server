<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Streaming\Trickplay;

use Phlix\Media\Streaming\Trickplay\TrickplayArtefacts;
use Phlix\Media\Transcoding\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/**
 * config/trickplay.php says its tuning values are constants, and names them. This
 * test keeps that statement true: every constant the header names must exist and
 * still hold the value the header documents. It also keeps the file to the keys
 * code actually reads, so a dead key cannot quietly come back.
 */
final class TrickplayConfigDocumentationTest extends TestCase
{
    private const CONFIG_PATH = __DIR__ . '/../../../../../config/trickplay.php';

    /**
     * Every constant the header names, with the value the header states.
     *
     * @var array<string, int>
     */
    private const DOCUMENTED = [
        'TrickplayArtefacts::SPRITE_COUNT' => 60,
        'TrickplayArtefacts::BIF_TARGET_INTERVAL_SECONDS' => 10,
        'TrickplayArtefacts::BIF_MAX_FRAMES' => 600,
        'TrickplayArtefacts::BIF_FRAME_WIDTH' => 320,
        'FfmpegRunner::TRICKPLAY_SPRITE_COLUMNS' => 6,
        'FfmpegRunner::TRICKPLAY_THUMB_WIDTH' => 160,
        'FfmpegRunner::TRICKPLAY_THUMB_HEIGHT' => 90,
        'FfmpegRunner::TRICKPLAY_MARGIN' => 2,
        'FfmpegRunner::TRICKPLAY_PADDING' => 1,
        'FfmpegRunner::TRICKPLAY_DECODE_THREADS' => 4,
        'FfmpegRunner::TRICKPLAY_NICE_LEVEL' => 19,
    ];

    private const OWNERS = [
        'TrickplayArtefacts' => TrickplayArtefacts::class,
        'FfmpegRunner' => FfmpegRunner::class,
    ];

    public function testTheFileCarriesOnlyTheKeysCodeReads(): void
    {
        /** @var array<string, mixed> $config */
        $config = require self::CONFIG_PATH;

        $this->assertSame(
            ['enabled', 'base_url'],
            array_keys($config),
            'A tuning key here would be dead: the values live in code constants, not config.'
        );
    }

    public function testEveryConstantTheHeaderNamesExistsWithTheDocumentedValue(): void
    {
        $header = (string) file_get_contents(self::CONFIG_PATH);
        preg_match_all('/`((?:TrickplayArtefacts|FfmpegRunner)::[A-Z_]+)`/', $header, $matches);
        $named = array_values(array_unique($matches[1]));
        sort($named);

        $documented = array_keys(self::DOCUMENTED);
        sort($documented);
        $this->assertSame($documented, $named, 'The header names a constant this test does not check, or vice versa.');

        foreach (self::DOCUMENTED as $reference => $expected) {
            [$owner, $constant] = explode('::', $reference, 2);
            $class = self::OWNERS[$owner];

            $actual = (new \ReflectionClassConstant($class, $constant))->getValue();
            $this->assertSame(
                $expected,
                $actual,
                $reference . ' no longer holds the value config/trickplay.php documents.'
            );
        }
    }
}
