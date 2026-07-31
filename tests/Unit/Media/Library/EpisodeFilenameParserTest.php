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
            // Series titles containing a dot: a blind pathinfo() strip used to
            // truncate at the FIRST dot ("Dr. Stone …" → "Dr"), losing the
            // SxxExx marker so the file mis-filed as a movie.
            'dotted title'         => ['Dr. Stone S01E05 [1080p] Stone World the Beginning.mkv', 'Dr. Stone', 1, 5],
            'dotted no ext'        => ['Dr. STONE S02E01.mp4', 'Dr. STONE', 2, 1],
            'dotted hyphen title'  => ['D.Gray-man S01E07 [480p] Tombstone of Memories.mkv', 'D.Gray-man', 1, 7],
        ];
    }

    /**
     * The scanner strips the extension before calling parse(); parse() must not
     * strip a SECOND time. For a dotted series title that double-strip would cut
     * at the title's dot ("Dr. Stone S01E05 …" → "Dr"), dropping the SxxExx
     * marker — the bug that left Dr. Stone / D.Gray-man / Gangsta. episodes
     * scattered as loose movies instead of grouped under one series.
     */
    public function testIdempotentForAlreadyStrippedDottedTitle(): void
    {
        $alreadyStripped = 'Dr. Stone S01E05 [1080p] Stone World the Beginning';
        $result = EpisodeFilenameParser::parse($alreadyStripped, true);

        $this->assertNotNull($result, 'pre-stripped dotted name must still parse');
        $this->assertSame('Dr. Stone', $result['series']);
        $this->assertSame(1, $result['season']);
        $this->assertSame(5, $result['episode']);
    }

    /**
     * A non-media trailing token after a dot is NOT an extension and must be
     * preserved (only recognised media containers are stripped).
     */
    public function testDoesNotStripNonMediaTrailingToken(): void
    {
        $result = EpisodeFilenameParser::parse('Gangsta. S01E03', true);
        $this->assertNotNull($result);
        $this->assertSame(1, $result['season']);
        $this->assertSame(3, $result['episode']);
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
     * SM-0.2 — the title that follows a quality tag must survive.
     *
     * `Show SxxEyy [480p] Title` is the dominant convention in the reference
     * library: 501 of the 1,328 episodes that have NO title at all carry one in
     * exactly this shape, and the old first-bracket cut deleted every one of
     * them. Every filename below is a real prod basename.
     *
     * @dataProvider titleAfterQualityTagCases
     */
    public function testTitleSurvivesAQualityTagThatPrecedesIt(
        string $filename,
        string $expected
    ): void {
        $result = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($expected, $result['episode_title']);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function titleAfterQualityTagCases(): array
    {
        return [
            'tv 480p'            => ['Knight Rider S02E23 [480p] Let It Be Me.mkv', 'Let It Be Me'],
            'anime 480p'         => ["InuYasha S06E23 [480p] Miroku's Past Mistake.mkv", "Miroku's Past Mistake"],
            'apostrophe'         => [
                "Battlestar Galactica (2003) S02E17 [720p] The Captain's Hand.mkv",
                "The Captain's Hand",
            ],
            'punctuation'        => ['Turn A Gundam S01E31 [720p] Pursuit! Crybaby Poe.mkv', 'Pursuit! Crybaby Poe'],
            'digits inside'      => [
                'InuYasha S03E06 [480p] The 50 Year-Old Curse of the Dark Priestess.mkv',
                'The 50 Year-Old Curse of the Dark Priestess',
            ],
            // Two tags around the title — both groups go, the text between stays.
            'tag on both sides'  => ['Show S01E02 [720p] Real Title [x265].mkv', 'Real Title'],
            // Fullwidth brackets are a group too (the ASCII-only patterns miss them).
            'fullwidth bracket'  => ["Show S01E03 \u{3010}720p\u{3011} Real Title.mkv", 'Real Title'],
            // The series segment keeps its own first-bracket cut (see cleanSeries).
            'paren year series'  => [
                'The Outer Limits (1995) S01E22 [240p] The Voice of Reason.avi',
                'The Voice of Reason',
            ],
        ];
    }

    /**
     * A dot-separated scene name keeps its title and loses only the trailing
     * release run — and the kept prefix keeps its ORIGINAL separators, so an
     * abbreviation ("Mr.Monk") is never rewritten into spaces.
     *
     * @dataProvider sceneRunCases
     */
    public function testTrailingReleaseRunIsCutWithoutRewritingSeparators(
        string $filename,
        ?string $expected
    ): void {
        $result = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($expected, $result['episode_title']);
    }

    /** @return array<string, array{0:string,1:?string}> */
    public static function sceneRunCases(): array
    {
        return [
            'dotted title'      => ['The.Office.US.S03E14.Ben.Franklin.720p.WEBRip.2CH.x265.HEVC-PSA', 'Ben.Franklin'],
            'abbreviation kept' => ['Monk.S07E01.Mr.Monk.Buys.a.House.720p.WEB-DL.DD5.1.h.264-TjHD', 'Mr.Monk.Buys.a.House'],
            'group suffix'      => ['MacGyver.S04E18.Renegade.DVDRip', 'Renegade'],
            // Release run AFTER a bracket group — reachable only because the tag
            // strip runs before the truncation.
            'run after a group' => [
                'Elementary (2012) - S01E07 - One Way to Get Off (1080p AMZN WEB-DL x265 RZeroX) REPACK',
                'One Way to Get Off',
            ],
            // Nothing but release junk → no title at all (today this stores
            // "720p.AMZN.WEBRip.x264-GalaxyTV" as the episode title on 472 rows).
            'pure junk'         => ['House.S08E07.720p.AMZN.WEBRip.x264-GalaxyTV', null],
            'bare resolution'   => ['Family Ties S1EP17 1080p (moviesbyrizzo upl).mp4', null],
            // 70 prod rows are literally NAMED "v2" because of this shape.
            'revision tag'      => ['Fullmetal Alchemist Brotherhood - E19 v2 [1080p][x265].mkv', null],
        ];
    }

    /**
     * A multi-episode range leaves the second marker glued to the front of the
     * remainder ("S04E01-E02 …" → "-E02 …"). That is not a title.
     */
    public function testMultiEpisodeRangeContinuationIsNotPartOfTheTitle(): void
    {
        $r = EpisodeFilenameParser::parse('Star Trek Deep Space Nine S04E01-E02 [576p] The Way of the Warrior.mkv', true);
        $this->assertNotNull($r);
        $this->assertSame('The Way of the Warrior', $r['episode_title']);

        $r2 = EpisodeFilenameParser::parse('Burn Notice S06E17-E18 You Can Run & Game Change.mkv', true);
        $this->assertNotNull($r2);
        $this->assertSame('You Can Run & Game Change', $r2['episode_title']);
    }

    /**
     * The range strip requires the TIGHT form (dash glued to the marker), so a
     * real title that merely begins with a number survives untouched.
     */
    public function testSpacedSeparatorBeforeANumericTitleIsNotARangeMarker(): void
    {
        $r = EpisodeFilenameParser::parse("Family Guy - S20E04 - 80's Guy.mkv", true);
        $this->assertNotNull($r);
        $this->assertSame("80's Guy", $r['episode_title']);

        $r2 = EpisodeFilenameParser::parse('Family Guy - S04E08 - 8 Simple Rules for Buying my Teenage Daughter.mkv', true);
        $this->assertNotNull($r2);
        $this->assertSame('8 Simple Rules for Buying my Teenage Daughter', $r2['episode_title']);
    }

    /**
     * A trailing part marker is TMDB's own spelling ("Kobol's Last Gleaming (2)")
     * and is what keeps two halves of a two-parter from collapsing into one
     * title. Measured: dropping it collided 20 rows into 10 duplicate pairs.
     *
     * @dataProvider partMarkerCases
     */
    public function testTrailingPartMarkerIsPreserved(string $filename, string $expected): void
    {
        $result = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($expected, $result['episode_title']);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function partMarkerCases(): array
    {
        return [
            'bare (n)'          => [
                "Battlestar Galactica (2003) S01E13 [720p] Kobol's Last Gleaming (2).mkv",
                "Kobol's Last Gleaming (2)",
            ],
            'sibling half one'  => [
                'Battlestar Galactica (2003) S03E19 [720p] Crossroads (1).mkv',
                'Crossroads (1)',
            ],
            'then a tag group'  => [
                'Married... with Children (1987) - S09E18 - Ship Happens (1) (480p DVD x265 Silence).mkv',
                'Ship Happens (1)',
            ],
            'Part n spelling'   => [
                'King of the Hill - S04E14 - High Anxiety (Part 2) [576p] [x265] [pseudo].mkv',
                'High Anxiety (Part 2)',
            ],
        ];
    }

    /**
     * A part marker must never be promoted on its own: with no title text left,
     * the result is null, not " (2)".
     */
    public function testPartMarkerAloneIsNotATitle(): void
    {
        $r = EpisodeFilenameParser::parse('Show S01E05 [720p] (2).mkv', true);
        $this->assertNotNull($r);
        $this->assertNull($r['episode_title']);
    }

    /**
     * 🔴 The narrow release list exists BECAUSE the broad one is wrong.
     * `SceneFilenameNormalizer::QUALITY_TOKENS` contains FINAL / MA / DVD / FIX /
     * LIMITED / EXTENDED / THEATRICAL — ordinary English words. Applying it to an
     * episode title (which is a sentence, not a movie name) corrupts 86 genuine
     * titles in the reference library to clean up 81 junk ones. These are all
     * real prod titles.
     *
     * @dataProvider englishWordsThatLookLikeTagsCases
     */
    public function testEnglishWordsSharedWithQualityTokensAreNotStripped(
        string $filename,
        string $expected
    ): void {
        $result = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($expected, $result['episode_title']);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function englishWordsThatLookLikeTagsCases(): array
    {
        return [
            'final'     => ['Show S01E01 [480p] And the Final Curtain.mkv', 'And the Final Curtain'],
            'dvd'       => ['Show S01E02 [480p] In a DVD Factory.mkv', 'In a DVD Factory'],
            'fix'       => ['Show S01E03 [480p] The Fix-Up.mkv', 'The Fix-Up'],
            'ma'        => ['Show S01E04 [480p] Dear Ma.mkv', 'Dear Ma'],
            'limited'   => ['Show S01E05 [480p] The Limited.mkv', 'The Limited'],
            'extended'  => ['Show S01E06 [480p] Original Extended Broadcast Pilot.mkv', 'Original Extended Broadcast Pilot'],
            'web'       => ['Star Trek S03E09 [360p] The Tholian Web.mkv', 'The Tholian Web'],
            'web again' => ['Overlord S03E07 [1080p] Butterfly Entangled in a Spider\'s Web.mkv', 'Butterfly Entangled in a Spider\'s Web'],
        ];
    }

    /**
     * A CRC32 stamp is dropped, but the rule requires a DIGIT so an eight-letter
     * word that happens to be spelled from a-f is never mistaken for one.
     */
    public function testCrcStampIsDroppedButAnAllLetterHexWordSurvives(): void
    {
        $r = EpisodeFilenameParser::parse('Log Horizon - S02E13 a Sweet Trap [8BF54CFC].mkv', true);
        $this->assertNotNull($r);
        $this->assertSame('a Sweet Trap', $r['episode_title']);

        $r2 = EpisodeFilenameParser::parse('Show S01E01 [480p] Deadface.mkv', true);
        $this->assertNotNull($r2);
        $this->assertSame('Deadface', $r2['episode_title']);
    }

    /**
     * An episode named after its own show is a real convention (17 prod rows —
     * "Dexter" S01E01, "Goblin Slayer" S01E02, "The Sopranos" S01E01). A
     * "reject a title that echoes the series name" guard was measured and
     * REJECTED: it would have discarded 16 genuine titles to catch 1 junk one.
     */
    public function testTitleEqualToTheSeriesNameIsStillATitle(): void
    {
        $r = EpisodeFilenameParser::parse('Goblin Slayer S01E02 [720p] Goblin Slayer.mkv', true);
        $this->assertNotNull($r);
        $this->assertSame('Goblin Slayer', $r['series']);
        $this->assertSame('Goblin Slayer', $r['episode_title']);
    }

    /** A non-Latin title is kept verbatim. */
    public function testNonLatinTitleIsKept(): void
    {
        $r = EpisodeFilenameParser::parse("Show S01E07 [720p] \u{541B}\u{306E}\u{540D}\u{306F}.mkv", true);
        $this->assertNotNull($r);
        $this->assertSame("\u{541B}\u{306E}\u{540D}\u{306F}", $r['episode_title']);
    }

    /** A marker with no free text after it yields no title. */
    public function testRangeMarkerWithNoTitleYieldsNull(): void
    {
        $r = EpisodeFilenameParser::parse('Seinfeld S07E14-E15 [720p].mkv', true);
        $this->assertNotNull($r);
        $this->assertNull($r['episode_title']);
    }

    /**
     * 🔴 REGRESSION PIN — do not "clean up" short or punctuation-only titles.
     *
     * A `/\p{L}\p{L}/u` "must contain a word" guard was written for this method
     * and DELETED after measurement: it rejects 88 real files and all 88 are
     * genuine episode titles, 21 of which the previous parser already returned.
     * Every filename below is a real prod basename; the expected value is the
     * real episode title.
     *
     * @dataProvider shortTitleCases
     */
    public function testShortAndPunctuationOnlyTitlesAreKept(string $filename, string $expected): void
    {
        $result = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($expected, $result['episode_title']);
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function shortTitleCases(): array
    {
        return [
            'dotted initialism'  => ['Gargoyles - S02 E29 - M.I.A. (480p - DVDRip).mp4', 'M.I.A'],
            'long initialism'    => ['Beyond S02E09 [720p] F.G.B..mkv', 'F.G.B'],
            'ampersand'          => ['Homeland (2011) - S02E05 - Q&A (1080p BluRay x265 Silence).mkv', 'Q&A'],
            'roman numeral'      => ['Black Sails S02E02 X.mkv', 'X'],
            'digits and dots'    => ['Warehouse 13 S02E05 [1080p] 13.1.mkv', '13.1'],
            'digits and comma'   => ['Person of Interest S05E04 [720p] 6,741.mkv', '6,741'],
            'digits and dashes'  => ["Marvel's Agents of S.H.I.E.L.D. S01E02 [1080p] 0-8-4.mkv", '0-8-4'],
            'version-like title' => ['Nikita S01E02 [720p] 2.0.mkv', '2.0'],
            'letter plus digit'  => ['Star Trek Voyager S07E19 [576p] Q2.mkv', 'Q2'],
            'clock range'        => ['24 S01E15 [1080p] 200 P.M. - 300 P.M..mkv', '200 P.M. - 300 P.M'],
        ];
    }

    /**
     * The pre-existing bare-ordinal guard still fires: a two-digit multi-episode
     * range continuation that is not in the tight "-Enn" form ("S06E04-05")
     * leaves a bare "05", which is not a title.
     */
    public function testBareOrdinalResidueIsNotATitle(): void
    {
        $r = EpisodeFilenameParser::parse('The.Office.US.S06E04-05.1080p.NF.WEB.x264-MRSK.mkv', true);
        $this->assertNotNull($r);
        $this->assertNull($r['episode_title']);
    }

    /**
     * ⚠ KNOWN, MEASURED LIMIT — carried over unchanged from before SM-0.2, NOT
     * introduced by it.
     *
     * The bare-ordinal guard also refuses episodes whose real title IS a number:
     * Stargate SG-1 "200"/"1969"/"2010", Battlestar Galactica "33", Loki "1893",
     * Heroes "1961", Family Guy "420", X-Files "3", Daredevil ".380". Measured
     * over the reference library the guard changes 25 files, of which 22 are
     * genuine titles like these and only 3 are range fragments.
     *
     * SM-0.2 deliberately does NOT flip it: all 25 rows already carry a provider
     * title, so nothing is recovered by relaxing it today, and the interaction
     * with the absolute-numbering rules ("Show - 394 - 395") is unmeasured. It
     * needs its own step. This test exists so the limit is visible rather than
     * folklore.
     */
    public function testNumericEpisodeTitlesAreRefusedByTheBareOrdinalGuard(): void
    {
        $r = EpisodeFilenameParser::parse('Stargate SG-1 S10E06 [1080p] 200.mkv', true);
        $this->assertNotNull($r);
        $this->assertNull($r['episode_title'], 'documented limit: a numeric episode title is refused');

        $r2 = EpisodeFilenameParser::parse('Battlestar Galactica (2003) S01E01 [720p] 33.mkv', true);
        $this->assertNotNull($r2);
        $this->assertNull($r2['episode_title']);
    }

    /**
     * A release token fused to its group with a dash ("DVDRip-TV-Series") is
     * still a release token: the head before the first "-" is tested too. Real
     * prod basename — the only file in the reference library where this is the
     * difference between a title and a junk run.
     */
    public function testReleaseTokenFusedToItsGroupSuffixStillTruncates(): void
    {
        $r = EpisodeFilenameParser::parse('MacGyver.S01E01.Pilot.DVDRip-TV-Series No.001 S1E01 Pilot.avi', true);
        $this->assertNotNull($r);
        $this->assertSame('Pilot', $r['episode_title']);
    }

    /**
     * A title may never be emitted as invalid UTF-8 — `media_items` is utf8mb4
     * and a stray byte fails the insert with MySQL error 1366.
     */
    public function testInvalidUtf8NameNeverYieldsAnInvalidTitle(): void
    {
        $r = EpisodeFilenameParser::parse("Show S01E01 [480p] Caf\xE9 Noir", true);
        $this->assertNotNull($r);
        if ($r['episode_title'] !== null) {
            $this->assertTrue(mb_check_encoding($r['episode_title'], 'UTF-8'));
        }
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

    /**
     * Trailing edition/noise phrases on the SERIES segment must be peeled (via
     * the shared TitleSuffixStripper) so the show title matches metadata cleanly.
     *
     * @dataProvider seriesNoiseCases
     */
    public function testSeriesNoiseSuffixesStripped(
        string $filename,
        string $expectedSeries,
        bool $allowAbsolute
    ): void {
        $result = EpisodeFilenameParser::parse($filename, $allowAbsolute);
        $this->assertNotNull($result, "should parse: {$filename}");
        $this->assertSame($expectedSeries, $result['series']);
    }

    /** @return array<string, array{0:string,1:string,2:bool}> */
    public static function seriesNoiseCases(): array
    {
        return [
            // SxxExx series segment carrying an edition suffix.
            'directors cut series' => ['Highlander Directors Cut S01E02.mkv', 'Highlander', false],
            'extended series'      => ['Foo Extended S02E03.mkv', 'Foo', false],
            'yify series'          => ['Bar YIFY S01E01.mkv', 'Bar', false],
            // Absolute-numbered anime carrying noise on the title segment.
            'remastered absolute'  => ['Baz Remastered - 012 [720p].mkv', 'Baz', true],
            // STACKED noise tokens (with a dash separator) on the series segment —
            // all peel before the SxxExx marker is reached.
            'stacked sep series'   => ['Highlander - Uncut Remastered Directors Cut S03E04.mkv', 'Highlander', false],
        ];
    }

    /**
     * A series whose title legitimately CONTAINS a noise word mid-title (not as a
     * trailing word-boundary phrase) is left intact when there is no trailing
     * edition phrase to peel.
     */
    public function testSeriesTitleContainingNoiseWordLeftIntact(): void
    {
        $r = EpisodeFilenameParser::parse('Uncut Gems S01E01.mkv', false);
        $this->assertNotNull($r);
        $this->assertSame('Uncut Gems', $r['series']);
    }

    /**
     * The episode TITLE must keep all its words — TitleSuffixStripper is applied
     * only to the series segment, never to the episode title.
     */
    public function testEpisodeTitleNotNoiseStripped(): void
    {
        $r = EpisodeFilenameParser::parse('Show S01E05 - The Extended Mix.mkv', false);
        $this->assertNotNull($r);
        $this->assertSame('Show', $r['series']);
        $this->assertSame('The Extended Mix', $r['episode_title']);
    }

    /**
     * A series literally named after a single-token noise word must NOT be
     * emptied (the TitleSuffixStripper default never empties a title).
     */
    public function testSeriesNamedAfterNoiseTokenSurvives(): void
    {
        $r = EpisodeFilenameParser::parse('DC S01E01.mkv', false);
        $this->assertNotNull($r);
        $this->assertSame('DC', $r['series']);
    }

    /**
     * Step 13.3: an injected (admin-extended) noise list strips a CUSTOM phrase
     * from the SERIES segment that is not in the built-in const.
     */
    public function testInjectedCustomSuffixStripsFromSeriesSegment(): void
    {
        $custom = ['fan edit', 'remux'];
        $r = EpisodeFilenameParser::parse('Highlander Fan Edit S03E04.mkv', false, $custom);
        $this->assertNotNull($r);
        $this->assertSame('Highlander', $r['series']);
    }

    /**
     * Step 13.3: a null/empty injected list falls back to the built-in const, so
     * the canonical series-segment phrases still strip.
     */
    public function testInjectedEmptyListFallsBackToConstForSeries(): void
    {
        $r = EpisodeFilenameParser::parse('Highlander Directors Cut S03E04.mkv', false, []);
        $this->assertNotNull($r);
        $this->assertSame('Highlander', $r['series']);
    }

    /**
     * Step 13.3 (replace-not-merge semantics, end-to-end through the series path):
     * when a non-empty custom override is injected, ONLY its phrases peel — a
     * built-in const phrase the override omits ("Directors Cut") is NOT stripped
     * from the series segment, while the override's own phrase ("fan edit") is.
     * This pins that the injected list REPLACES the const rather than merging.
     */
    public function testInjectedCustomOverrideDoesNotStripBuiltinFromSeries(): void
    {
        $custom = ['fan edit'];

        // "Directors Cut" is a built-in const phrase but NOT in the override → kept.
        $kept = EpisodeFilenameParser::parse('Highlander Directors Cut S03E04.mkv', false, $custom);
        $this->assertNotNull($kept);
        $this->assertSame('Highlander Directors Cut', $kept['series']);

        // The override's own custom phrase still peels from the series segment.
        $stripped = EpisodeFilenameParser::parse('Highlander Fan Edit S03E04.mkv', false, $custom);
        $this->assertNotNull($stripped);
        $this->assertSame('Highlander', $stripped['series']);
    }

    // ------------------------------------------------------------------
    // SM-0.2 reviewer findings F1-F4. Every filename below is either a real
    // prod basename or the exact shape a finding named.
    // ------------------------------------------------------------------

    /**
     * F1. A release-group suffix that a removed tag group leaves behind is no
     * longer promoted to a title. These three were STRICTLY WORSE than the
     * pre-change parser, which returned null for all of them.
     *
     * @dataProvider groupSuffixCases
     */
    public function testAReleaseGroupSuffixIsNeverPromotedToATitle(string $filename): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertNull($r['episode_title'], "junk promoted from: {$filename}");
    }

    /** @return array<string, array{0: string}> */
    public static function groupSuffixCases(): array
    {
        return [
            'dash-glued to the closer'   => ['Show S01E01 [1080p]-RARBG.mkv'],
            'dash-led after a space'     => ['Show S01E01 [1080p] -GalaxyTV.mkv'],
            'named group after a paren'  => ['Show S01E01 (1080p) YIFY.mkv'],
            'named group, no list entry' => ['Show S01E01 [720p] x264-MRSK.mkv'],
            'tilde residue'              => ['Show S01E01 [720p] ~.mkv'],
            'ampersand residue'          => ['Show S01E01 [720p] &.mkv'],
            'comma residue'              => ['Show S01E01 [720p] , .mkv'],
            'quote residue'              => ["Show S01E01 [720p] ''.mkv"],
        ];
    }

    /**
     * F1. The live evidence: 8 prod rows produced the title "INTERNAL" because
     * the cut started at "1080p" and left the scene's own prefix marker behind.
     * "INTERNAL"/"WEB"/"NF"/"AMZN" are absorbed into the run instead.
     *
     * @dataProvider wholeSceneRunCases
     */
    public function testAWholeSceneRunLeavesNoResidue(string $filename): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertNull($r['episode_title'], "residue from: {$filename}");
    }

    /** @return array<string, array{0: string}> */
    public static function wholeSceneRunCases(): array
    {
        return [
            // Real prod basename (The Witcher US, 8 rows).
            'INTERNAL prefix' => ['The.Witcher.US.S01E01.INTERNAL.1080p.WEB.x264-STRiFE.mkv'],
            'WEB prefix'      => ['Show.S01E01.WEB.H264-EDITH.mkv'],
            'NF + WEB'        => ['Show.S01E01.NF.WEB.x264-MRSK.mkv'],
            'AMZN + WEB + DL' => ['Show.S01E01.AMZN.WEB.DL.x264-FLUX.mkv'],
            'PROPER prefix'   => ['Show.S01E01.PROPER.1080p.WEB.x264-GRP.mkv'],
            'REPACK prefix'   => ['Show.S01E01.REPACK.1080p.WEB.x264-GRP.mkv'],
            'HD after a res'  => ['Show.S01E01.720p.HD.mkv'],
            // Real prod basename (Shameless US).
            'bare tag run'    => ['Shameless.US.S05E03.1080p.Bluray.x265.HEVC.5.1[fs87].mkv'],
        ];
    }

    /**
     * F1. The one genuine per-row regression the reviewer found: a duplicate-file
     * digit welded to the closing paren ended up welded to the title.
     * Real prod basename.
     */
    public function testADigitGluedToAClosingParenIsNotWeldedToTheTitle(): void
    {
        $r = EpisodeFilenameParser::parse(
            'The Boondocks (2005) - S03E06 - Smokin With Cigarettes (1080p HMAX WEB-DL x265 YOGI)1.mkv',
            true
        );
        $this->assertNotNull($r);
        $this->assertSame('Smokin With Cigarettes', $r['episode_title']);

        // Same duplicate-file artefact spelled with square brackets. The glued
        // chunk has to stay part of the group's token: split off on its own it is
        // a digits-only token, which is title text and breaks the run.
        $square = EpisodeFilenameParser::parse('Show S01E01 Smokin With Cigarettes [1080p]1.mkv', true);
        $this->assertNotNull($square);
        $this->assertSame('Smokin With Cigarettes', $square['episode_title']);
    }

    /**
     * F2. SM-0.1's balanced-bracket guarantee reaches the episode path too:
     * `repairBracketBalance()` runs immediately AFTER `stripBracketedTags()`,
     * never before, exactly as `normalize()` does at every other call site.
     *
     * @dataProvider orphanBracketCases
     */
    public function testAnOrphanBracketNeverReachesTheEpisodeTitle(
        string $filename,
        ?string $expected
    ): void {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function orphanBracketCases(): array
    {
        return [
            'trailing orphan opener'   => ['Show S01E01 Title [720p.mkv', 'Title'],
            'trailing orphan paren'    => ['Show S01E01 Title (1080p.mkv', 'Title'],
            'leading orphan opener'    => ['Show S01E01 [720p Title.mkv', null],
            'fullwidth orphan opener'  => ["Show S01E01 \u{3010}720p Title.mkv", null],
            // No release marker inside, so the group is not cut — the orphan is
            // repaired and the words are kept. Without repairBracketBalance()
            // this returns "The (Best Episode".
            'orphan with no marker'    => ['Show S01E01 The (Best Episode.mkv', 'The Best Episode'],
            // Nested parens: stripBracketedTags() is deliberately non-nesting, so
            // it eats "(Special (Extended)" and leaves an orphan ")" that ONLY the
            // AFTER-order repair removes. Running the repair FIRST yields
            // "The Trial ) Ending" — this pins the ordering SM-0.1 established.
            'nested parens'            => [
                'Show S01E01 The Trial (Special (Extended)) Ending.mkv',
                'The Trial Ending',
            ],
            'nested tag group'         => ['Show S01E01 Title (Bonus (Disc 2)).mkv', 'Title'],
        ];
    }

    /**
     * F4. The revision rule is deliberately ONE digit, so a two-digit "v" token
     * is ordinary title text, not a fansub revision. Measured over all 26,389
     * reference basenames the only revision markers that exist are v1 and v2;
     * "v10"-"v99" are unreachable, which is exactly why the second digit the
     * pattern used to allow was dead code.
     */
    public function testATwoDigitVTokenIsTitleTextNotARevisionMarker(): void
    {
        $r = EpisodeFilenameParser::parse('Show S01E01 [720p] V10.mkv', true);
        $this->assertNotNull($r);
        $this->assertSame('V10', $r['episode_title']);
    }

    /**
     * F3. Ordinary English words that collide with a scene marker no longer
     * destroy the title. Before the run rule these returned "A", "The", "The"
     * and null respectively, because the cut was a PREFIX cut.
     *
     * @dataProvider englishWordCollisionCases
     */
    public function testAMarkerThatIsAlsoAnEnglishWordDoesNotCutTheTitle(
        string $filename,
        string $expected
    ): void {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function englishWordCollisionCases(): array
    {
        return [
            'proper mid-title'  => ['Show S01E01 [720p] A Proper Sendoff.mkv', 'A Proper Sendoff'],
            'proper leading'    => ['Show S01E01 [720p] Proper Channels.mkv', 'Proper Channels'],
            'repack trailing'   => ['Show S01E01 [720p] The Repack.mkv', 'The Repack'],
            'hdtv mid-title'    => ['Show S01E01 [720p] The Hdtv Years.mkv', 'The Hdtv Years'],
            'remux mid-title'   => ['Show S01E01 [720p] The Remux Job.mkv', 'The Remux Job'],
            'remux leading'     => ['Show S01E01 [720p] Remux and Remaster.mkv', 'Remux and Remaster'],
            'divx leading'      => ['Show S01E01 [720p] Divx and Conquer.mkv', 'Divx and Conquer'],
            // Real prod basenames.
            'web after a tag'   => ['NCIS S03E06 [576p] The Voyeur\'s Web.m4v', 'The Voyeur\'s Web'],
            'web before a tag'  => [
                'The MAGIC School Bus - S03 E03 - Spins a Web (480p - DVDRip).mp4',
                'Spins a Web',
            ],
            'internal after tag' => ['NCIS S05E14 [576p] Internal Affairs.m4v', 'Internal Affairs'],
            'dl at the end'      => ['Dark Angel S01E05 [360p] 411 on the DL.mkv', '411 on the DL'],
        ];
    }

    /**
     * F4. The bit-depth branch was dead under the old prefix cut (the cut already
     * happened at "1080p", so "10bit" never mattered). Under the run rule it is
     * load-bearing: without it the run breaks at "10bit" and the resolution
     * survives into the title.
     *
     * @dataProvider bitDepthCases
     */
    public function testABitDepthTokenKeepsTheReleaseRunTogether(string $filename): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame('The.Title', $r['episode_title']);
    }

    /** @return array<string, array{0: string}> */
    public static function bitDepthCases(): array
    {
        return [
            '10bit' => ['Show.S01E01.The.Title.1080p.10bit.x265-GRP.mkv'],
            '8bit'  => ['Show.S01E01.The.Title.1080p.8bit.BluRay.x265-GRP.mkv'],
            '12bit' => ['Show.S01E01.The.Title.2160p.12bit.WEBRip.x265-GRP.mkv'],
        ];
    }

    /**
     * F4. The revision rule is ONE digit. Measured over all 26,389 reference
     * basenames the only revision markers that exist are v1 and v2, so the
     * second digit the pattern used to allow was unreachable. D6 (70 prod rows
     * literally named "v2") stays closed — the real basename is below.
     */
    public function testTheRevisionMarkerIsStillRemoved(): void
    {
        $r = EpisodeFilenameParser::parse(
            'Fullmetal Alchemist Brotherhood - E63 v2 [1080p][x265][10-bit][Dual-Audio].mkv',
            true
        );
        $this->assertNotNull($r);
        $this->assertNull($r['episode_title']);
    }

    /**
     * A lone marker in the middle of a name is left alone, but the scene's
     * tag-GROUP spelling may cut on its own — no English phrase produces it.
     * Real prod basename; without this the title keeps the whole tail.
     */
    public function testADashJoinedSceneTagCutsOnItsOwn(): void
    {
        $r = EpisodeFilenameParser::parse(
            'MacGyver.S01E01.Pilot.DVDRip-TV-Series No.001 S1E01 Pilot.avi',
            true
        );
        $this->assertNotNull($r);
        $this->assertSame('Pilot', $r['episode_title']);
    }

    /**
     * Scrap is DASH-led only. A broader "does not start with a letter or a digit"
     * test looked identical and cost 26 real titles on the reference library.
     * All five below are real prod basenames.
     *
     * @dataProvider punctuationLedTitleCases
     */
    public function testAPunctuationLedTitleIsNotScrap(string $filename, string $expected): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function punctuationLedTitleCases(): array
    {
        return [
            'hash'        => ['Supernatural S09E15 [1080p] #THINMAN.mkv', '#THINMAN'],
            'ellipsis'    => [
                'Marvel\'s Agents of S.H.I.E.L.D. S02E09 [720p] …Ye Who Enter Here.mkv',
                '…Ye Who Enter Here',
            ],
            'apostrophe'  => [
                'Star Trek Deep Space Nine S07E18 [576p] \'Til Death Do Us Part.mkv',
                '\'Til Death Do Us Part',
            ],
            'clipped word' => [
                'Batman T.A.S - S01 E35 - Almost Got \'Im (720p - BluRay).mp4',
                'Almost Got \'Im',
            ],
            'trailing bang' => [
                'Pokemon - 0518 - Tag! We\'re It...! [480p] [x265] [pseudo].mkv',
                'Tag! We\'re It...!',
            ],
        ];
    }

    /**
     * A digits-only token is title text, not junk — it breaks a run rather than
     * joining one. All four are real prod basenames whose provider title is
     * exactly the number, and all four sit immediately after a quality tag.
     *
     * @dataProvider numericTitleCases
     */
    public function testANumericTitleAfterATagSurvives(string $filename, string $expected): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function numericTitleCases(): array
    {
        return [
            '2.0'  => ['Nikita S01E02 [720p] 2.0.mkv', '2.0'],
            '3.0'  => ['Nikita S03E01 [720p] 3.0.mkv', '3.0'],
            '13.1' => ['Warehouse 13 S02E05 [1080p] 13.1.mkv', '13.1'],
            '1.28' => ['Death Note S01E36 [1080p] 1.28.mkv', '1.28'],
        ];
    }

    /**
     * A run broken by a token in NEITHER marker list used to fail the
     * two-bare-marker test and leave its own anchor behind, so the resolution
     * was promoted into the title ("The Title 1080p AAC"). Every shape below is
     * a mainstream modern release naming; measured over 30,000 such names the
     * class was 2,047 titles (6.8%) before this rule and 0 after.
     *
     * The breaking tokens are exactly the ones a wider vocabulary would have to
     * absorb — AAC, Atmos, DTS, AVC, HDR, HDR10, SDR, DV, MULTi, SUBBED, and
     * "H.265" whose dot split separates "H" from "265". None of them is listed;
     * the resolution / bit-depth PATTERN is what cuts.
     *
     * @dataProvider brokenReleaseRunCases
     */
    public function testAResolutionTokenIsNeverPromotedIntoTheTitle(string $filename, string $expected): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function brokenReleaseRunCases(): array
    {
        return [
            'aac breaks the run'    => ['Show S01E01 The Title 1080p AAC x264-GRP.mkv', 'The Title'],
            'hdr + atmos'           => [
                'Show.S01E01.The.Title.2160p.HDR.WEB-DL.DDP5.1.Atmos.x265-GRP.mkv',
                'The.Title',
            ],
            'hdr alone'             => ['Show S01E01 The Title 2160p HDR WEB-DL x265-GRP.mkv', 'The Title'],
            'sdr + aac + repack'    => ['Show S01E01 The Title 720p SDR AAC REPACK x265.mkv', 'The Title'],
            'dv + atmos + multi'    => ['Show S01E01 The Title 1080p DV Atmos MULTi.mkv', 'The Title'],
            'hdr10 + subbed + avc'  => ['Show S01E01 The Title 2160p HDR10 SUBBED AVC.mkv', 'The Title'],
            'dts after a bit depth' => ['Show S01E01 The Title 1080p 10bit DTS x265.mkv', 'The Title'],
            'dot-spelled h.265'     => ['Show.S01E01.The.Title.1080p.H.265.HMAX.WEB-DL.mkv', 'The.Title'],
            // ⚠ Bit depth with NO resolution anywhere. These are the shapes that
            // fail if isHardPatternMarker() is narrowed to the resolution alone —
            // measured, that narrowing leaves 574 (seed 4242) / 533 (seed 90210)
            // resolution-or-bit-depth titles per 30,000 modern scene names.
            'bit depth alone'       => ['Show S01E01 The Title 10bit AAC x265-GRP.mkv', 'The Title'],
            '8bit alone'            => ['Show S01E01 The Title 8bit DTS Atmos.mkv', 'The Title'],
        ];
    }

    /**
     * ⚠ The rule above trusts a SHAPE, never a word. A marker that is also an
     * English word must still need two of them, or the end of the name — so the
     * cut lands on the resolution and leaves the title whole. If
     * isHardReleaseMarker() were widened to the whole marker dictionary these
     * would all collapse to "The".
     *
     * @dataProvider wordMarkerInsideATitleCases
     */
    public function testAWordMarkerInsideATitleStillDoesNotCut(string $filename, string $expected): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function wordMarkerInsideATitleCases(): array
    {
        return [
            'remux' => ['Show S01E01 The Remux Job 1080p AAC x265-GRP.mkv', 'The Remux Job'],
            'hdtv'  => ['Show S01E01 The Hdtv Years 2160p HDR x265-GRP.mkv', 'The Hdtv Years'],
            'divx'  => ['Show S01E01 Divx and Conquer 720p AAC x264-GRP.mkv', 'Divx and Conquer'],
        ];
    }

    /**
     * ⚠ A BRACKET GROUP is never a hard marker, even when it holds a resolution.
     * "Show SxxEyy [480p] Title" IS the recovery convention this whole step
     * exists for — all 501 recovered prod titles are that shape — so letting
     * "[480p]" prove its own run would delete every one of them.
     *
     * @dataProvider bracketedResolutionCases
     */
    public function testABracketedResolutionNeverProvesItsOwnRun(string $filename, string $expected): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function bracketedResolutionCases(): array
    {
        return [
            // Real prod basenames.
            'square before'  => ['Grey\'s Anatomy S06E12 [480p] Let It Be Me.mkv', 'Let It Be Me'],
            'square 1080p'   => ['Supernatural S11E15 [1080p] Beyond the Mat.mkv', 'Beyond the Mat'],
            'both sides'     => [
                'Fullmetal Alchemist Brotherhood - E10 [1080p] Separate Destinations [x265].mkv',
                'Separate Destinations',
            ],
            'parenthesised'  => ['Show S01E01 (1080p) Real Title.mkv', 'Real Title'],
            'bit depth tag'  => ['Show S01E01 [10bit] Real Title.mkv', 'Real Title'],
        ];
    }

    /**
     * The other side of the same rule: a BARE resolution or bit-depth marker in
     * front of the text means the text is more release metadata, so nothing is
     * promoted. Pre-change these produced "1080p The Title".
     *
     * ⚠ The revision tag is deliberately absent — see
     * {@see revisionOrCrcShapedTitleCases()}. "Show S01E01 v2 The Title" keeps
     * master's "v2 The Title" precisely so "V2 Rocket Base" survives; the two
     * are the same rule firing.
     *
     * @dataProvider bareMarkerBeforeTextCases
     */
    public function testABareMarkerInFrontOfTheTextYieldsNoTitle(string $filename): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertNull($r['episode_title']);
    }

    /** @return array<string, array{0: string}> */
    public static function bareMarkerBeforeTextCases(): array
    {
        return [
            'resolution' => ['Show S01E01 1080p The Title.mkv'],
            'bit depth'  => ['Show S01E01 10bit The Title.mkv'],
        ];
    }

    /**
     * ⚠ A revision tag ("V2") and a CRC32-shaped word are NOT hard markers, and
     * this test is the guarantee.
     *
     * A hard marker cuts a run with no corroboration at all — no second marker,
     * no end of name. That is safe for a resolution and a bit depth, which no
     * one writes inside a sentence, but it is NOT safe for the other two
     * patterns: a census of all 85,763 basenames in this estate finds
     * "07 deadmau5 - Tau V2", "04 deadmau5 - Tau V1", "16 Superspy - Sumo V2",
     * "08 8bit" and "28 Just Before 8bit" as human-authored titles, and
     * "V1"/"V2"/"V8" are ordinary title vocabulary (rocket designations, engine
     * layouts). While they were hard, a single such token between two real words
     * truncated the title outright: "V2 Rocket Base" → null, "The V8
     * Interceptor" → "The".
     *
     * Both patterns remain ordinary markers in
     * EpisodeFilenameParser::isPatternReleaseMarker(), which is what keeps
     * defect D6 closed — see {@see revisionOrCrcMarkerStillCutsCases()}.
     *
     * @dataProvider revisionOrCrcShapedTitleCases
     */
    public function testARevisionOrCrcShapedTokenIsNotAHardMarker(
        string $filename,
        string $expected
    ): void {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function revisionOrCrcShapedTitleCases(): array
    {
        return [
            // Leading, medial and trailing revision tags, in every separator
            // style the tokenizer distinguishes.
            'v2 leads the title'      => ['Show S01E01 V2 Rocket Base.mkv', 'V2 Rocket Base'],
            'v8 mid title'            => ['Show S01E01 The V8 Interceptor.mkv', 'The V8 Interceptor'],
            'v1 mid title'            => ['Show S01E01 The V1 Flying Bomb.mkv', 'The V1 Flying Bomb'],
            'v2 mid title'            => ['Show S01E01 Mission V2 Complete.mkv', 'Mission V2 Complete'],
            'v2 dot-separated'        => ['Show S01E01.V2.Rocket.Base.mkv', 'V2.Rocket.Base'],
            'v2 after a quality tag'  => ['Show S01E23 [480p] V2 Rocket.mkv', 'V2 Rocket'],
            'v2 in front of the text' => ['Show S01E01 v2 The Title.mkv', 'v2 The Title'],
            'v2 before a word run'    => [
                'Show S01E01 The Title v2 AAC MULTi.mkv',
                'The Title v2 AAC MULTi',
            ],
            // CRC32-shaped words: eight hex characters with at least one digit.
            'crc-shaped word leads'   => ['Show S01E01 Cafe1234 Blues.mkv', 'Cafe1234 Blues'],
            'crc-shaped word leads 2' => ['Show S01E01 Deadbe3f Story.mkv', 'Deadbe3f Story'],
            'crc-shaped word mid'     => [
                'Show S01E01 The Deadbe3f Affair.mkv',
                'The Deadbe3f Affair',
            ],
            'crc-shaped before a run' => [
                'Show S01E01 The Title deadbee1 AAC.mkv',
                'The Title deadbee1 AAC',
            ],
            // ⚠ …and the round-2 fix still fires on the very same titles: the
            // release run is cut, the title is not. If isHardReleaseMarker() were
            // widened back these would be null or a one-word stump.
            'v2 title + broken run'   => [
                'Show S01E01 V2 Rocket Base 1080p AAC x264-GRP.mkv',
                'V2 Rocket Base',
            ],
            'v2 title + hdr dot run'  => [
                'Show S01E01.V2.Rocket.Base.2160p.HDR.WEB-DL.DDP5.1.Atmos.x265-GRP.mkv',
                'V2.Rocket.Base',
            ],
            'crc title + broken run'  => [
                'Show S01E01 Cafe1234 Blues 1080p AAC x264-GRP.mkv',
                'Cafe1234 Blues',
            ],
            'crc title mid + run'     => [
                'Show S01E01 The Deadbe3f Affair 1080p AAC x264-GRP.mkv',
                'The Deadbe3f Affair',
            ],
        ];
    }

    /**
     * Defect D6 stays closed, and so does the CRC32 stamp. Both are still
     * release MARKERS in isPatternReleaseMarker() — they simply need
     * corroboration again: the end of the name, a second bare marker, or a
     * tag-GROUP spelling. All 70 prod rows literally named "v2" are this shape.
     *
     * ⚠ These are the cases that fail if either pattern is deleted from
     * isPatternReleaseMarker() rather than merely demoted out of the HARD set.
     *
     * @dataProvider revisionOrCrcMarkerStillCutsCases
     */
    public function testARevisionOrCrcMarkerStillCutsWhenCorroborated(
        string $filename,
        ?string $expected
    ): void {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function revisionOrCrcMarkerStillCutsCases(): array
    {
        return [
            // Real D6 prod shapes — base emitted "v2" on 70 rows.
            'absolute numbering'   => ['Show - 259 v2 [720p].mkv', null],
            'bare v2 is the name'  => ['Show S01E01 v2.mkv', null],
            'v2 then resolution'   => ['Show S01E01 v2 1080p x264-GRP.mkv', null],
            // Reaches the end of the name, so no hard marker is needed.
            'v2 ends the name'     => ['Show S01E01 The Title v2.mkv', 'The Title'],
            // A CRC32 stamp in its usual bracketed place is still removed.
            'bracketed crc stamp'  => ['Show S01E01 The Title [6D3D5CCA].mkv', 'The Title'],
            // …and unbracketed, where only its being a MARKER can start the run.
            'bare crc stamp'       => ['Show S01E01 The Title deadbee1.mkv', 'The Title'],
            'crc stamp then a run' => [
                'Show S01E01 The Title deadbee1 1080p x264-GRP.mkv',
                'The Title',
            ],
            'crc stamp dot scene'  => [
                'Show.S01E01.The.Title.deadbee1.WEB.x264-MRSK.mkv',
                'The.Title',
            ],
        ];
    }

    /**
     * The two-BARE-marker threshold is exactly two. Nothing on the 26,389-name
     * reference library distinguishes 2 from 3 — every real row that qualifies
     * that way also reaches the end of the name or carries a tag-GROUP spelling
     * — but a resolution-less scene name does, and there the run rule is the
     * ONLY thing that cuts (no pattern marker exists to fall back on). Raising
     * the threshold to 3 leaves the whole tail on all three.
     *
     * @dataProvider twoBareMarkerCases
     */
    public function testTwoBareMarkersAreEnoughWhenThereIsNoResolutionToCutAt(
        string $filename,
        string $expected
    ): void {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /**
     * An eight-DIGIT token is title text, not a CRC32 stamp. That distinction
     * only started to matter here: the CRC pattern is now one of the markers
     * that proves a run on its own, so without the digits-only SOLID rule
     * "11001001" — a real Star Trek TNG episode title — would cut the run open
     * and take the rest of the title with it.
     *
     * @dataProvider allDigitTokenCases
     */
    public function testAnAllDigitTokenIsTitleTextNotACrcStamp(string $filename, string $expected): void
    {
        $r = EpisodeFilenameParser::parse($filename, true);
        $this->assertNotNull($r, "should still parse: {$filename}");
        $this->assertSame($expected, $r['episode_title']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function allDigitTokenCases(): array
    {
        return [
            'before a broken run' => [
                'Star Trek The Next Generation S01E15 The 11001001 Job 1080p AAC x264-GRP.mkv',
                'The 11001001 Job',
            ],
            'after a quality tag' => [
                'Star Trek The Next Generation S01E15 [1080p] The 11001001 Job.mkv',
                'The 11001001 Job',
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function twoBareMarkerCases(): array
    {
        return [
            'web-dl + ddp5' => [
                'Show.S01E01.The.Title.WEB-DL.DDP5.1.Atmos.H.264-NTb.mkv',
                'The.Title',
            ],
            'bluray + remux' => ['Show.S01E01.The.Title.BluRay.REMUX.AVC.Atmos.mkv', 'The.Title'],
            'hdtv + xvid'    => ['Show.S01E01.The.Title.HDTV.XviD.MP3.Dual.mkv', 'The.Title'],
            'space spelled'  => ['Show S01E01 The Title WEB-DL DDP5.1 Atmos AVC.mkv', 'The Title'],
        ];
    }
}
