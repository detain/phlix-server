<?php

/**
 * Phlix media server component: Providers.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Access\StreamSessionService;
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Session\SyncPlay\SyncPlayBridge;
use Phlix\Session\SyncPlay\SyncPlayBridgePublisher;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Stats\StatsCollector;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers session-related services: device-session management,
 * the playback controller used by continue-watching, and SyncPlay
 * state management.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.10.0
 */
final class SessionServicesProvider implements ServiceProviderInterface
{
    /**
     * Register session bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.10.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        // S445 write-through bridge publisher (HTTP side). Null when
        // `syncplay_bridge.enabled` is false — the controller rail then
        // stays persist-only and the WS worker self-heals a group on its
        // next published mutation. Bounded sync unix-socket write, registered
        // in docs/dev/BLOCKING_IO_EXCEPTIONS.md; never degrades a REST
        // response. Model: docs/dev/SYNCPLAY_WRITE_THROUGH_BRIDGE.md.
        $bridgePublisher = static function (ContainerInterface $c) use ($appConfig): ?SyncPlayBridgePublisher {
            $raw = $appConfig['syncplay_bridge'] ?? [];
            $bridgeConfig = is_array($raw) ? $raw : [];
            if (!SyncPlayBridge::isEnabled($bridgeConfig)) {
                return null;
            }

            $timeoutRaw = $bridgeConfig['publish_timeout_ms'] ?? 250;
            $logger = $c->get('logger.session');

            return new SyncPlayBridgePublisher(
                SyncPlayBridge::socketPathFromConfig($bridgeConfig),
                is_numeric($timeoutRaw) ? max(1, (int) $timeoutRaw) : 250,
                $logger instanceof StructuredLogger ? $logger : null
            );
        };

        $builder->addDefinitions([
            // Was resolved by implicit autowiring, which silently skipped the
            // optional `settings` param and left the concurrent-stream default
            // pinned at DEFAULT_CONCURRENT_STREAMS — i.e.
            // `access.default_concurrent_streams` would have been inert
            // (read-path class (g)). Registered explicitly so the store is
            // actually handed over. The second construction path is
            // `Application::getStreamLimitController()`'s no-container
            // fallback, which passes its own SettingsRepository.
            StreamSessionService::class => autowire()
                ->constructorParameter('settings', get(SettingsRepository::class)),

            SessionManager::class => autowire()
                ->constructorParameter('logger', get('logger.session')),

            // `statsCollector` is wired so playback start/stop events land in
            // stats_playback_events — the source the admin dashboard's Top
            // Users / Top Media / activity widgets read from. Without it the
            // controller silently no-ops its recording (the ctor param is
            // optional) and those widgets stay empty.
            PlaybackController::class => autowire()
                ->constructorParameter('logger', get('logger.session'))
                ->constructorParameter('eventDispatcher', get(EventDispatcherInterface::class))
                ->constructorParameter('statsCollector', get(StatsCollector::class)),

            // SyncPlaySnapshotService: the REST-owned durable store for SyncPlay
            // group snapshots. S445 made it read AND write from the REST side
            // (write-through persistence); the WS worker keeps publishing its
            // own mutations into the same rows and never reads them back.
            SyncPlaySnapshotService::class => autowire(),

            // SyncPlayManager: registered as a singleton within each worker
            // process. The WS worker (count=1) owns the authoritative live
            // state, publishes snapshots after each of ITS mutations, and
            // ingests REST deltas as bridge frames (S445). HTTP workers use
            // this instance for the REST rails' read-modify-write base.
            //
            // Deliberately still NO `setSnapshotService()` call here: with one
            // the manager's WS-side broadcast helpers would fire per mutation
            // on the wrong side of the transport. REST persistence lives in the
            // controller rail (S445), not in this instance. The S415 envelope
            // pin guards the rail's exact SQL surface (two snapshot SELECTs +
            // the write-through upsert DELETE pair); routing REST traffic away
            // from the WS process remains the point - the WS worker must never
            // become a DB reader (owner ruling 2026-09-12).
            SyncPlayManager::class => autowire()
                ->constructorParameter('logger', get('logger.session')),

            // S445 bridge publisher: see $bridgePublisher above (the closure is
            // hoisted out of the definitions array purely for line budget).
            SyncPlayBridgePublisher::class => factory($bridgePublisher),
        ]);
    }
}
