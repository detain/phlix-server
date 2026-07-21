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
use Phlix\Session\PlaybackController;
use Phlix\Session\SessionManager;
use Phlix\Session\SyncPlay\SyncPlayManager;
use Phlix\Session\SyncPlay\SyncPlaySnapshotService;
use Phlix\Stats\StatsCollector;
use Psr\EventDispatcher\EventDispatcherInterface;

use function DI\autowire;
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

            // SyncPlaySnapshotService: reads SyncPlay state snapshots from DB.
            // The authoritative state lives in the WS worker's SyncPlayManager;
            // this service provides a read-only view for HTTP/REST workers.
            SyncPlaySnapshotService::class => autowire(),

            // SyncPlayManager: registered as a singleton within each worker
            // process. The WS worker (count=1) owns the authoritative state
            // and publishes snapshots after each mutation. HTTP workers use
            // this for local state only (mutations are deprecated in REST).
            SyncPlayManager::class => autowire()
                ->constructorParameter('logger', get('logger.session')),
        ]);
    }
}
