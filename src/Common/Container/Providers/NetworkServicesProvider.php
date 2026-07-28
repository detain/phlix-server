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
use Phlix\Config\EffectiveConfig;
use Phlix\Network\NatPmpClient;
use Phlix\Network\PortForwardService;
use Phlix\Network\StunClient;
use Phlix\Network\UpnpIgdClient;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\get;

/**
 * Registers the network subsystem: UPnP-IGD client, STUN client,
 * NAT-PMP client, and PortForwardService.
 *
 * @internal Phlix-internal service provider.
 *
 * @package Phlix\Common\Container\Providers
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
        // Read through EffectiveConfig, NOT $appConfig.
        //
        // config/server.php does not compose config/port-forward.php, so
        // $appConfig['port_forwarding'] was PERMANENTLY absent and every value
        // below silently took its `??` literal. That made the shipped admin
        // setting `port-forward.port_forwarding.upnp_enabled` inert in both
        // directions: SettingsRepository resolved its default straight from
        // config/port-forward.php (so SettingsDefaultResolvabilityTest passed and
        // always would), while this provider — the only consumer — never saw the
        // file at all. EffectiveConfig::overlayAppConfig() could not rescue it
        // either, because it refuses to create keys that do not already exist.
        //
        // EffectiveConfig::file() loads the file AND applies any `port-forward.*`
        // override, so these values are now genuinely admin-controlled (on reload).
        $pfFile = EffectiveConfig::file('port-forward');
        $pfConfig = is_array($pfFile['port_forwarding'] ?? null) ? $pfFile['port_forwarding'] : [];
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
                ->constructorParameter('autoEnabled', $autoEnabled)
                // Until now $upnpEnabled was computed above and then dropped on
                // the floor — it reached no definition, and PortForwardService
                // had no UPnP switch at all. `upnp_enabled` was therefore inert
                // in effect even though EffectiveConfig resolved it correctly:
                // an admin could save `false` and UPnP discovery still ran, on
                // every restart, forever. Wiring it is what makes the key's
                // `"restart": true` (phlix-shared v0.47.0) an honest promise
                // rather than a relabelled no-op.
                ->constructorParameter('upnpEnabled', $upnpEnabled),
        ]);
    }
}
