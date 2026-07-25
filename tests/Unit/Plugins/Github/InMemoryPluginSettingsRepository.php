<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Plugins\Repository\PluginSettingsStore;

/**
 * In-memory {@see PluginSettingsStore} for unit tests.
 *
 * Implements the contract (S48 review r1, Finding 7) rather than extending the
 * concrete {@see \Phlix\Plugins\Repository\PluginSettingsRepository} and skipping
 * its constructor behind a phpstan suppression — no DB connection is involved.
 *
 * @internal Test fixture only.
 */
final class InMemoryPluginSettingsRepository implements PluginSettingsStore
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function get(string $pluginName): ?array
    {
        return $this->rows[$pluginName] ?? null;
    }

    public function save(string $pluginName, array $settings): void
    {
        $this->rows[$pluginName] = $settings;
    }

    public function exists(string $pluginName): bool
    {
        return isset($this->rows[$pluginName]);
    }
}
