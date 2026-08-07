<?php

/**
 * Phlix media server component: Hub.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;

/**
 * The relay-tunnel fork's boot decision, extracted from `start.php` so it can
 * be unit-tested (S39).
 *
 * `start.php` runs OUTSIDE PHPUnit — nothing in the suite can execute it — so
 * the kill-switch logic that used to live inline in the `phlix-relay-tunnel`
 * `onWorkerStart` closure was, by construction, unpinnable. It was also WRONG:
 * the `PHLIX_RELAY_DISABLED` env var and the persisted `relay-control.json`
 * flag only gated {@see RelayConfig::withAutoEnable()} (the URL derivation),
 * while `$consumer->start()` ran unconditionally. With `hub_relay_ws_url` /
 * `PHLIX_RELAY_HUB_WS_URL` set — which `config/relay.php` actively recommends
 * for TLS deployments — the derivation was irrelevant anyway, so the admin
 * "Disable" control was a COMPLETE no-op while the endpoint told the operator
 * the tunnel would disconnect on the next reload.
 *
 * This class holds that decision (pure, testable) plus the ONE side effect it
 * owes: when the operator kill-switch is set the fork must persist an HONEST
 * state to `relay-tunnel.state.json` instead of leaving whatever the previous
 * run wrote there, so `/api/v1/health/relay` and the admin relay status/ping
 * endpoints report "down, because you disabled it" rather than a stale
 * `connected: true` or a misleading reconnect error.
 *
 * @package Phlix\Hub
 * @since 0.20.0
 */
final class RelayTunnelBoot
{
    /**
     * The reason persisted (and surfaced by the health endpoints) when the
     * tunnel is suppressed by the operator kill-switch.
     */
    public const DISABLED_REASON = 'relay disabled by operator kill-switch';

    /**
     * Env-var spellings that count as "disabled" for `PHLIX_RELAY_DISABLED`.
     *
     * @var list<string>
     */
    private const TRUTHY = ['1', 'true', 'yes', 'on'];

    /**
     * Whether the `PHLIX_RELAY_DISABLED` env var value disables the tunnel.
     *
     * `getenv()` returns `false` when unset, so that shape is accepted directly
     * and treated as "not disabled".
     *
     * @param string|false|null $raw Raw env value (typically `getenv('PHLIX_RELAY_DISABLED')`).
     *
     * @return bool `true` when the env var is set to a truthy spelling.
     *
     * @since 0.20.0
     */
    public static function envDisables(string|false|null $raw): bool
    {
        if (!is_string($raw)) {
            return false;
        }

        return in_array(strtolower(trim($raw)), self::TRUTHY, true);
    }

    /**
     * Whether an operator kill-switch is active: the env var OR the persisted
     * `relay-control.json` flag the admin Disable control writes.
     *
     * @param string|false|null $envRaw Raw `PHLIX_RELAY_DISABLED` value.
     * @param RelayStateStore   $store  The fork's cross-process state store.
     *
     * @return bool `true` when either lever is set.
     *
     * @since 0.20.0
     */
    public static function isOperatorDisabled(string|false|null $envRaw, RelayStateStore $store): bool
    {
        return self::envDisables($envRaw) || $store->isRelayDisabled();
    }

    /**
     * The relay fork's boot gate: may the tunnel be started?
     *
     * Returns `false` when the operator kill-switch is set — and, before doing
     * so, persists the honest "disabled" tunnel state so every reader of
     * `relay-tunnel.state.json` (the `/api/v1/health/relay` endpoint and the
     * admin relay status/ping endpoints, all in the HTTP worker) sees
     * `connected: false, active: false` with {@see DISABLED_REASON} rather than
     * a stale snapshot from the last enabled run.
     *
     * Deliberately does NOT gate on enrollment: a missing enrollment is a
     * different condition that {@see RelayConsumer::connect()} already reports
     * with its own persisted reason, and swallowing it here would lose that.
     *
     * @param bool                  $operatorDisabled Result of {@see isOperatorDisabled()}.
     * @param RelayStateStore       $store            The fork's state store.
     * @param StructuredLogger|null $logger           Optional hub logger.
     *
     * @return bool `true` when the caller should start the tunnel.
     *
     * @since 0.20.0
     */
    public static function allowBoot(
        bool $operatorDisabled,
        RelayStateStore $store,
        ?StructuredLogger $logger = null,
    ): bool {
        if (!$operatorDisabled) {
            return true;
        }

        $store->writeRelayState(self::disabledState());

        $logger?->warning('RelayTunnelBoot: relay tunnel suppressed by operator kill-switch', [
            'reason' => self::DISABLED_REASON,
        ]);

        return false;
    }

    /**
     * The state payload persisted when the tunnel is suppressed.
     *
     * Mirrors {@see RelayConsumer} 's own `writeRelayState()` key set exactly so
     * the readers need no special case; `updatedAt` is stamped by the store.
     *
     * @return array<string, mixed> The honest "disabled by operator" state.
     *
     * @since 0.20.0
     */
    public static function disabledState(): array
    {
        return [
            'connected' => false,
            'active' => false,
            'reconnectAttempts' => 0,
            'activeSessions' => 0,
            'lastDisconnectTime' => null,
            'lastConnectError' => self::DISABLED_REASON,
            'lastConnectErrorAt' => (new \DateTimeImmutable())->format('c'),
        ];
    }
}
