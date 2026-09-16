<?php

/**
 * Phlix media server component: Plugins.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Theming\Exception\InvalidThemeDefinition;
use Phlix\Theming\ThemeSourceInterface;
use Phlix\Theming\ThemeSourceRegistry;
use Throwable;

/**
 * Makes every worker's copy of the process-scoped {@see ThemeSourceRegistry}
 * converge on the shared `plugins` table without a restart (S498).
 *
 * ## The defect this closes (W99 live run)
 *
 * The HTTP pool runs 14 Workerman workers, each with its own container and
 * therefore its own {@see ThemeSourceRegistry}. {@see PluginLoader::enable()},
 * {@see PluginLoader::disable()} and {@see PluginLoader::uninstall()} mutate
 * the durable `plugins` table AND the registry of the ONE worker that served
 * the admin request — the other 13 keep serving the stale theme catalogue from
 * their process memory until the process recycles. Live-measured: after an
 * app-flow uninstall, ~6/30 sampled `GET /api/v1/themes` still carried the
 * uninstalled plugin's themes; only a full restart re-unified the pool.
 *
 * ## The mechanism (per-request version stamp, theme-arm rebuild)
 *
 * The durable truth of every lifecycle mutation already lives in one tiny,
 * shared table. `plugins` is a handful of rows with a UNIQUE `name` key, and
 * every mutation path writes it. So instead of inventing an inter-worker
 * broadcast channel a plain `http://` worker group does not have, each worker
 * re-derives a STAMP from that shared table on the next theme request:
 *
 *  - {@see flushIfStale()} is called by {@see \Phlix\Server\Http\Controllers\ThemesController}
 *    before it serves; it folds `name:enabled:version` of every installed row
 *    into one sha1 and compares it with the stamp this process last observed.
 *  - Equal (the overwhelmingly common case) → zero rebuild work; the request
 *    costs one indexed read of a ≤tens-of-rows table on an auth-gated,
 *    low-QPS endpoint — NOT a per-request heavy scan.
 *  - Different → this worker's theme registry is stale: it is rebuilt from
 *    durable truth (clear, then re-register every currently-enabled theme
 *    source), and the served bytes re-converge pool-wide on the very next
 *    request each worker handles — far inside the bounded freshness window,
 *    with no restart and no new infrastructure.
 *  - First call per process reconciles too: the stamp starts as a sentinel
 *    (`null`), and `stamp()` always yields a 40-hex sha1 that can never equal
 *    it. Boot's {@see PluginLoader::bootstrapEnabled()} only reflects the
 *    generation `onWorkerStart` happened to see, so a worker whose first theme
 *    request lands AFTER a peer mutation would otherwise adopt that foreign
 *    stamp as baseline and serve its stale boot generation stamp-equal forever
 *    (S498's blind baseline capture; the S499 cold-worker hole). Rebuilding
 *    from durable truth on that first call closes it at zero duplicate cost:
 *    {@see rebuild()} clears then re-registers each enabled source once, so when
 *    boot already equalled durable the extra rebuild is byte-idempotent.
 *
 * ## Why the stamp is derived from `plugins` itself (not a separate epoch row)
 *
 * The same UPDATE that performs the mutation changes the stamp atomically —
 * there is no second write that could fail or race, and no window where a
 * bump lands after a worker's last check and before its next one forever
 * (an epoch counter written separately can miss exactly that, permanently).
 *
 * ## Scope: the theme arm only
 *
 * This reconciles {@see ThemeSourceRegistry} — the surface with a live,
 * directly observable staleness symptom (`GET /api/v1/themes`), which is
 * what S498 targets. The rebuild path deliberately does NOT run
 * `onEnable()`, subscribe events, or touch the metadata/subtitle/writer
 * registries: those keep their existing boot-time-only wiring, byte-for-byte
 * unchanged behavior. A settings-only `updateSettings()` does not move the
 * stamp — faithfully so: even the handling worker's registry is not refreshed
 * by an settings update today.
 *
 * ## Failure posture
 *
 * Per-plugin failures are logged and skipped (one broken plugin must not
 * empty the catalogue in the fleet, mirroring
 * {@see PluginLoader::bootstrapEnabled()}), and an invalid theme payload
 * stays refused by {@see ThemeSourceRegistry::register()} exactly as at
 * enable time — the rebuild admits nothing the live path would have refused.
 */
final class ThemeRegistryFleetSync
{
    /**
     * Correlation marker stamped on every fleet-flush log line, so an operator
     * grepping the live log tail can prove which worker flushed and when.
     */
    public const FLUSH_MARKER = 'S498WORKERFLUSHX9P3';

