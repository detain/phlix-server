<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Library;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Library\EpisodeFilenameParser;

class EpisodeFilenameParserTest extends TestCase
{
    /**
     * @dataProvider seasonEpisodeCases
     */
    public function testSeasonEpisodePatterns(
        string $filename,
        string $series,
        int $season,
        int $episode
    ): void {
        $result = EpisodeFilenameParser::parse($filename, false);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($series, $result['series']);
        $this->assertSame($season, $result['season']);
        $this->assertSame($episode, $result['episode']);
    }

    public function testEpisodeTitlePreservesMultibyteQuotesAsValidUtf8(): void
    {
        // Regression: the curly quotes “ (E2 80 9C) … ” (E2 80 9D) must survive
        // intact. The byte-wise trim mask " -–._\t" (containing the multibyte
        // en-dash) used to strip the E2/80 lead bytes off the leading curly
        // quote, leaving an invalid lone 0x9C that then failed to insert into a
        // utf8mb4 column with MySQL error 1366.
        $name = "Shameless S10E12 \u{201C}Gallavich!\u{201D}.mkv";
        $result = EpisodeFilenameParser::parse($name, false);

        $this->assertNotNull($result);
        $this->assertSame('Shameless', $result['series']);
        $this->assertSame(10, $result['season']);
        $this->assertSame(12, $result['episode']);
        $this->assertNotNull($result['episode_title']);
        $this->assertTrue(
            mb_check_encoding($result['episode_title'], 'UTF-8'),
            'episode title must be valid UTF-8'
        );
        $this->assertSame("\u{201C}Gallavich!\u{201D}", $result['episode_title']);
    }

    /** @return array<string, array{0:string,1:string,2:int,3:int}> */
    public static function seasonEpisodeCases(): array
    {
        return [
            'adjacent SxxExx'      => ['24 S01E01.mkv', '24', 1, 1],
            'spaced S## E##'       => ['ALIAS - S01 E17 - Q and A (720p - AMZN Web-DL).mp4', 'ALIAS', 1, 17],
            'S1EP17 compact'       => ['Family Ties S1EP17 1080p (moviesbyrizzo upl).mp4', 'Family Ties', 1, 17],
            'spaced cartoon'       => ['COW and CHICKEN - S04 E09 - Time Machine (480p).mp4', 'COW and CHICKEN', 4, 9],
            'episode range'        => ['Ed, Edd n Eddy - S05 E16-E17 - Tight End Ed (720p).mp4', 'Ed, Edd n Eddy', 5, 16],
            '1x02 style'           => ['Firefly - 1x02 - The Train Job.mkv', 'Firefly', 1, 2],
            'high season number'   => ['Ducktales - S01 E39 - Catch as Cash Can.mp4', 'Ducktales', 1, 39],
        ];
    }

    /**
     * @dataProvider absoluteCases
     */
    public function testAbsolutePatternsOnlyWhenAllowed(
        string $filename,
        string $series,
        int $episode
    ): void {
        // Absolute numbering must be OFF by default (movie-library safety)...
        $this->assertNull(EpisodeFilenameParser::parse($filename, false), "must not parse without allowAbsolute: {$filename}");
        // ...and ON for series libraries.
        $result = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($result, "should parse with allowAbsolute: {$filename}");
        $this->assertSame($series, $result['series']);
        $this->assertSame(1, $result['season'], 'absolute numbering buckets into season 1');
        $this->assertSame($episode, $result['episode']);
    }

    /** @return array<string, array{0:string,1:string,2:int}> */
    public static function absoluteCases(): array
    {
        return [
            'dash NNN + tags'   => ['Naruto Shippuden - 394 [720p] [Multi-Sub] [x265] [pseudo].mkv', 'Naruto Shippuden', 394],
            'dash zero-padded'  => ['Pokémon - 0153 - The Totodile Duel [480p] [x265].mkv', 'Pokémon', 153],
            'underscore anime'  => ['Trigun_-_18_(Dual Audio_10bit_DVD480p_x265).mkv', 'Trigun', 18],
            'underscore ranma'  => ['Ranma_½_-_098_(Dual Audio_10bit_BD720p_x265).mkv', 'Ranma ½', 98],
            'dash E-prefix'     => ['Fullmetal Alchemist Brotherhood - E29 v2 [1080p].mkv', 'Fullmetal Alchemist Brotherhood', 29],
            'space only'        => ['Bleach 125.mkv', 'Bleach', 125],
            'dash trailing'     => ['Bleach - 160 -.mkv', 'Bleach', 160],
            'versioned'         => ['Douluo Dalu - 184v2 (1080p) [Untouched].mp4', 'Douluo Dalu', 184],
            'group tag prefix'  => ['[Hatsuyuki] Some Show - 07 [BD] [480p].avi', 'Some Show', 7],
        ];
    }

    public function testEpisodeTitleExtraction(): void
    {
        $r = EpisodeFilenameParser::parse('ALIAS - S01 E17 - Q and A (720p - AMZN Web-DL).mp4', false);
        $this->assertNotNull($r);
        $this->assertSame('Q and A', $r['episode_title']);

        $r2 = EpisodeFilenameParser::parse('Pokémon - 0153 - The Totodile Duel [480p] [x265].mkv', true);
        $this->assertNotNull($r2);
        $this->assertSame('The Totodile Duel', $r2['episode_title']);
    }

    public function testNoTitleWhenAbsent(): void
    {
        $r = EpisodeFilenameParser::parse('Naruto Shippuden - 394 [720p] [x265].mkv', true);
        $this->assertNotNull($r);
        $this->assertNull($r['episode_title']);

        // Trailing "- 160 -" must not yield a numeric "title".
        $r2 = EpisodeFilenameParser::parse('Bleach - 160 -.mkv', true);
        $this->assertNotNull($r2);
        $this->assertNull($r2['episode_title']);
    }

    /**
     * @dataProvider nonEpisodeCases
     */
    public function testNonEpisodesReturnNull(string $filename): void
    {
        $this->assertNull(EpisodeFilenameParser::parse($filename, true), "must NOT parse as episode: {$filename}");
    }

    /** @return array<string, array{0:string}> */
    public static function nonEpisodeCases(): array
    {
        return [
            'movie no number'   => ['Animated Graphic Novel - Escape From The Spirit World (720p HDTV).mp4'],
            'plain title'       => ['Mulder Escapes From Car Wreck.mkv'],
            'the movie suffix'  => ['Ao no Exorcist - The Movie [BD] [480p].avi'],
        ];
    }

    public function testMovieWithYearNotEpisodeInMovieLibrary(): void
    {
        // allowAbsolute=false (movie library) — a trailing year must not become an episode.
        $this->assertNull(EpisodeFilenameParser::parse('Blade Runner 2049 (2017) [1080p].mkv', false));
    }
}
