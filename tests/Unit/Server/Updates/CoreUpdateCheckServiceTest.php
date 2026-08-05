<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Updates;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Common\Version;
use Phlix\Server\Updates\CoreUpdateCheckService;
use Phlix\Server\Updates\CoreUpdateStatus;
use Phlix\Server\Updates\VersionMarkerFetcherInterface;
use Phlix\Tests\Support\Database\InMemoryServerSettingsConnection;
use Phlix\Tests\Support\Updates\RecordingVersionMarkerFetcher;
use PHPUnit\Framework\TestCase;

/**
 * {@see CoreUpdateCheckService} — S74 / updates.md #48.
 *
 * Every test here runs a REAL {@see SettingsRepository} over an in-memory
 * `server_settings` table ({@see InMemoryServerSettingsConnection}), so the
 * write→read round-trip the status endpoint depends on is actually exercised
 * rather than stubbed. A `createMock(SettingsRepository::class)` would answer
 * `getOverride()` with a canned value no matter what `set()` did, and the whole
 * "a fetched marker reaches the status payload" claim would go unproven.
 *
 * @package Phlix\Tests\Unit\Server\Updates
 */
final class CoreUpdateCheckServiceTest extends TestCase
{
    private const MARKER_URL = 'https://example.invalid/VERSION';
    private const UPDATE_COMMAND = 'curl -fsSL https://example.invalid/install.sh | sudo bash -s -- --update -y';

