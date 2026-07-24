<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Github;

use Phlix\Plugins\Repository\PluginSettingsRepository;

/**
 * In-memory {@see PluginSettingsRepository} for unit tests. Deliberately does NOT
 * call the parent constructor (so no DB connection is needed) and overrides every
 * method, so the parent's `$db` property is never touched.
 *
 * @internal Test fixture only.
 */
final class InMemoryPluginSettingsRepository extends PluginSettingsRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @phpstan-ignore-next-line intentionally skips parent ctor (no DB needed). */
    public function __construct()
    {
        // No parent::__construct(): the DB connection is never used here.
    }

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
