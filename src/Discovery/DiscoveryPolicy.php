<?php

/**
 * Phlix media server component: Discovery.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Discovery;

use Phlix\Admin\SettingsRepository;

/**
 * Single enforcement point for the two background multicast-probe gates.
 *
 * ## Why this class exists
 *
 * `DiscoveryServer::start()` armed its SSDP M-SEARCH (60 s) and mDNS (30 s)
 * LAN probe timers unconditionally: an operator with no LAN targets — or one
 * who switched every casting protocol off — still got periodic multicast
 * traffic every thirty seconds. `config/discovery.php` has carried
 * `ssdp.enabled` / `mdns.enabled` (both `true`) since long before this, but
 * NO code ever loaded the file, which is why the 0.28.0 consumerless audit
 * deleted the sibling `discovery_port` schema key. This policy makes the two
 * surviving flags real: settings-visible (schema keys
 * `discovery.ssdp.enabled` / `discovery.mdns.enabled`, phlix-shared
 * 568655e) and read at every gate point of `DiscoveryServer`.
 *
 * ## Enforcement points (both in DiscoveryServer)
 *
 *  - Timer registration in `start()` — a boot-time read. Disabled at boot
 *    means the process never holds the timer at all; this is why the schema
 *    flags carry `"restart": true`: re-enabling needs a process that re-runs
 *    `start()`.
 *  - The head of `performSsdpDiscovery()` / `performMdnsDiscovery()` — a
 *    per-TICK re-check, which makes switching OFF live within one interval
 *    (the timer keeps firing, the probe goes silent). The same guard covers
 *    the immediate initial probes `start()` issues.
 *
 * ## Read path
 *
 * Class (a) LIVE at the decision, class (b) RESTART at the registration, for
 * exactly the disable/enable asymmetry described above; the schema helpText
 * states it in the operator's words.
 *
 * ## Safe degradation
 *
 * A null store, an unreadable store and an unparseable value all yield
 * {@see self::DEFAULT_ENABLED} (true) — pure opt-out: the shipped default
 * reproduces the pre-key behavior byte-for-byte, and a settings outage must
 * never masquerade as "the operator silenced discovery".
 *
 * ## Scope boundary (honest)
 *
 * This gates ONLY the periodic BACKGROUND probes owned by DiscoveryServer.
 * The SSDP advertiser that announces Phlix to TVs is `dlna.enabled`
 * (SsdpAdvertiser), and the request-time casting lookups are
 * `casting.{chromecast,roku,airplay}.enabled` — those keep their own
 * switches; nothing here touches them.
 *
 * @package Phlix\Discovery
 * @since 1.10.0
 */
final class DiscoveryPolicy
{
    /**
     * The dotted settings key backing {@see self::ssdpEnabled()}.
     */
    public const SETTING_KEY_SSDP = 'discovery.ssdp.enabled';

    /**
     * The dotted settings key backing {@see self::mdnsEnabled()}.
     */
    public const SETTING_KEY_MDNS = 'discovery.mdns.enabled';

    /**
     * Shipped default for both keys, matching `config/discovery.php` and the
     * unconditional behaviour these gates replaced (pure opt-out).
     */
    public const DEFAULT_ENABLED = true;

    /**
     * @param SettingsRepository|null $settings Effective-settings store. NULL
     *        degrades to {@see self::DEFAULT_ENABLED} for both keys.
     *
     *        NOTE for DI: PHP-DI SKIPS optional constructor parameters during
     *        autowiring, so any binding that needs a configured policy must
     *        name this parameter explicitly. Left unnamed, the settings are
     *        inert by construction.
     */
    public function __construct(
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    /**
     * May the background SSDP M-SEARCH probe run right now?
     *
     * @return bool False ONLY when an override explicitly and readably says so.
     *
     * @since 1.10.0
     */
    public function ssdpEnabled(): bool
    {
        return $this->enabledFor(self::SETTING_KEY_SSDP);
    }

    /**
     * May the background mDNS query probe run right now?
     *
     * @return bool False ONLY when an override explicitly and readably says so.
     *
     * @since 1.10.0
     */
    public function mdnsEnabled(): bool
    {
        return $this->enabledFor(self::SETTING_KEY_MDNS);
    }

    /**
     * Read one gate key, degrading to the shipped default on any outage.
     *
     * @param string $key One of the two SETTING_KEY_* constants.
     */
    private function enabledFor(string $key): bool
    {
        if ($this->settings === null) {
            return self::DEFAULT_ENABLED;
        }

        try {
            /** @var mixed $configured */
            $configured = $this->settings->getEffective($key);
        } catch (\Throwable) {
            // A settings-store failure must never silence LAN discovery on
            // its own — that would present as devices "randomly" vanishing
            // with no admin control showing anything wrong.
            return self::DEFAULT_ENABLED;
        }

        return self::coerce($configured);
    }

    /**
     * Interpret a persisted value as a boolean.
     *
     * The schema types both keys `bool`, and {@see SettingsRepository} decodes
     * a `bool` row to a real PHP bool, so the `is_bool()` arm covers the
     * normal path. The rest exists because a `server_settings` row can also be
     * written by direct SQL, a restored backup or a row orphaned by a renamed
     * key, and such a row can carry any of the textual spellings below.
     * Anything not recognised falls back to the default rather than being
     * coerced by PHP's loose truthiness — `(bool) 'false'` is TRUE, precisely
     * the silent misreading this method prevents. Same table as
     * {@see \Phlix\Media\Storage\ArtworkDownloadPolicy::coerce()}.
     *
     * @param mixed $configured Raw effective value.
     */
    private static function coerce(mixed $configured): bool
    {
        if (is_bool($configured)) {
            return $configured;
        }

        if (is_int($configured)) {
            return $configured !== 0;
        }

        if (is_string($configured)) {
            return match (strtolower(trim($configured))) {
                '1', 'true', 'yes', 'on'      => true,
                '0', 'false', 'no', 'off', '' => false,
                default                       => self::DEFAULT_ENABLED,
            };
        }

        return self::DEFAULT_ENABLED;
    }
}
