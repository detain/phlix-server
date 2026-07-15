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
use Phlix\Discovery\DiscoveryManager;
use Phlix\Discovery\Mdns\MdnsDiscovery;
use Phlix\Discovery\Mdns\MdnsSocket;
use Phlix\Discovery\Ssdp\SsdpDiscovery;
use Phlix\Discovery\Ssdp\SsdpSocket;

use function DI\autowire;
use function DI\get;

/**
 * Registers the discovery subsystem: SSDP, mDNS, and the unified DiscoveryManager.
 *
 * Phase 10 (DLNA/UPnP) requires DiscoveryManager to be injected into CdsServer
 * so that the server can announce itself via both SSDP NOTIFY and mDNS.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
 * @since 0.12.0
 */
final class DiscoveryServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the discovery bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $builder->addDefinitions([
            // Raw sockets - autowirable with optional logger injection
            SsdpSocket::class => autowire()
                ->constructorParameter('logger', get('logger.dlna')),

            MdnsSocket::class => autowire()
                ->constructorParameter('logger', get('logger.dlna')),

            // Discovery services
            SsdpDiscovery::class => autowire()
                ->constructorParameter('logger', get('logger.dlna')),

            MdnsDiscovery::class => autowire()
                ->constructorParameter('logger', get('logger.dlna')),

            // Unified discovery manager - facade over both SSDP and mDNS
            DiscoveryManager::class => autowire()
                ->constructorParameter('logger', get('logger.dlna')),
        ]);
    }
}
