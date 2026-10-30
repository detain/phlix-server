<?php

/**
 * Phlix media server component: Tests.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Admin;

use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Phlix\Admin\NewsletterGenerator;
use Phlix\Media\Library\FolderWatcher;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\LibraryManager;
use Phlix\Media\Library\MediaScanner;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Music\MusicLibraryScanner;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Stats\StatsCollector;
use Workerman\MySQL\Connection;

/**
 * Review server__copywriter M-SERVER-02: every link in the weekly newsletter
 * email shipped as a dead `href="#"` — "View in Phlix", "Visit Phlix", "Help
 * Center", "Preferences" and, worst, the "Unsubscribe" row that promised an
 * opt-out leading nowhere. NewsletterGenerator/NewsletterSender performed no
 * href substitution, so the `#` went out verbatim in every sent email.
 *
 * The shipped truth: links are rendered ONLY when the deployment provides a
 * base URL (config newsletter.base_url, falling back to hub.public_url — the
 * same public hostname the server advertises when pairing). CTA and
 * "Visit Phlix" resolve to the SPA at {base}/app, "Preferences" to
 * {base}/app/settings (both real shipped routes). "Help Center" is gone —
 * no such page exists anywhere. The Unsubscribe anchor is gone too: this
 * server has no user-facing opt-out route, so the footer now states the real
 * control plane (the administrator's newsletter toggle) instead of shipping
 * a fake opt-out link.
 *
 * @package Phlix\Tests\Unit\Admin
 */
final class NewsletterLinksTruthTest extends TestCase
{
    /** @var Connection&MockObject */
    private Connection $db;

    private string $templateDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = $this->createMock(Connection::class);
        $this->db->method('query')->willReturn([]);

        $this->templateDir = sys_get_temp_dir() . '/phlix_srmaj_newsletter_templates';
        if (!is_dir($this->templateDir . '/emails')) {
            mkdir($this->templateDir . '/emails', 0755, true);
        }
        copy(
            dirname(__DIR__, 3) . '/public/templates/emails/newsletter.tpl',
            $this->templateDir . '/emails/newsletter.tpl',
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $template = $this->templateDir . '/emails/newsletter.tpl';
        if (is_file($template)) {
            unlink($template);
        }
        if (is_dir($this->templateDir . '/emails')) {
            rmdir($this->templateDir . '/emails');
        }
        if (is_dir($this->templateDir)) {
            rmdir($this->templateDir);
        }
    }

    private function generator(array $config): NewsletterGenerator
    {
        $stats = new StatsCollector($this->db);
        $musicScanner = new MusicLibraryScanner($this->db, new FfmpegRunner());
        $musicLibraryService = new MusicLibraryService($this->db, $musicScanner);
        $library = new LibraryManager(
            $this->db,
            new MediaScanner($this->db, new ItemRepository($this->db)),
            new FolderWatcher(),
            $musicLibraryService
        );

        return new NewsletterGenerator($stats, $library, $this->db, $this->templateDir, $config);
    }

    public function test_the_committed_template_ships_no_dead_anchor(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/public/templates/emails/newsletter.tpl');

        self::assertStringNotContainsString(
            'href="#"',
            $template,
            'a literal-hashed href in the committed template mails a dead button to every user (M-SERVER-02).'
        );
        self::assertStringNotContainsString(
            'Help Center',
            $template,
            'no Help Center page exists on this server; the footer must not link one.'
        );
        self::assertStringNotContainsString(
            'Unsubscribe</a>',
            $template,
            'no user-facing unsubscribe route exists; the footer must not render a fake opt-out anchor.'
        );
    }

    public function test_links_resolve_against_the_configured_base_url(): void
    {
        $result = $this->generator(['base_url' => 'https://phlix.test/'])
            ->generateForUser('user-123', new DateTime('2024-01-01'));

        $html = $result['html_body'];

        self::assertStringContainsString(
            'href="https://phlix.test/app"',
            $html,
            'the CTA/Visit links must resolve to the SPA root under the configured base URL (trailing slash normalized).'
        );
        self::assertStringContainsString(
            'href="https://phlix.test/app/settings"',
            $html,
            'Preferences must resolve to the shipped /app/settings route.'
        );
        self::assertStringNotContainsString('href="#"', $html);
        self::assertStringContainsString(
            'ask your server administrator to disable the newsletter',
            $html,
            'the footer must state the real control plane in place of the phantom unsubscribe link.'
        );
    }

    public function test_no_base_url_means_no_links_rather_than_dead_links(): void
    {
        $html = $this->generator([])->generateForUser('user-123', new DateTime('2024-01-01'))['html_body'];

        self::assertStringNotContainsString(
            '<a href=',
            $html,
            'without a base URL every anchor would be a dead button — the template must omit links entirely.'
        );
    }
}
