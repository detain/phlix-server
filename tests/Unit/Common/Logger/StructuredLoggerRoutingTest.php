<?php

/**
 * Phlix media server component: Logger — handler routing (item-5b).
 *
 * Verifies that each configured handler attaches only to the channels it
 * declares (and only when its optional env gate is satisfied), so that:
 *   - app.log captures every channel/level (no coverage regression),
 *   - error.log captures every channel's errors,
 *   - events.log is scoped to the EVENTS channel and gated on
 *     PHLIX_DEBUG_EVENTS,
 *   - plugins.log is scoped to the PLUGINS channel.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Logger;

use PHPUnit\Framework\TestCase;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\StructuredLogger;

class StructuredLoggerRoutingTest extends TestCase
{
    private string $tempDir;
    /** @var string|false */
    private string|false $originalDebugEvents;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/phlix_routing_logs_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        // Snapshot then clear the env gate so each test starts from a known
        // (disabled) state; tearDown restores the original value.
        $this->originalDebugEvents = getenv(LogChannels::DEBUG_EVENTS_ENV);
        putenv(LogChannels::DEBUG_EVENTS_ENV);
    }

    protected function tearDown(): void
    {
        if ($this->originalDebugEvents === false) {
            putenv(LogChannels::DEBUG_EVENTS_ENV);
        } else {
            putenv(LogChannels::DEBUG_EVENTS_ENV . '=' . $this->originalDebugEvents);
        }

        array_map('unlink', glob($this->tempDir . '/*') ?: []);
        rmdir($this->tempDir);
    }

    /**
     * Build a config mirroring config/logger.php's routing but with `stream`
     * handlers writing to deterministic temp paths (StreamHandler creates its
     * file lazily on the first matching write, so file presence == routed).
     *
     * @return array<string, mixed>
     */
    private function routedConfig(): array
    {
        return [
            'handlers' => [
                'file' => [
                    'type' => 'stream',
                    'path' => $this->path('app.log'),
                    'level' => 'debug',
                ],
                'error' => [
                    'type' => 'stream',
                    'path' => $this->path('error.log'),
                    'level' => 'error',
                ],
                'events' => [
                    'type' => 'stream',
                    'path' => $this->path('events.log'),
                    'level' => 'debug',
                    'channels' => [LogChannels::EVENTS],
                    'env' => LogChannels::DEBUG_EVENTS_ENV,
                ],
                'plugins' => [
                    'type' => 'stream',
                    'path' => $this->path('plugins.log'),
                    'level' => 'debug',
                    'channels' => [LogChannels::PLUGINS],
                ],
            ],
        ];
    }

    private function path(string $file): string
    {
        return $this->tempDir . '/' . $file;
    }

    public function testNormalChannelWritesOnlyToAppLog(): void
    {
        $logger = new StructuredLogger(LogChannels::AUTH, $this->routedConfig());
        $logger->info('auth-marker');

        $this->assertFileExists($this->path('app.log'), 'app.log must capture every channel');
        $this->assertStringContainsString('auth-marker', (string) file_get_contents($this->path('app.log')));
        $this->assertFileDoesNotExist($this->path('events.log'), 'events.log must stay off non-EVENTS channels');
        $this->assertFileDoesNotExist($this->path('plugins.log'), 'plugins.log must stay off non-PLUGINS channels');
    }

    public function testPluginsChannelWritesToPluginsAndAppButNotEvents(): void
    {
        $logger = new StructuredLogger(LogChannels::PLUGINS, $this->routedConfig());
        $logger->info('plugin-lifecycle-marker');

        $this->assertFileExists($this->path('plugins.log'), 'PLUGINS channel must reach plugins.log');
        $this->assertStringContainsString(
            'plugin-lifecycle-marker',
            (string) file_get_contents($this->path('plugins.log'))
        );
        $this->assertFileExists($this->path('app.log'), 'PLUGINS records must still land in app.log');
        $this->assertFileDoesNotExist($this->path('events.log'), 'PLUGINS records must NOT land in events.log');
    }

    public function testEventsChannelStaysEmptyWhenDebugDisabled(): void
    {
        putenv(LogChannels::DEBUG_EVENTS_ENV); // unset -> disabled

        $logger = new StructuredLogger(LogChannels::EVENTS, $this->routedConfig());
        $logger->info('event-dispatch-marker');

        $this->assertFileDoesNotExist(
            $this->path('events.log'),
            'events.log must stay empty when PHLIX_DEBUG_EVENTS is unset'
        );
        // ...but the record is not lost — it still reaches app.log.
        $this->assertFileExists($this->path('app.log'));
        $this->assertStringContainsString('event-dispatch-marker', (string) file_get_contents($this->path('app.log')));
    }

    public function testEventsChannelWritesWhenDebugEnabled(): void
    {
        putenv(LogChannels::DEBUG_EVENTS_ENV . '=1');

        $logger = new StructuredLogger(LogChannels::EVENTS, $this->routedConfig());
        $logger->info('event-dispatch-marker');

        $this->assertFileExists(
            $this->path('events.log'),
            'events.log must receive EVENTS records when PHLIX_DEBUG_EVENTS=1'
        );
        $this->assertStringContainsString(
            'event-dispatch-marker',
            (string) file_get_contents($this->path('events.log'))
        );
    }

    /**
     * The env gate follows EventDispatcherFactory::debugEnabled() semantics:
     * 1/true/yes/on enable; anything else (including "0"/"false") disables.
     */
    public function testEventsEnvGateTruthinessMatchesDispatcher(): void
    {
        foreach (['true', 'YES', 'On'] as $truthy) {
            putenv(LogChannels::DEBUG_EVENTS_ENV . '=' . $truthy);
            $logger = new StructuredLogger(LogChannels::EVENTS, $this->routedConfig());
            $logger->info('truthy-' . $truthy);
            $this->assertFileExists($this->path('events.log'), "value '$truthy' should enable events.log");
            unlink($this->path('events.log'));
        }

        foreach (['0', 'false', 'off'] as $falsy) {
            putenv(LogChannels::DEBUG_EVENTS_ENV . '=' . $falsy);
            $logger = new StructuredLogger(LogChannels::EVENTS, $this->routedConfig());
            $logger->info('falsy-' . $falsy);
            $this->assertFileDoesNotExist($this->path('events.log'), "value '$falsy' should keep events.log empty");
        }
    }

    public function testErrorRecordOnAnyChannelReachesErrorLog(): void
    {
        // A non-EVENTS/non-PLUGINS channel to prove error aggregation is
        // channel-agnostic.
        $logger = new StructuredLogger(LogChannels::MEDIA, $this->routedConfig());
        $logger->error('media-failure-marker');

        $this->assertFileExists($this->path('error.log'), 'error.log must aggregate errors from every channel');
        $this->assertStringContainsString('media-failure-marker', (string) file_get_contents($this->path('error.log')));
        // And app.log still captures it too (belt-and-suspenders coverage).
        $this->assertFileExists($this->path('app.log'));
    }

    public function testInfoRecordDoesNotReachErrorLog(): void
    {
        $logger = new StructuredLogger(LogChannels::MEDIA, $this->routedConfig());
        $logger->info('just-info-marker');

        $this->assertFileDoesNotExist($this->path('error.log'), 'error.log must not receive sub-error records');
    }

    /**
     * Mutation-style guard: if the plugins handler is (mis)scoped to a
     * different channel, a PLUGINS-channel record must NOT reach plugins.log.
     * This proves the channel filter is load-bearing rather than incidental.
     */
    public function testMisScopedChannelListSuppressesHandler(): void
    {
        $config = $this->routedConfig();
        $config['handlers']['plugins']['channels'] = [LogChannels::AUTH]; // wrong channel

        $logger = new StructuredLogger(LogChannels::PLUGINS, $config);
        $logger->info('plugin-lifecycle-marker');

        $this->assertFileDoesNotExist(
            $this->path('plugins.log'),
            'a mis-scoped channels list must keep the handler off the PLUGINS channel'
        );
        // The record is still captured by the unrestricted app.log handler.
        $this->assertFileExists($this->path('app.log'));
    }

    public function testAbsentChannelsKeyAttachesToEveryChannel(): void
    {
        // A handler with no `channels`/`env` keys behaves like today's
        // file/error handlers: it attaches regardless of channel.
        $config = [
            'handlers' => [
                'catchall' => [
                    'type' => 'stream',
                    'path' => $this->path('catchall.log'),
                    'level' => 'debug',
                ],
            ],
        ];

        $loggerA = new StructuredLogger(LogChannels::EVENTS, $config);
        $loggerA->info('from-events');
        $loggerB = new StructuredLogger(LogChannels::HTTP, $config);
        $loggerB->info('from-http');

        $contents = (string) file_get_contents($this->path('catchall.log'));
        $this->assertStringContainsString('from-events', $contents);
        $this->assertStringContainsString('from-http', $contents);
    }
}
