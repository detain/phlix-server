<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\NewsletterGenerator;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Stats\StatsCollector;
use Workerman\MySQL\Connection;

/**
 * Unit tests for NewsletterGenerator class.
 */
class NewsletterGeneratorTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;
    private StatsCollector $stats;
    private LibraryManager $library;
    private string $templateDir;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->stats = new StatsCollector($this->db);
        $musicScanner = new MusicLibraryScanner($this->db, new FfmpegRunner());
        $musicLibraryService = new MusicLibraryService($this->db, $musicScanner);
        $this->library = new LibraryManager(
            $this->db,
            new MediaScanner($this->db, new \Phlix\Media\Library\ItemRepository($this->db)),
            new FolderWatcher(),
            $musicLibraryService
        );
        $this->templateDir = sys_get_temp_dir() . '/phlix_newsletter_test_templates';
        if (!is_dir($this->templateDir)) {
            mkdir($this->templateDir, 0755, true);
        }
        $emailsDir = $this->templateDir . '/emails';
        if (!is_dir($emailsDir)) {
            mkdir($emailsDir, 0755, true);
        }
        $sourceTemplate = dirname(__DIR__, 3) . '/public/templates/emails/newsletter.tpl';
        if (file_exists($sourceTemplate)) {
            copy($sourceTemplate, $emailsDir . '/newsletter.tpl');
        }
    }

    public function testGenerateForUserReturnsEmailContent(): void
    {
        $this->db->method('query')->willReturn([]);

        $weekStart = new DateTime('2024-01-01');
        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir
        );

        $result = $generator->generateForUser('user-123', $weekStart);

        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('html_body', $result);
        $this->assertArrayHasKey('plain_text', $result);
        $this->assertArrayHasKey('week_watch_time_minutes', $result);
        $this->assertArrayHasKey('new_items_count', $result);
        $this->assertArrayHasKey('top_media', $result);

        $this->assertIsString($result['subject']);
        $this->assertIsString($result['html_body']);
        $this->assertIsString($result['plain_text']);
        $this->assertIsInt($result['week_watch_time_minutes']);
        $this->assertIsInt($result['new_items_count']);
        $this->assertIsArray($result['top_media']);
    }

    public function testGenerateIncludesWatchTime(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            [['total_seconds' => 3600]],
            [['item_count' => 0]],
            []
        );

        $weekStart = new DateTime('2024-01-01');
        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir
        );

        $result = $generator->generateForUser('user-123', $weekStart);

        $this->assertEquals(60, $result['week_watch_time_minutes']);
    }

    public function testGenerateIncludesTopMedia(): void
    {
        $this->db->method('query')->willReturnOnConsecutiveCalls(
            [['total_seconds' => 0]],
            [['item_count' => 0]],
            [
                [
                    'media_item_id' => 'media-1',
                    'name' => 'Test Movie',
                    'poster_url' => null,
                    'play_count' => 5,
                ],
                [
                    'media_item_id' => 'media-2',
                    'name' => 'Another Movie',
                    'poster_url' => '"/poster.jpg"',
                    'play_count' => 3,
                ],
            ]
        );

        $weekStart = new DateTime('2024-01-01');
        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir
        );

        $result = $generator->generateForUser('user-123', $weekStart);

        $this->assertCount(2, $result['top_media']);
        $this->assertEquals('media-1', $result['top_media'][0]['media_item_id']);
        $this->assertEquals('Test Movie', $result['top_media'][0]['name']);
        $this->assertEquals(5, $result['top_media'][0]['play_count']);
        $this->assertEquals('media-2', $result['top_media'][1]['media_item_id']);
        $this->assertEquals('Another Movie', $result['top_media'][1]['name']);
        $this->assertEquals(3, $result['top_media'][1]['play_count']);
    }

    public function testGetRecipientUserIds(): void
    {
        $this->db->method('query')->willReturn([
            ['user_id' => 'user-1'],
            ['user_id' => 'user-2'],
            ['user_id' => 'user-3'],
        ]);

        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir
        );

        $result = $generator->getRecipientUserIds();

        $this->assertCount(3, $result);
        $this->assertEquals('user-1', $result[0]);
        $this->assertEquals('user-2', $result[1]);
        $this->assertEquals('user-3', $result[2]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->templateDir)) {
            $this->removeDirectory($this->templateDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testPlainTextLegDeclinesSingularFormsLikeTheTemplate(): void
    {
        // M21 (review): the plain-text half of ONE email must follow the same
        // grammar rules the HTML half already keeps (newsletter.tpl :306
        // prints "1 play"): "1 hour" vs "N hours", "1 play" vs "N plays", and
        // zero new items reads as prose, not "New Items Added: 0".
        $generator = new NewsletterGenerator($this->stats, $this->library, $this->db, $this->templateDir);
        $method = new \ReflectionMethod($generator, 'generatePlainText');
        $method->setAccessible(true);

        $singular = (string) $method->invoke($generator, 60, 1, [
            ['name' => 'Solo Film', 'play_count' => 1],
        ]);
        $this->assertStringContainsString('Watch Time: 1 hour', $singular);
        $this->assertStringContainsString('New Items Added: 1', $singular);
        $this->assertStringContainsString('Solo Film (1 play)', $singular);

        $plural = (string) $method->invoke($generator, 150, 0, [
            ['name' => 'Repeat Watch', 'play_count' => 5],
        ]);
        $this->assertStringContainsString('Watch Time: 2.5 hours', $plural);
        $this->assertStringContainsString('No new items this week', $plural);
        $this->assertStringContainsString('Repeat Watch (5 plays)', $plural);

        // A numeric-string play_count (the emulated-prepare DB face returns
        // these) declines identically — the (int) cast is the normalizer.
        $fromString = (string) $method->invoke($generator, 120, 2, [
            ['name' => 'TV Show', 'play_count' => '1'],
        ]);
        $this->assertStringContainsString('TV Show (1 play)', $fromString);
    }

    public function testSubjectInterpolatesTheAdvertisedDateTokens(): void
    {
        $this->db->method('query')->willReturn([]);

        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir,
            ['subject_template' => 'New this week ({$week_start} to {$week_end})']
        );

        $result = $generator->generateForUser('user-123', new DateTime('2024-01-01'));

        // SRV-M11 red-at-parent: before the cure this subject shipped the
        // literal '{$week_start} to {$week_end}' — the assign existed, the
        // interpolation did not.
        $this->assertSame('New this week (Jan 1, 2024 to Jan 8, 2024)', $result['subject']);
    }

    public function testSubjectWithoutTokensPassesThroughUnchanged(): void
    {
        $this->db->method('query')->willReturn([]);

        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir
        );

        $result = $generator->generateForUser('user-123', new DateTime('2024-01-01'));

        $this->assertSame('Your Phlix Weekly Watch Report', $result['subject']);
    }

    public function testNonStringSubjectTemplateFallsBackToTheDefault(): void
    {
        $this->db->method('query')->willReturn([]);

        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir,
            ['subject_template' => 42]
        );

        $result = $generator->generateForUser('user-123', new DateTime('2024-01-01'));

        $this->assertSame('Your Phlix Weekly Watch Report', $result['subject']);
    }

    /**
     * SH-010 (review server__srphp): the hour-word grammar rule compared a
     * float with a loose == — the estate's last loose comparison in src/.
     * This token-scan tripwire keeps NewsletterGenerator.php strict-typed
     * forever: no == or != may live in the file's operator stream.
     */
    public function test_the_generator_carries_no_loose_comparisons(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Admin/NewsletterGenerator.php');

        $looseLines = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_IS_EQUAL || $token[0] === T_IS_NOT_EQUAL)) {
                $looseLines[] = $token[2];
            }
        }

        $this->assertSame(
            [],
            $looseLines,
            'loose ==/!= found in NewsletterGenerator.php on lines ' . implode(', ', $looseLines)
            . ' — comparisons here are strict/typed by law (SH-010)'
        );
    }

    public function test_theHourWordFollowsTheRenderedNumber(): void
    {
        // SH-010 typed rule: singular only when the number the reader
        // actually sees reads exactly "1" — minutes that round to a display
        // "1" decline singular even though the raw minutes differ.
        $generator = new NewsletterGenerator($this->stats, $this->library, $this->db, $this->templateDir);
        $method = new \ReflectionMethod($generator, 'generatePlainText');
        $method->setAccessible(true);

        $atOne = (string) $method->invoke($generator, 60, 0, []);
        $this->assertStringContainsString('Watch Time: 1 hour', $atOne);

        $roundsToOne = (string) $method->invoke($generator, 57, 0, []);
        $this->assertStringContainsString('Watch Time: 1 hour', $roundsToOne);

        $justPastOne = (string) $method->invoke($generator, 63, 0, []);
        $this->assertStringContainsString('Watch Time: 1.1 hours', $justPastOne);

        $halfway = (string) $method->invoke($generator, 30, 0, []);
        $this->assertStringContainsString('Watch Time: 0.5 hours', $halfway);
    }

    /**
     * SH-014 (review server__fedev #6): the template's <title> and <h1>
     * referenced {$subject}, which generateForUser() never assigned — the
     * body always shipped the default while the envelope carried the custom
     * subject. The subject is now assigned before fetch and html-escaped at
     * both template sites (the envelope value stays raw).
     */
    public function test_theRenderedEmailCarriesTheEnvelopeSubject(): void
    {
        $this->db->method('query')->willReturn([]);

        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir,
            ['subject_template' => 'New this week ({$week_start} to {$week_end})']
        );

        $result = $generator->generateForUser('user-123', new DateTime('2024-01-01'));

        $this->assertStringContainsString(
            '<title>New this week (Jan 1, 2024 to Jan 8, 2024)</title>',
            $result['html_body'],
            'the assigned subject must surface in the rendered title'
        );
        $this->assertStringContainsString(
            '<h1>New this week (Jan 1, 2024 to Jan 8, 2024)</h1>',
            $result['html_body'],
            'the assigned subject must surface in the rendered headline'
        );
    }

    public function test_aSpecialCharSubjectIsEscapedInTheBodyButRawInTheEnvelope(): void
    {
        $this->db->method('query')->willReturn([]);

        $generator = new NewsletterGenerator(
            $this->stats,
            $this->library,
            $this->db,
            $this->templateDir,
            ['subject_template' => 'Phlix <b>Weekly</b>']
        );

        $result = $generator->generateForUser('user-123', new DateTime('2024-01-01'));

        $this->assertSame('Phlix <b>Weekly</b>', $result['subject']);
        $this->assertStringContainsString(
            '<title>Phlix &lt;b&gt;Weekly&lt;/b&gt;</title>',
            $result['html_body']
        );
        $this->assertStringNotContainsString('<title>Phlix <b>Weekly</b></title>', $result['html_body']);
    }
}
