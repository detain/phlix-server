<?php

declare(strict_types=1);

namespace Phlex\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlex\Common\Container\ServiceProviderInterface;
use Phlex\Network\NatPmpClient;
use Phlex\Network\PortForwardService;
use Phlex\Network\StunClient;
use Phlex\Network\UpnpIgdClient;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\get;

/**
 * Registers the network subsystem: UPnP-IGD client, STUN client,
 * NAT-PMP client, and PortForwardService.
 *
 * @internal Phlex-internal service provider.
 *
 * @package Phlex\Common\Container\Providers
 * @since 0.11.0
 */
final class NetworkServicesProvider implements ServiceProviderInterface
{
    /**
     * Register the network bindings.
     *
     * @param ContainerBuilder<\DI\Container> $builder
     * @param array<string, mixed>            $appConfig
     *
     * @return void
     *
     * @since 0.11.0
     */
    public function register(ContainerBuilder $builder, array $appConfig): void
    {
        $pfConfig = is_array($appConfig['port_forwarding'] ?? null) ? $appConfig['port_forwarding'] : [];
        $autoEnabled = (bool) ($pfConfig['auto'] ?? true);
        $portValue = $pfConfig['port'] ?? 32400;
        $port = is_numeric($portValue) ? (int) $portValue : 32400;
        $stunServer = is_string($pfConfig['stun_server'] ?? null)
            ? $pfConfig['stun_server']
            : StunClient::DEFAULT_STUN_SERVER;
        $stunPortValue = $pfConfig['stun_port'] ?? StunClient::DEFAULT_STUN_PORT;
        $stunPort = is_numeric($stunPortValue) ? (int) $stunPortValue : StunClient::DEFAULT_STUN_PORT;
        $upnpEnabled = (bool) ($pfConfig['upnp_enabled'] ?? true);

        $builder->addDefinitions([
            UpnpIgdClient::class => autowire()
                ->constructorParameter('logger', get('logger.network')),

            StunClient::class => autowire()
                ->constructorParameter('logger', get('logger.network'))
                ->constructorParameter('stunServer', $stunServer)
                ->constructorParameter('stunPort', $stunPort),

            NatPmpClient::class => autowire()
                ->constructorParameter('logger', get('logger.network')),

            PortForwardService::class => autowire()
                ->constructorParameter('upnp', get(UpnpIgdClient::class))
                ->constructorParameter('stun', get(StunClient::class))
                ->constructorParameter('natpmp', get(NatPmpClient::class))
                ->constructorParameter('logger', get('logger.network'))
                ->constructorParameter('port', $port)
                ->constructorParameter('autoEnabled', $autoEnabled),
        ]);
    }
}
