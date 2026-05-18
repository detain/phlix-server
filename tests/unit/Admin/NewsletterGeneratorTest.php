<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Admin;

use DateTime;
use PHPUnit\Framework\TestCase;
use Phlex\Admin\NewsletterGenerator;
use Phlex\Media\Library\LibraryManager;
use Phlex\Media\Library\MediaScanner;
use Phlex\Media\Library\FolderWatcher;
use Phlex\Stats\StatsCollector;
use Workerman\MySQL\Connection;

/**
 * Unit tests for NewsletterGenerator class.
 *
 * @covers \Phlex\Admin\NewsletterGenerator
 */
class NewsletterGeneratorTest extends TestCase
{
    private Connection $db;
    private StatsCollector $stats;
    private LibraryManager $library;
    private string $templateDir;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->stats = new StatsCollector($this->db);
        $this->library = new LibraryManager(
            $this->db,
            new MediaScanner($this->db, new \Phlex\Media\Library\ItemRepository($this->db)),
            new FolderWatcher()
        );
        $this->templateDir = sys_get_temp_dir() . '/phlex_newsletter_test_templates';
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

        $this->assertIsArray($result);
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

        $this->assertIsArray($result);
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
}
