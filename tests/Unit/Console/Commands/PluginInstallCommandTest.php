<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Console\Commands;

use Phlix\Admin\SettingsRepository;
use Phlix\Common\Net\SsrfGuard;
use Phlix\Console\Commands\PluginInstallCommand;
use Phlix\Plugins\Catalog\CatalogSourceResolver;
use Phlix\Plugins\Catalog\PluginCatalogService;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\PluginLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PluginInstallCommandTest extends TestCase
{
    private const DEFAULT_SOURCE = 'https://github.com/detain/phlix-plugins';
    private const CATALOG_RAW = 'https://raw.githubusercontent.com/detain/phlix-plugins/'
        . CatalogSourceResolver::OFFICIAL_PINNED_REF . '/plugins.json';

    protected function tearDown(): void
    {
        SsrfGuard::reset();
        parent::tearDown();
    }

    private function tester(PluginLoader $loader, ?PluginCatalogService $catalog = null): CommandTester
    {
        $application = new Application();
        $application->add(new PluginInstallCommand(
            fn(): PluginLoader => $loader,
            $catalog !== null ? fn(): PluginCatalogService => $catalog : null,
        ));

        return new CommandTester($application->find('plugin:install'));
    }

    /**
     * Build a real {@see PluginCatalogService} (final → cannot be mocked) over a
     * stub settings store + offline fetcher serving the given plugin entries.
     *
     * @param list<array<string, mixed>> $plugins Raw `plugins[]` entries.
     */
    private function catalogService(array $plugins): PluginCatalogService
    {
        // Catalog fetch SSRF-guards the resolved URL; pin a deterministic public IP.
        SsrfGuard::setResolver(static fn (string $host): array => ['93.184.216.34']);

        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturnCallback(
            static fn (string $key): mixed => $key === PluginCatalogService::KEY_DEFAULT_SOURCE
                ? self::DEFAULT_SOURCE
                : null,
        );
        $body = json_encode(['name' => 'Official', 'plugins' => $plugins], JSON_THROW_ON_ERROR);

        return new PluginCatalogService(
            $settings,
            static fn (string $url, int $t): string => $url === self::CATALOG_RAW
                ? $body
                : throw new \RuntimeException("unexpected catalog fetch: $url"),
        );
    }

    public function testInstallsAndPrintsManifest(): void
    {
        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-x',
            'version' => '2.0.1',
            'type' => 'metadata',
            'entry' => 'Acme\\Entry',
        ]);

        $loader = $this->createMock(PluginLoader::class);
        // No catalog factory supplied → un-pinned install (null sha + ref).
        $loader->expects($this->once())
            ->method('install')
            ->with('file:///tmp/plugin.json', null, null)
            ->willReturn($manifest);

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['source' => 'file:///tmp/plugin.json']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Installed plugin "phlix-plugin-x" version 2.0.1.', $display);
    }

    public function testThreadsCatalogPinWhenSourceIsPinned(): void
    {
        // SV-B2: with a catalog factory, a pinned entry forwards its
        // artifactSha256 + ref into install so the SV-S2b default-deny clears.
        $repo = 'https://github.com/detain/phlix-plugin-anidb';
        $sha  = str_repeat('a', 64);
        $ref  = str_repeat('b', 40);

        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-anidb',
            'version' => '2.0.0',
            'type' => 'metadata',
            'entry' => 'Acme\\Entry',
        ]);

        $catalog = $this->catalogService([[
            'name' => 'phlix-plugin-anidb',
            'repo' => $repo,
            'ref' => $ref,
            'artifactSha256' => $sha,
        ]]);

        $loader = $this->createMock(PluginLoader::class);
        $loader->expects($this->once())
            ->method('install')
            ->with($repo, $sha, $ref)
            ->willReturn($manifest);

        $tester = $this->tester($loader, $catalog);
        $exitCode = $tester->execute(['source' => $repo]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Installed plugin "phlix-plugin-anidb" version 2.0.0.', $tester->getDisplay());
    }

    public function testPassesNullPinForUnknownSourceWithCatalog(): void
    {
        // SV-B2: a source not in any catalog yields [null, null] → un-pinned
        // install (default-deny preserved).
        $url = 'https://example.com/operator.tar.gz';

        $manifest = Manifest::fromArray([
            'name' => 'phlix-plugin-op',
            'version' => '1.0.0',
            'type' => 'metadata',
            'entry' => 'Acme\\Entry',
        ]);

        // Catalog lists a different plugin → no match for $url → [null, null].
        $catalog = $this->catalogService([[
            'name' => 'phlix-plugin-other',
            'repo' => 'https://github.com/detain/phlix-plugin-other',
        ]]);

        $loader = $this->createMock(PluginLoader::class);
        $loader->expects($this->once())
            ->method('install')
            ->with($url, null, null)
            ->willReturn($manifest);

        $tester = $this->tester($loader, $catalog);
        $exitCode = $tester->execute(['source' => $url]);

        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function testInstallFailureExitsOne(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('install')
            ->willThrowException(new PluginInstallException('signature did not verify'));

        $tester = $this->tester($loader);
        $exitCode = $tester->execute(['source' => 'https://example.com/bad']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Plugin install failed: signature did not verify', $tester->getDisplay());
    }
}
