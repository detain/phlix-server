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
use Phlix\Admin\SettingsRepository;
use Phlix\Common\Container\DegradedBuild;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Config\EffectiveConfig;
use Phlix\Discovery\DiscoveryPolicy;
use Phlix\Discovery\DiscoveryServer;
use Phlix\Dlna\CdsServer;
use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\DlnaAdvertisedHost;
use Phlix\Dlna\DlnaServer;
use Phlix\Dlna\LibraryBridge;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Music\MusicLibraryService;
use Phlix\Media\Streaming\HlsStreamer;
use Psr\Container\ContainerInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * Registers the DLNA/UPnP MediaServer: {@see DlnaServer}, {@see CdsServer} and
 * the {@see ContentDirectory} they share.
 *
 * ## Why this provider exists
 *
 * Before 1.3.0 none of these were registered anywhere. `DlnaServer`'s
 * constructor takes three un-autowirable `string` parameters (`$serverId`,
 * `$friendlyName`, `$baseUrl`), so PHP-DI could not build it —
 * `Application::loadCdsRoutes()` resolved `CdsServer` inside a bare
 * `catch (\Throwable)`, that resolution always threw, and the exception was
 * swallowed. The result was that **no DLNA browse route was ever registered**,
 * while the SSDP advertiser happily broadcast a LOCATION pointing at a stale
 * static file. Verified on production 2026-07-21.
 *
 * ## The single-instance requirement
 *
 * `DlnaServer` builds its OWN `ContentDirectory` internally and only serves
 * real library data once {@see DlnaServer::setLibraryBridge()} has been called
 * on it. `Application::loadCdsRoutes()` separately resolves
 * `ContentDirectory::class` from the container for the SOAP controller — so if
 * that resolved to a DIFFERENT, autowired instance, `/dlna/content_directory`
 * would serve STUB data while `/cds/control` served real data, which is the
 * kind of split-brain that is very hard to spot from the outside.
 *
 * `ContentDirectory::class` is therefore bound to
 * `DlnaServer::getContentDirectory()` — the same object, bridge already
 * attached.
 *
 * ## Note on being registered but off
 *
 * Registering these does NOT expose anything by itself. The routes are gated on
 * `dlna.cds_enabled`, which ships FALSE because DLNA has no authentication.
 * See `config/dlna.php`.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 1.3.0
 */
final class DlnaServicesProvider implements ServiceProviderInterface
{
    /**
     * Register DLNA services.
     *
     * @param array<string, mixed> $appConfig Assembled boot config.
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $serverConfig = is_array($appConfig['server'] ?? null) ? $appConfig['server'] : [];
        $port = is_int($serverConfig['port'] ?? null) ? $serverConfig['port'] : 8096;

        $builder->addDefinitions([
            DlnaServer::class => factory(
                static function (ContainerInterface $c) use ($port): DlnaServer {
                    $dlna = EffectiveConfig::file('dlna');

                    $friendlyName = is_string($dlna['friendly_name'] ?? null)
                        && ($dlna['friendly_name'] ?? '') !== ''
                            ? (string) $dlna['friendly_name']
                            : 'Phlix Media Server';

                    $serverId = is_string($dlna['server_id'] ?? null) && ($dlna['server_id'] ?? '') !== ''
                        ? (string) $dlna['server_id']
                        : self::derivedServerId();

                    // A BARE HOST, never a URL: DlnaServer::getBaseUrl() builds
                    // "http://{host}:{port}" itself, so a `http://…` value here
                    // would produce "http://http://…". The constructor
                    // parameter is misleadingly named $baseUrl in that class;
                    // any scheme/trailing slash an operator adds out of habit is
                    // stripped rather than shipping a broken description.
                    //
                    // S53: this resolution moved into DlnaAdvertisedHost so the
                    // SSDP LOCATION reads the SAME setting. It previously did
                    // not — see that class's docblock.
                    $host = DlnaAdvertisedHost::fromValue($dlna['advertise_host'] ?? null);

                    /** @var StructuredLogger $logger */
                    $logger = LoggerFactory::get(LogChannels::DLNA);
                    /** @var ItemRepository $items */
                    $items = $c->get(ItemRepository::class);

                    $server = new DlnaServer(
                        $serverId,
                        $friendlyName,
                        $host,
                        $port,
                        $items,
                        $logger,
                    );

                    // Without this the ContentDirectory falls back to STUB data
                    // and a TV browses an empty/fake library. It is not
                    // optional decoration.
                    /** @var HlsStreamer $hls */
                    $hls = $c->get(HlsStreamer::class);

                    // S97: without this the Audio category can only list artists —
                    // their albums and tracks are unreachable, because the music
                    // hierarchy lives in `music_*` and never in
                    // `media_items.parent_id`. Optional so a container that cannot
                    // build it degrades to an empty artist container rather than
                    // failing DLNA startup outright.
                    $musicLibrary = null;
                    try {
                        /** @var MusicLibraryService $musicLibrary */
                        $musicLibrary = $c->get(MusicLibraryService::class);
                    } catch (\Throwable $e) {
                        // Degrading to an empty artist container is the right call —
                        // it beats failing DLNA startup outright — but it is a real,
                        // user-visible loss: the Audio category lists artists whose
                        // albums and tracks are then unreachable. Nothing else in
                        // this factory resolves MusicLibraryService, so this catch
                        // is genuinely reachable.
                        $musicLibrary = null;
                        DegradedBuild::warnUnlessAbsent(
                            $c,
                            LogChannels::DLNA,
                            'MusicLibraryService could not be built: the DLNA Audio category will '
                            . 'list artists but their albums and tracks will be unreachable.',
                            $e
                        );
                    }

