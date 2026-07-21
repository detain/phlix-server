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
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Logger\LogChannels;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\CdsServer;
use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\DlnaServer;
use Phlix\Dlna\LibraryBridge;
use Phlix\Dlna\SsdpAdvertiser;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\HlsStreamer;
use Psr\Container\ContainerInterface;

use function DI\factory;

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
                    // strip any scheme/trailing slash an operator adds out of
                    // habit rather than shipping a broken description.
                    $host = is_string($dlna['advertise_host'] ?? null) && ($dlna['advertise_host'] ?? '') !== ''
                        ? (string) $dlna['advertise_host']
                        : SsdpAdvertiser::detectLocalIp();
                    $host = rtrim(preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $host) ?? $host, '/');

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
                    $server->setLibraryBridge(new LibraryBridge($items, $hls, $logger));

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
