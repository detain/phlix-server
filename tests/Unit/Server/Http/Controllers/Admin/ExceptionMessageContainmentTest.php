<?php

/**
 * SH-003 — admin failure envelopes never echo raw exception text.
 *
 * The reviewed defect (server__copywriter.md #1, M-SERVER-05 family): catch
 * arms built their wire `message` (or `error`) straight from
 * `$e->getMessage()`. Backup failures carry `mysqldump`/`tar` process errors,
 * re-thrown `PDOException` text (`'SQL:' . <the full statement>`) and
 * absolute paths; installer failures embed staging paths and upstream URLs.
 * The cure replaces the echoed detail with a per-controller fixed constant
 * and logs class + message + origin to the HTTP channel. Envelope shapes,
 * per-arm `error` labels, statuses and the wire code set are UNCHANGED
 * (contract law — the contracts trio md5s stay frozen).
 *
 * Two legs, deliberately:
 *  - BEHAVIOURAL: for every arm a unit can drive, a fake dependency throws a
 *    sentinel and the wire must carry the fixed copy, never the sentinel.
 *  - STRUCTURAL: token-scan every cured class and fail if any literal
 *    `->json(...)` argument references `getMessage()`. This covers the
 *    defensive arms no unit can reach (FsBrowse, AdminRestart, Ldap, the
 *    BackupController schedule arm) and keeps them cured.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MPL-2.0
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\BackupManager;
use Phlix\Admin\Maintenance\MaintenanceJobRepository;
use Phlix\Admin\Maintenance\MaintenanceTaskRunner;
use Phlix\Auth\UserRepository;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Media\Transcoding\FfmpegRunner;
use Phlix\Plugins\Ldap\Controller\LdapAdminController;
use Phlix\Server\Http\Controllers\Admin\AdminRestartController;
use Phlix\Server\Http\Controllers\Admin\AdminTranscodingController;
use Phlix\Server\Http\Controllers\Admin\AdminUpdatesController;
use Phlix\Server\Http\Controllers\Admin\AdminWebhooksController;
use Phlix\Server\Http\Controllers\Admin\BackupController;
use Phlix\Server\Http\Controllers\Admin\FsBrowseController;
use Phlix\Server\Http\Controllers\Admin\MaintenanceController;
use Phlix\Server\Http\Controllers\PluginAdminController;
use Phlix\Server\Http\Middleware\AdminMiddleware;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\Response;
use Phlix\Webhooks\WebhookService;
use ReflectionClass;
use RuntimeException;

final class ExceptionMessageContainmentTest extends TestCase
{
    /** Text any cured arm must never let reach the wire. */
    private const SENTINEL = 'SENTINEL-RAW sql: SELECT * FROM mysql.user at /var/lib/phlix/db.cnf';

    private const BAK_COPY = 'An internal error occurred while handling the backup request. '
        . 'The details were logged server-side.';

    private const MAINT_COPY = 'An internal error occurred while handling the maintenance request. '
        . 'The details were logged server-side.';

    private const WEBHOOK_COPY = 'An internal error occurred while handling the webhook request. '
        . 'The details were logged server-side.';

    private const ACCEL_COPY = 'An internal error occurred while detecting hardware accelerators. '
        . 'The details were logged server-side.';

    private const UPDATE_COPY = 'An internal error occurred while dispatching the core update check. '
        . 'The details were logged server-side.';

    private const BROWSE_COPY = 'An internal error occurred while browsing the filesystem. '
        . 'The details were logged server-side.';

    private const RESTART_COPY = 'An internal error occurred while requesting the server restart. '
        . 'The details were logged server-side.';

    private const LDAP_COPY = 'The LDAP connection test failed to complete. '
        . 'The details were logged server-side.';

    /**
     * The nine cured classes: class-name => [source path, fixed copy]. The
     * copy entries mirror the src constants verbatim (leg: constants match).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function curedClasses(): array
    {
        $src = dirname(__DIR__, 6) . '/src/';

        return [
            'backup' => [$src . 'Server/Http/Controllers/Admin/BackupController.php', self::BAK_COPY],
            'maintenance' => [$src . 'Server/Http/Controllers/Admin/MaintenanceController.php', self::MAINT_COPY],
            'webhooks' => [$src . 'Server/Http/Controllers/Admin/AdminWebhooksController.php', self::WEBHOOK_COPY],
            'accelerators' => [$src . 'Server/Http/Controllers/Admin/AdminTranscodingController.php', self::ACCEL_COPY],
            'updates' => [$src . 'Server/Http/Controllers/Admin/AdminUpdatesController.php', self::UPDATE_COPY],
            'fsbrowse' => [$src . 'Server/Http/Controllers/Admin/FsBrowseController.php', self::BROWSE_COPY],
            'restart' => [$src . 'Server/Http/Controllers/Admin/AdminRestartController.php', self::RESTART_COPY],
            'ldap' => [$src . 'Plugins/Ldap/Controller/LdapAdminController.php', self::LDAP_COPY],
        ];
    }

    private function request(): Request
    {
        $request = new Request();
        $request->method = 'POST';
        $request->path = '/api/v1/admin/exception-containment';
        $request->userId = 'admin-1';
        $request->body = [];
        $request->query = [];

        return $request;
    }

    /**
     * @return array<string, array{0: string, 1: string}> handler => [manager method, error label]
     */
    private function backupArms(): array
    {
        return [
            'create' => ['createBackup', 'Backup creation failed'],
            'list' => ['listBackups', 'Failed to list backups'],
            'delete' => ['deleteBackup', 'Failed to delete backup'],
            'restore' => ['restore', 'Restore failed'],
            'uploadS3' => ['uploadToS3', 'S3 upload failed'],
            'getSchedule' => ['getNextScheduledBackup', 'Failed to get schedule'],
        ];
    }

    /**
     * Every reachable BackupController failure arm answers 500 with the
     * per-arm error label (unchanged), the fixed operator copy, and NEVER
     * the thrown text.
     */
    public function test_every_backup_failure_arm_answers_with_the_fixed_copy(): void
    {
        foreach ($this->backupArms() as $handler => [$managerMethod, $errorLabel]) {
            $manager = $this->createMock(BackupManager::class);
            $manager->method($managerMethod)
                ->willThrowException(new RuntimeException(self::SENTINEL));

            $response = (new BackupController($manager))->{$handler}($this->request(), ['id' => 'bk-1']);

            $this->assertSame(500, $response->statusCode, $handler . ' status');
            $body = $this->decode($response);
            $this->assertFalse($body['success'], $handler . ' success flag');
            $this->assertSame($errorLabel, $body['error'], $handler . ' error label must stay byte-ident');
            $this->assertSame(self::BAK_COPY, $body['message'], $handler . ' fixed copy');
            $this->assertStringNotContainsString('SENTINEL-RAW', $response->body, $handler . ' must not echo');
        }
    }

    /**
     * The MaintenanceController repository arm (jobs list) — same law. The
     * four arms share failure(), so this convicts the helper for all of them.
     */
    public function test_the_maintenance_repository_arm_answers_with_the_fixed_copy(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findAdminById')->willReturnCallback(
            static fn (string $id): ?array => $id === 'admin-1' ? ['id' => $id] : null
        );
        $guard = new AdminMiddleware($users, $this->createMock(AuditLogger::class));

        $jobs = $this->createMock(MaintenanceJobRepository::class);
        $jobs->method('recent')->willThrowException(new RuntimeException(self::SENTINEL));

        $controller = new MaintenanceController(
            $jobs,
            $this->createMock(MaintenanceTaskRunner::class),
            $guard,
        );

        $response = $controller->jobs($this->request(), []);

        $this->assertSame(500, $response->statusCode);
        $body = $this->decode($response);
        $this->assertSame('Failed to list maintenance jobs', $body['error']);
        $this->assertSame(self::MAINT_COPY, $body['message']);
        $this->assertStringNotContainsString('SENTINEL-RAW', $response->body);
    }

    /**
     * The AdminWebhooksController errorResponse() arm (subscriptions list).
     */
    public function test_the_webhook_list_arm_answers_with_the_fixed_copy(): void
    {
        $service = $this->createMock(WebhookService::class);
        $service->method('listSubscriptions')
            ->willThrowException(new RuntimeException(self::SENTINEL));

        $response = (new AdminWebhooksController($service))->listSubscriptions($this->request(), []);

        $this->assertSame(500, $response->statusCode);
        $body = $this->decode($response);
        $this->assertSame('Failed to list subscriptions', $body['error']);
        $this->assertSame(self::WEBHOOK_COPY, $body['message']);
        $this->assertStringNotContainsString('SENTINEL-RAW', $response->body);
    }

    /**
     * The accelerator probe arm (envelope has no `success` key — shape kept).
     */
    public function test_the_accelerator_probe_arm_answers_with_the_fixed_copy(): void
    {
        $runner = $this->createMock(FfmpegRunner::class);
        $runner->method('getHardwareAccelerators')
            ->willThrowException(new RuntimeException(self::SENTINEL));

        $response = (new AdminTranscodingController($runner, []))->accelerators($this->request(), []);

        $this->assertSame(500, $response->statusCode);
        $body = $this->decode($response);
        $this->assertSame('Failed to detect hardware accelerators', $body['error']);
        $this->assertSame(self::ACCEL_COPY, $body['message']);
        $this->assertStringNotContainsString('SENTINEL-RAW', $response->body);
    }

    /**
     * STRUCTURAL LEG — no literal ->json(...) argument in any cured class may
     * reference getMessage(). Catches the four defensive arms no unit can
     * reach (FsBrowse's scandir-path arm, AdminRestart's signalling arm,
     * Ldap's constructor arm, BackupController's schedule-write arm) and
     * keeps every cured class cured. Log-context arrays are outside ->json()
     * arguments, so the logFailure() helpers stay compliant.
     */
    public function test_no_cured_class_puts_exception_text_into_a_json_envelope(): void
    {
        $files = array_column($this->curedClasses(), 0);
        $files[] = (new ReflectionClass(PluginAdminController::class))->getFileName();

        foreach ($files as $file) {
            $this->assertFileExists($file);
            foreach ($this->jsonArgumentTexts((string) file_get_contents($file)) as $index => $argument) {
                $this->assertStringNotContainsString(
                    'getMessage',
                    $argument,
                    sprintf('%s: ->json() argument #%d must not echo exception text (SH-003)', $file, $index),
                );
            }
        }
    }

    /**
     * The eight constant-bearing classes declare the fixed copy verbatim;
     * the test constants above mirror the same strings, so a src wording
     * change without a law-test change reddens here, not silently.
     */
    public function test_each_cured_class_declares_the_pinned_generic_constant(): void
    {
        $expected = [
            BackupController::class => self::BAK_COPY,
            MaintenanceController::class => self::MAINT_COPY,
            AdminWebhooksController::class => self::WEBHOOK_COPY,
            AdminTranscodingController::class => self::ACCEL_COPY,
            AdminUpdatesController::class => self::UPDATE_COPY,
            FsBrowseController::class => self::BROWSE_COPY,
            AdminRestartController::class => self::RESTART_COPY,
            LdapAdminController::class => self::LDAP_COPY,
        ];

        foreach ($expected as $class => $copy) {
            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->hasConstant('GENERIC_FAILURE_MESSAGE'), $class);
            $this->assertSame($copy, $reflection->getConstant('GENERIC_FAILURE_MESSAGE'), $class);
        }

        $pluginSource = (string) file_get_contents(
            dirname(__DIR__, 6) . '/src/Server/Http/Controllers/PluginAdminController.php',
        );
        $this->assertStringContainsString("'error' => 'Plugin installation failed'", $pluginSource);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, 'Every response must be a JSON object: ' . $response->body);

        return $decoded;
    }

    /**
     * Balanced-argument extractor: every literal array passed to ->json( in
     * the source, as raw text. Token-walked so nested arrays cannot truncate
     * the capture (the regex ']' trap that bit earlier guard ports).
     *
     * @return list<string>
     */
    private function jsonArgumentTexts(string $source): array
    {
        $tokens = token_get_all($source);
        $texts = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'json') {
                continue;
            }

            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '(') {
                continue;
            }

            $depth = 0;
            $start = $j;
            for ($k = $j; $k < $count; $k++) {
                $raw = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($raw === '(' || $raw === '[' || $raw === '{') {
                    $depth++;
                } elseif ($raw === ')' || $raw === ']' || $raw === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $texts[] = implode('', array_map(
                            static fn ($t): string => is_array($t) ? $t[1] : (string) $t,
                            array_slice($tokens, $start, $k - $start + 1),
                        ));
                        $i = $k;
                        break;
                    }
                }
            }
        }

        return $texts;
    }
}