                    // The FIFTH argument is what makes S53's `<res>` resolvable:
                    // `getBaseUrl()` is the very string the device description
                    // advertises as its origin, so the stream URL inside every
                    // Browse response, the description's own service URLs and
                    // the SSDP LOCATION are one host by construction. Passing it
                    // explicitly (rather than letting LibraryBridge resolve it)
                    // also means the bridge cannot pick a different port from
                    // the one this server is actually listening on.
                    $server->setLibraryBridge(
                        new LibraryBridge($items, $hls, $logger, $musicLibrary, $server->getBaseUrl())
                    );

                    return $server;
                }
            ),

            // Bind to the DlnaServer's OWN instance — see the class docblock.
            // An autowire() here would silently create a second, bridge-less
            // ContentDirectory and split the browse paths.
            ContentDirectory::class => factory(
                static fn (ContainerInterface $c): ContentDirectory
                    => self::dlnaServer($c)->getContentDirectory()
            ),

            CdsServer::class => factory(
                static function (ContainerInterface $c): CdsServer {
                    /** @var StructuredLogger $logger */
                    $logger = LoggerFactory::get(LogChannels::DLNA);

                    // DiscoveryManager is deliberately NOT passed: SSDP
                    // announcement is owned by the phlix-dlna-ssdp worker, and
                    // handing CdsServer a second announcer would put two
                    // advertisers on the multicast group.
                    return new CdsServer(self::dlnaServer($c), null, $logger);
                }
            ),
            // W2 background-probe gates. DiscoveryServer stays AUTOWIRED for
            // everything else (its manager tree — SsdpDiscovery/MdnsDiscovery
            // over their sockets — has only optional/defaulted parameters);
            // this binding names ONLY the policy, because PHP-DI skips
            // optional constructor params and leaving `policy` unnamed would
            // make the two settings inert by construction (the exact
            // 2e63b30-class trap the MediaServicesProvider policy bindings
            // document). The boot-time fallback autowire still works: a
            // container built WITHOUT this provider yields the default
            // DiscoveryPolicy (both probes on = historical behaviour).
            DiscoveryPolicy::class => factory(
                static fn (ContainerInterface $c): DiscoveryPolicy => new DiscoveryPolicy(self::optionalSettings($c))
            ),
            DiscoveryServer::class => autowire(DiscoveryServer::class)
                ->constructorParameter('policy', get(DiscoveryPolicy::class)),
        ]);
    }

    /**
     * Resolve the shared {@see DlnaServer}, narrowed from the container's
     * `mixed` return.
     *
     * A real runtime check rather than an `@var` suppression: if the container
     * ever hands back something else, the DLNA subsystem is misconfigured and
     * should say so loudly. Silent degradation is what let the previous DLNA
     * defect hide for months.
     */
    private static function dlnaServer(ContainerInterface $c): DlnaServer
    {
        $server = $c->get(DlnaServer::class);
        if (!$server instanceof DlnaServer) {
            throw new \RuntimeException(
                'Container returned ' . get_debug_type($server) . ' for DlnaServer; DLNA cannot start.'
            );
        }

        return $server;
    }

    /**
     * Resolve the settings store, NULL when it is not bound at all.
     *
     * Same shape as MediaServicesProvider::optionalSettings(): the store is
     * OPTIONAL by design — a container built without the admin subsystem
     * (tests, trimmed boots) must still produce a working policy on its
     * shipped defaults, while a store that IS bound but fails to build is
     * loudly degraded via {@see DegradedBuild} rather than silently ignored.
     */
    private static function optionalSettings(ContainerInterface $c): ?SettingsRepository
    {
        try {
            $settings = $c->get(SettingsRepository::class);

            return $settings instanceof SettingsRepository ? $settings : null;
        } catch (\Throwable $e) {
            DegradedBuild::warnUnlessAbsent(
                $c,
                LogChannels::MEDIA,
                'The settings store is bound but could not be built; the background '
                . 'SSDP/mDNS discovery gates fall back to their shipped defaults '
                . '(both probes on). Admin-saved discovery settings stay ignored by '
                . 'this worker until it is recycled.',
                $e
            );

            return null;
        }
    }

    /**
     * A stable UDN suffix derived from the host name.
     *
     * Control points cache devices by UDN, so this MUST be identical across
     * restarts — a randomly generated id would make the server reappear as a
     * new device on every boot and accumulate duplicates in every TV's list.
     * Hashing the host name is deterministic and needs no persisted state.
     */
    private static function derivedServerId(): string
    {
        $host = gethostname();

        return substr(md5(is_string($host) && $host !== '' ? $host : 'phlix'), 0, 16);
    }
}
