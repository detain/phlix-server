<?php

declare(strict_types=1);

/**
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license MIT
 */

namespace Tests\Unit\Plugins\Scrobbler\Trakt;

use Phlix\Admin\SettingsRepository;
use Phlix\Auth\WatchHistory;
use Phlix\Media\Library\ItemRepository;
use Phlix\Plugins\Scrobbler\Trakt\TraktPlugin;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * Asserts the CONSEQUENCE of wiring the settings overlay into `TraktPlugin`.
 *
 * ## Why `$api` is the right thing to assert
 *
 * `TraktPlugin::initApi()` constructs a `TraktApi` only when it has a non-empty
 * client id AND secret; otherwise `$this->api` stays null. `isConfigured()`
 * then returns false and every scrobble and every pull-sync tick is a silent
 * no-op — nothing is logged, so the failure presents to the operator as "Trakt
 * says connected but nothing ever syncs".
 *
 * So a non-null `$api` after `onEnable()` is not an implementation detail; it
 * is the exact observable that separates a working install from the broken one
 * this change fixes.
 *
 * The pair of tests below is the discriminating one: identical inputs except
 * for whether the container exposes a `SettingsRepository`. Before the fix,
 * BOTH produced a null `$api`.
 */
final class TraktPluginCredentialOverlayTest extends TestCase
{
    /**
     * Read the plugin's private `$api`, which is what `isConfigured()` gates on.
     */
    private function apiOf(TraktPlugin $plugin): mixed
    {
        $prop = new ReflectionProperty(TraktPlugin::class, 'api');
        $prop->setAccessible(true);

        return $prop->getValue($plugin);
    }

    /**
     * A host container exposing the collaborators `onEnable()` resolves.
     *
     * @param SettingsRepository|null $settings When null, `has()` reports the
     *                                          binding as absent — the
     *                                          no-database shape.
     */
    private function container(?SettingsRepository $settings): ContainerInterface
    {
        $entries = [
            LoggerInterface::class => new NullLogger(),
            ItemRepository::class  => $this->createMock(ItemRepository::class),
            WatchHistory::class    => $this->createMock(WatchHistory::class),
        ];
        if ($settings !== null) {
            $entries[SettingsRepository::class] = $settings;
        }

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(
            static fn (string $id): bool => array_key_exists($id, $entries)
        );
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $entries[$id] ?? null
        );

        return $container;
    }

    /**
     * Settings stub holding admin-saved operator credentials.
     *
     * @param array<string, mixed> $rows
     */
    private function settingsWith(array $rows): SettingsRepository
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getOverride')->willReturnCallback(
            static fn (string $key): ?array =>
                array_key_exists($key, $rows) ? ['value' => $rows[$key]] : null
        );

        return $repo;
    }

    /**
     * Guards the premise of the whole test: the real config file must NOT
     * already supply credentials, or the pair below could not discriminate.
     *
     * `config/scrobblers/trakt.php` reads `getenv('TRAKT_CLIENT_ID')` with an
     * `''` fallback. If a developer runs the suite with those env vars set,
     * the plugin would build an API from the environment regardless of the
     * overlay and the "without settings" case would pass for the wrong reason.
     */
    public function testTraktEnvCredentialsAreAbsentSoTheTestsCanDiscriminate(): void
    {
        self::assertSame('', getenv('TRAKT_CLIENT_ID') ?: '', 'unset TRAKT_CLIENT_ID to run this suite');
        self::assertSame('', getenv('TRAKT_CLIENT_SECRET') ?: '', 'unset TRAKT_CLIENT_SECRET to run this suite');
    }

    public function testAdminSavedCredentialsBuildTheApiClient(): void
    {
        $plugin = new TraktPlugin();
        $plugin->configure(['enabled' => true]);

        $plugin->onEnable($this->container($this->settingsWith([
            'trakt.client_id'     => 'admin-entered-id',
            'trakt.client_secret' => 'admin-entered-secret',
        ])));

        self::assertNotNull(
            $this->apiOf($plugin),
            'admin-saved Trakt credentials must reach initApi(); a null $api means '
            . 'isConfigured() is false and every sync tick silently does nothing'
        );
    }

    public function testWithoutASettingsRepositoryTheApiStaysNull(): void
    {
        // The pre-fix behaviour, kept as the discriminator. With no env vars and
        // no overlay there are no credentials anywhere, so no client is built.
        $plugin = new TraktPlugin();
        $plugin->configure(['enabled' => true]);

        $plugin->onEnable($this->container(null));

        self::assertNull($this->apiOf($plugin));
    }

    public function testABlankSavedCredentialDoesNotBuildTheApiClient(): void
    {
        // A saved-but-empty row must not be mistaken for a configured install.
        $plugin = new TraktPlugin();
        $plugin->configure(['enabled' => true]);

        $plugin->onEnable($this->container($this->settingsWith([
            'trakt.client_id'     => 'admin-entered-id',
            'trakt.client_secret' => '',
        ])));

        self::assertNull($this->apiOf($plugin));
    }
}
