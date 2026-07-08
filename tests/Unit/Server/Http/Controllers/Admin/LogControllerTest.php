<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Server\Http\Controllers\Admin\LogController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the admin log viewer: listing, tailing, line clamping, and
 * — most importantly — that the `file` parameter cannot escape the log dir
 * (traversal / absolute path / non-.log).
 */
final class LogControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phlix_logtest_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/app.log', "l1\nl2\nl3\nl4\nl5\n");
        file_put_contents($this->dir . '/error.log', "e1\ne2\n");
        // A non-log file + a secret outside the dir that traversal must not reach.
        file_put_contents($this->dir . '/notes.txt', "secret\n");
        file_put_contents(dirname($this->dir) . '/phlix_outside_secret', "TOPSECRET\n");
    }

    protected function tearDown(): void
    {
        foreach (['app.log', 'error.log', 'notes.txt'] as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @unlink(dirname($this->dir) . '/phlix_outside_secret');
        @rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function req(array $query): Request
    {
        $r = new Request();
        $r->userId = 'admin-1';
        $r->query = $query;
        return $r;
    }

    public function testIndexListsOnlyLogFiles(): void
    {
        $controller = new LogController($this->dir);
        /** @var array{files: array<int, array<string, mixed>>} $body */
        $body = json_decode($controller->index(new Request(), [])->body, true);

        $names = array_map(
            static function (array $f): string {
                $name = $f['name'] ?? '';
                return is_string($name) ? $name : '';
            },
            $body['files'],
        );
        sort($names);
        $this->assertSame(['app.log', 'error.log'], $names); // notes.txt excluded
        $this->assertArrayHasKey('size', $body['files'][0]);
        $this->assertArrayHasKey('modified_at', $body['files'][0]);
    }

    public function testTailReturnsLastNLines(): void
    {
        $controller = new LogController($this->dir);
        $resp = $controller->tail($this->req(['file' => 'app.log', 'lines' => '2']), []);

        $this->assertSame(200, $resp->statusCode);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($resp->body, true);
        $this->assertSame(['l4', 'l5'], $body['lines']);
        $this->assertSame('app.log', $body['file']);
    }

    public function testTailDefaultsAndReturnsAllWhenFewerLines(): void
    {
        $controller = new LogController($this->dir);
        /** @var array<array-key, mixed> $body */
        $body = json_decode($controller->tail($this->req(['file' => 'error.log']), [])->body, true);
        $this->assertSame(['e1', 'e2'], $body['lines']);
    }

    /** @dataProvider maliciousFileNames */
    public function testTailRejectsTraversalAndNonLogNames(string $file, int $expectedStatus): void
    {
        $controller = new LogController($this->dir);
        $resp = $controller->tail($this->req(['file' => $file]), []);
        $this->assertSame($expectedStatus, $resp->statusCode);
        // Never leaks the out-of-jail secret.
        $this->assertStringNotContainsString('TOPSECRET', $resp->body);
        $this->assertStringNotContainsString('secret', $resp->body);
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function maliciousFileNames(): array
    {
        return [
            'parent traversal'      => ['../phlix_outside_secret', 400],
            'absolute path'         => ['/etc/passwd', 400],
            'non-log extension'     => ['notes.txt', 400],
            'nested traversal .log' => ['../../etc/shadow.log', 404],
            'empty'                 => ['', 400],
        ];
    }

    public function testMissingFileReturns404(): void
    {
        $controller = new LogController($this->dir);
        $resp = $controller->tail($this->req(['file' => 'nope.log']), []);
        $this->assertSame(404, $resp->statusCode);
    }

    public function testTailAllMergesEveryLogFileTaggedBySource(): void
    {
        $controller = new LogController($this->dir);
        /** @var array{files: array<int, mixed>, lines: array<int, string>, truncated: mixed} $body */
        $body = json_decode($controller->tailAll($this->req([]), [])->body, true);

        // Lists every .log file that was merged (notes.txt excluded).
        sort($body['files']);
        $this->assertSame(['app.log', 'error.log'], $body['files']);

        // Every source line appears, each prefixed with its file name.
        $joined = implode("\n", $body['lines']);
        $this->assertStringContainsString('app.log', $joined);
        $this->assertStringContainsString('error.log', $joined);
        foreach (['l1', 'l5', 'e1', 'e2'] as $needle) {
            $this->assertStringContainsString($needle, $joined);
        }
        // app.log (5) + error.log (2) = 7 merged lines.
        $this->assertCount(7, $body['lines']);
        $this->assertFalse($body['truncated']);
        // Never leaks the non-.log file.
        $this->assertStringNotContainsString('secret', $joined);
    }

    public function testTailAllOrdersByLeadingTimestampAcrossFiles(): void
    {
        // Two files whose timestamped lines interleave chronologically.
        file_put_contents(
            $this->dir . '/a.log',
            "[2026-05-15T10:00:00.000000-04:00] a.INFO: first\n"
            . "[2026-05-15T10:00:30.000000-04:00] a.INFO: third\n",
        );
        file_put_contents(
            $this->dir . '/b.log',
            "[2026-05-15T10:00:15.000000-04:00] b.INFO: second\n"
            . "[2026-05-15T10:00:45.000000-04:00] b.INFO: fourth\n",
        );

        $controller = new LogController($this->dir);
        /** @var array{lines: array<int, string>} $body */
        $body = json_decode($controller->tailAll($this->req([]), [])->body, true);
        $joined = implode("\n", $body['lines']);

        $this->assertLessThan(strpos($joined, 'second'), strpos($joined, 'first'));
        $this->assertLessThan(strpos($joined, 'third'), strpos($joined, 'second'));
        $this->assertLessThan(strpos($joined, 'fourth'), strpos($joined, 'third'));

        @unlink($this->dir . '/a.log');
        @unlink($this->dir . '/b.log');
    }

    public function testTailAllRespectsLineCap(): void
    {
        $controller = new LogController($this->dir);
        // app.log has 5, error.log 2 → 7 total; cap at 3.
        /** @var array{lines: array<int, mixed>, truncated: mixed} $body */
        $body = json_decode($controller->tailAll($this->req(['lines' => '3']), [])->body, true);

        $this->assertCount(3, $body['lines']);
        $this->assertTrue($body['truncated']);
    }
}