    private InMemoryServerSettingsConnection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new InMemoryServerSettingsConnection();
    }

    private function configDir(): string
    {
        return dirname(__DIR__, 4) . '/config';
    }

    private function settings(): SettingsRepository
    {
        return new SettingsRepository($this->db, $this->configDir());
    }

    /**
     * A fetcher that calls back synchronously with a fixed outcome.
     */
    private function fetcher(?string $body, ?string $error = null): RecordingVersionMarkerFetcher
    {
        return new RecordingVersionMarkerFetcher($body, $error);
    }

    private function service(
        VersionMarkerFetcherInterface $fetcher,
        string $currentVersion = '1.2.2',
    ): CoreUpdateCheckService {
        return new CoreUpdateCheckService(
            $this->settings(),
            $fetcher,
            $this->createMock(StructuredLogger::class),
            self::MARKER_URL,
            self::UPDATE_COMMAND,
            $currentVersion,
        );
    }

    // ------------------------------------------------------------------
    // The comparison itself
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function comparisonProvider(): array
    {
        return [
            'patch bump is newer'          => ['1.2.3', '1.2.2', true],
            'minor bump is newer'          => ['1.3.0', '1.2.2', true],
            'major bump is newer'          => ['2.0.0', '1.2.2', true],
            'leading v is tolerated'       => ['v1.3.0', '1.2.2', true],
            'trailing newline tolerated'   => ["1.3.0\n", '1.2.2', true],
            'identical is NOT newer'       => ['1.2.2', '1.2.2', false],
            'older is NOT newer'           => ['1.2.1', '1.2.2', false],
            'pre-release below release'    => ['1.2.2-rc1', '1.2.2', false],
            'release above pre-release'    => ['1.2.2', '1.2.2-rc1', true],
            'html page is NOT newer'       => ['<!DOCTYPE html>', '1.2.2', false],
            'empty marker is NOT newer'    => ['', '1.2.2', false],
            'two-part version rejected'    => ['1.3', '1.2.2', false],
            'garbage current is NOT newer' => ['9.9.9', 'not-a-version', false],
        ];
    }

    /**
     * @dataProvider comparisonProvider
     */
    public function testIsNewerComparesStrictly(string $candidate, string $current, bool $expected): void
    {
        self::assertSame($expected, CoreUpdateCheckService::isNewer($candidate, $current));
    }

    public function testNormaliseStripsDecorationAndRejectsNonVersions(): void
    {
        self::assertSame('1.2.2', CoreUpdateCheckService::normalise("  v1.2.2\n"));
        self::assertSame('1.2.2-rc1', CoreUpdateCheckService::normalise('1.2.2-rc1'));
        self::assertNull(CoreUpdateCheckService::normalise(''));
        self::assertNull(CoreUpdateCheckService::normalise('   '));
        self::assertNull(CoreUpdateCheckService::normalise('404: Not Found'));
        self::assertNull(CoreUpdateCheckService::normalise('1.2.2 extra'));
    }

    // ------------------------------------------------------------------
    // check() → persistence → status()
    // ------------------------------------------------------------------

    /**
     * AC1, at the service layer: a SEEDED NEWER marker must make the status
     * report an available update.
     */
    public function testASeededNewerMarkerMakesTheStatusReportAnUpdate(): void
    {
        $service = $this->service($this->fetcher("9.9.9\n"));

        $seen = null;
        $service->check(static function (CoreUpdateStatus $status) use (&$seen): void {
            $seen = $status;
        });

        self::assertInstanceOf(CoreUpdateStatus::class, $seen);
        self::assertTrue($seen->updateAvailable);
        self::assertSame('9.9.9', $seen->latestVersion);
        self::assertSame('1.2.2', $seen->currentVersion);
        self::assertNull($seen->lastError);
        self::assertIsInt($seen->lastCheckedAt);
        self::assertSame(self::UPDATE_COMMAND, $seen->updateCommand);

        // And it survives into a FRESH service instance reading the same rows —
        // i.e. it was persisted, not merely returned.
        self::assertTrue($this->service($this->fetcher(null, 'unused'))->status()->updateAvailable);
    }

    /**
     * CONTROL for the test above: an identical marker must NOT report an update.
     * Without this, "updateAvailable is true" could be a constant.
     */
    public function testAnIdenticalMarkerReportsNoUpdate(): void
    {
        $service = $this->service($this->fetcher('1.2.2'));
        $service->check();

        $status = $service->status();
        self::assertFalse($status->updateAvailable);
        self::assertSame('1.2.2', $status->latestVersion);
    }

    public function testAnOlderMarkerReportsNoUpdate(): void
    {
        $service = $this->service($this->fetcher('1.0.0'));
        $service->check();

        self::assertFalse($service->status()->updateAvailable);
    }

    public function testTheMarkerUrlHandedToTheFetcherIsTheConfiguredOne(): void
    {
        $fetcher = $this->fetcher('1.2.2');
        $this->service($fetcher)->check();

        self::assertSame([self::MARKER_URL], $fetcher->urls);
    }

    public function testAFetchErrorIsRecordedAndLeavesTheLastKnownVersionIntact(): void
    {
        $this->service($this->fetcher('9.9.9'))->check();

        $service = $this->service($this->fetcher(null, 'connection refused'));
        $service->check();

        $status = $service->status();
        self::assertSame('connection refused', $status->lastError);
        self::assertSame('9.9.9', $status->latestVersion, 'A failed poll must not erase the last good marker.');
        self::assertTrue($status->updateAvailable);
    }

    public function testAnUnparseableMarkerIsRecordedAsAnErrorRatherThanStored(): void
    {
        $service = $this->service($this->fetcher('<!DOCTYPE html><html>404</html>'));
        $service->check();

        $status = $service->status();
        self::assertNull($status->latestVersion);
        self::assertFalse($status->updateAvailable);
        self::assertSame('update check: version marker is not a semver string', $status->lastError);
    }

    public function testASuccessfulCheckClearsAPreviousError(): void
    {
        $this->service($this->fetcher(null, 'dns failure'))->check();
        self::assertSame('dns failure', $this->service($this->fetcher('1.2.2'))->status()->lastError);

        $service = $this->service($this->fetcher('1.2.3'));
        $service->check();

        self::assertNull($service->status()->lastError);
    }

    public function testStatusPerformsNoFetchAtAll(): void
    {
        $fetcher = $this->fetcher('9.9.9');
        $service = $this->service($fetcher);

        $service->status();

        self::assertSame([], $fetcher->urls, 'status() must never reach the network — it answers an HTTP request.');
    }

    // ------------------------------------------------------------------
    // The check_enabled gate
    // ------------------------------------------------------------------

    public function testTheCheckDefaultsToEnabledFromConfig(): void
    {
        self::assertTrue($this->service($this->fetcher('1.2.2'))->isCheckEnabled());
    }

    public function testADisabledCheckFetchesNothing(): void
    {
        $service = $this->service($this->fetcher('9.9.9'));
        $service->setCheckEnabled(false);

        $fetcher = $this->fetcher('9.9.9');
        $disabled = $this->service($fetcher);

        $reported = null;
        $disabled->check(static function (CoreUpdateStatus $status) use (&$reported): void {
            $reported = $status;
        });

        self::assertSame([], $fetcher->urls, 'A disabled check must not issue an outbound request.');
        self::assertInstanceOf(CoreUpdateStatus::class, $reported);
        self::assertFalse($reported->checkEnabled);
        self::assertNull($reported->latestVersion);
    }

    public function testTheToggleRoundTripsThroughPersistedSettings(): void
    {
        $service = $this->service($this->fetcher('1.2.2'));

        $service->setCheckEnabled(false);
        self::assertFalse($this->service($this->fetcher('1.2.2'))->isCheckEnabled());

        $service->setCheckEnabled(true);
        self::assertTrue($this->service($this->fetcher('1.2.2'))->isCheckEnabled());
    }

    // ------------------------------------------------------------------
    // Resident-memory / robustness
    // ------------------------------------------------------------------

    public function testTheCompletionCallbackFiresExactlyOncePerCheck(): void
    {
        $calls = 0;
        $service = $this->service($this->fetcher('1.2.3'));
        $service->check(static function () use (&$calls): void {
            $calls++;
        });

        self::assertSame(1, $calls);

        // A second check with no callback must not re-fire the first one — the
        // pending slot is single-shot, which is what keeps it from growing.
        $service->check();
        self::assertSame(1, $calls);
    }

    public function testAThrowingCompletionCallbackDoesNotEscape(): void
    {
        $service = $this->service($this->fetcher('1.2.3'));

        $service->check(static function (): void {
            throw new \RuntimeException('subscriber blew up');
        });

        // Reaching here at all is the assertion: a throw escaping check() would
        // land in a Workerman timer callback and take the worker's tick.
        self::assertSame('1.2.3', $service->status()->latestVersion);
    }

    public function testTheDefaultCurrentVersionIsTheCompiledConstant(): void
    {
        $service = new CoreUpdateCheckService(
            $this->settings(),
            $this->fetcher('1.2.2'),
            $this->createMock(StructuredLogger::class),
            self::MARKER_URL,
            self::UPDATE_COMMAND,
        );

        self::assertSame(Version::STRING, $service->status()->currentVersion);
    }

    public function testTheStatusArrayIsTheCamelCaseWireShape(): void
    {
        $service = $this->service($this->fetcher('9.9.9'));
        $service->check();

        self::assertSame(
            [
                'currentVersion',
                'latestVersion',
                'updateAvailable',
                'checkEnabled',
                'lastCheckedAt',
                'lastError',
                'updateCommand',
            ],
            array_keys($service->status()->toArray()),
        );
    }
}