    /**
     * Correlation marker added to the log context of the ONE flush per process
     * that reconciled a COLD worker (see {@see flushIfStale()}). An operator
     * grepping the live log tail proves that no worker ever baseline-captured a
     * foreign-current stamp and then served a stale boot generation forever
     * (the S499 F1 hole): every freshly booted worker's first theme request
     * carries this marker exactly once.
     */
    public const BOOT_PRIME_MARKER = 'S499BOOTPRIMEX9P4';

    /**
     * Stamp this process last reconciled against; the sentinel {@see null}
     * means "never reconciled". {@see stamp()} always returns a 40-hex sha1, so
     * the sentinel can never equal a real stamp — which is precisely what makes
     * the first check reconcile rather than adopt.
     */
    private ?string $stamp = null;

    public function __construct(
        private readonly PluginLoader $loader,
        private readonly ThemeSourceRegistry $registry,
        private readonly StructuredLogger $logger,
    ) {
    }

    /**
     * Rebuild this worker's theme registry if the shared plugin state moved.
     *
     * The stamp sentinel (see the property docblock) makes the worker's FIRST
     * served call reconcile exactly like any later stamp move: boot's
     * `bootstrapEnabled()` reflects whatever generation `onWorkerStart` saw, so
     * adopting that stamp blind (S498's original baseline capture) would strand
     * a worker whose registry predates a peer mutation that landed before its
     * first request — stamp-equal, and stale, until the process recycled.
     * Rebuilding from durable truth on that first call costs no duplicates:
     * {@see rebuild()} clears then re-registers each enabled source once, and
     * when boot already equalled durable the rebuild is byte-idempotent.
     *
     * @return bool True when a rebuild ran (the stamp moved, or this process had
     *              never reconciled); false when the registry already matched the
     *              current durable stamp.
     */
    public function flushIfStale(): bool
    {
        $current = $this->stamp();

        if ($this->stamp === $current) {
            return false;
        }

        $previous = $this->stamp;
        $this->rebuild();
        // Commit the adopted stamp only AFTER the rebuild completes, so the
        // invariant "stamp == current ⟹ this process is reconciled to it" holds
        // even if a future runtime interleaves requests mid-rebuild (S499 F5).
        $this->stamp = $current;

        $context = [
            'marker' => self::FLUSH_MARKER,
            'from' => $previous,
            'to' => $current,
            'sources' => $this->registry->sourceNames(),
        ];
        if ($previous === null) {
            // The cold-worker reconcile (F1 fix): tagged so the log alone proves
            // no worker ever baseline-captured a foreign generation.
            $context['boot_prime'] = self::BOOT_PRIME_MARKER;
        }

        $this->logger->info('theme-source registry fleet flush', $context);

        return true;
    }

    /**
     * Fold the shared `plugins` table into one comparable stamp.
     *
     * `name:enabled:version` per installed row — the three columns every
     * lifecycle mutation writes through (install/insert, enable/disable,
     * uninstall/delete, version update). Both list queries are
     * `ORDER BY name ASC`, so the fold is deterministic.
     */
    private function stamp(): string
    {
        $parts = [];
        foreach ($this->loader->listInstalled() as $plugin) {
            $parts[] = $plugin->name() . ':' . ($plugin->enabled ? '1' : '0') . ':' . $plugin->manifest->version;
        }

        return sha1(implode('|', $parts));
    }

    /**
     * Replace this worker's theme registry contents with what the durable
     * enabled set says should be there right now.
     *
     * Entry instances come from {@see PluginLoader::getEntryInstance()} —
     * autoload + instantiate + deliver persisted settings, but NO `onEnable()`
     * and NO event subscriptions: this is the theme arm's serving state, not
     * a second full enable. Sources that no longer exist in the enabled set
     * fall out by construction: the registry was just cleared.
     */
    private function rebuild(): void
    {
        $this->registry->clear();

        foreach ($this->loader->getEnabled() as $plugin) {
            $name = $plugin->name();

            try {
                $instance = $this->loader->getEntryInstance($name);
            } catch (Throwable $e) {
                // Row vanished mid-loop or the entry cannot be built — log and
                // continue; one broken plugin must not starve the catalogue.
                $this->logger->warning('theme fleet flush: entry instance failed to load', [
                    'plugin' => $name,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (!$instance instanceof ThemeSourceInterface) {
                continue;
            }

            try {
                $this->registry->register($instance);
            } catch (InvalidThemeDefinition $e) {
                // Same all-or-nothing door as enable(): this source contributes
                // nothing, the others still serve.
                $this->logger->error('theme fleet flush: plugin theme refused validation', [
                    'plugin' => $name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
