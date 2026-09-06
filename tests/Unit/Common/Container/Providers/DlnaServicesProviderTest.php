<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\DlnaServicesProvider;
use Phlix\Config\EffectiveConfig;
use Phlix\Dlna\CdsServer;
use Phlix\Dlna\ContentDirectory;
use Phlix\Dlna\DlnaServer;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Streaming\HlsStreamer;
use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Wiring tests for the DLNA MediaServer.
 *
 * ## Why these exist
 *
 * `DlnaServer` was registered in NO provider until 1.3.0, and its constructor
 * takes three un-autowirable `string` parameters, so PHP-DI could never build
 * it. `Application::loadCdsRoutes()` resolved `CdsServer` inside a bare
 * `catch (\Throwable)`, that resolution always threw, the exception was
 * swallowed — and therefore **no DLNA browse route was ever registered on any
 * install**. Verified on production 2026-07-21, where every dynamic DLNA path
 * 404'd.
 *
 * A test that "the provider defines these keys" would not have caught that.
 * These tests RESOLVE the services from a real container, which is the only
 * assertion that would have failed before the fix.
 */
final class DlnaServicesProviderTest extends TestCase
{
    /** @var list<string> minted config roots removed in tearDown (S439 zero-residue). */
    private array $mintedConfigRoots = [];

    protected function setUp(): void
    {
        parent::setUp();
        EffectiveConfig::reset();
    }

    protected function tearDown(): void
    {
        EffectiveConfig::reset();
        foreach ($this->mintedConfigRoots as $root) {
            RecursiveDelete::remove($root);
        }
        $this->mintedConfigRoots = [];
        parent::tearDown();
    }

    /**
     * Build a container with the DLNA provider over stubbed media services.
     *
     * @param array<string, mixed> $dlnaConfig contents of config/dlna.php
     */
    private function container(array $dlnaConfig = []): ContainerInterface
    {
        $dir = sys_get_temp_dir() . '/phlix_dlnaprov_' . uniqid('', true) . '/config';
        mkdir($dir, 0o777, true);
        $this->mintedConfigRoots[] = dirname($dir);
        file_put_contents($dir . '/dlna.php', '<?php return ' . var_export($dlnaConfig, true) . ";\n");

        $db = $this->createMock(\Workerman\MySQL\Connection::class);
        $db->method('query')->willReturn([]);
        EffectiveConfig::bootstrap($db, null, $dir);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            // DlnaServer requires a REAL ItemRepository (it throws otherwise),
            // and LibraryBridge needs an HlsStreamer. Both are supplied by
            // other providers in production; stub them here so this test
            // covers the DLNA wiring specifically.
            ItemRepository::class => $this->createMock(ItemRepository::class),
            HlsStreamer::class    => $this->createMock(HlsStreamer::class),
        ]);

        (new DlnaServicesProvider())->register($builder, ['server' => ['port' => 8096]]);

        return $builder->build();
    }

    /**
     * CONSEQUENCE: CdsServer actually RESOLVES.
     *
     * This is the whole bug. Before 1.3.0 this threw
     * "Parameter $serverId of __construct() has no value defined or guessable".
     *
     * Mutation-verified: removing the DlnaServer definition from the provider
     * fails this test with that exact error.
     */
    public function test_the_cds_server_resolves(): void
    {
        $cds = $this->container()->get(CdsServer::class);

        self::assertInstanceOf(CdsServer::class, $cds);
        self::assertInstanceOf(DlnaServer::class, $cds->getDlnaServer());
    }

    /**
     * CONSEQUENCE: the container's ContentDirectory is the server's OWN one.
     *
     * `DlnaServer` builds a ContentDirectory internally and attaches the
     * LibraryBridge to THAT instance. `Application::loadCdsRoutes()` resolves
     * `ContentDirectory::class` separately for the SOAP controller, so if the
     * container handed back a second, autowired instance then
     * `/dlna/content_directory` would serve STUB data while `/cds/control`
     * served the real library — a split-brain that looks fine from the outside.
     *
     * Mutation-verified: replacing the ContentDirectory factory with
     * `autowire()` fails this test.
     */
    public function test_the_content_directory_is_the_same_instance_the_server_uses(): void
    {
        $c = $this->container();

        self::assertSame(
            $c->get(DlnaServer::class)->getContentDirectory(),
            $c->get(ContentDirectory::class),
            'The container must not hand out a second, bridge-less ContentDirectory.'
        );
    }

    /**
     * CONSEQUENCE: the ContentDirectory is backed by the real library.
     *
     * Without `setLibraryBridge()` the ContentDirectory silently falls back to
     * STUB data, so a TV would browse a fake library. That failure is invisible
     * unless asserted — nothing errors.
     *
     * Mutation-verified: deleting the setLibraryBridge() call fails this.
     */
    public function test_the_content_directory_has_a_library_bridge(): void
    {
        self::assertTrue(
            $this->container()->get(ContentDirectory::class)->hasLibraryBridge(),
            'Without a LibraryBridge the ContentDirectory serves stub data, not the real library.'
        );
    }

    /**
     * CONSEQUENCE: the UDN is STABLE across container rebuilds.
     *
     * Control points cache devices by UDN. A server whose identity changed on
     * every boot would accumulate as endless duplicates in every TV's source
     * list, so a random id here would be a real (and very annoying) bug.
     *
     * Mutation-verified: deriving the id from uniqid()/random_bytes() fails this.
     */
    public function test_the_server_udn_is_stable_across_rebuilds(): void
    {
        $first  = $this->container()->get(DlnaServer::class)->getServerUdn();
        $second = $this->container()->get(DlnaServer::class)->getServerUdn();

        self::assertSame($first, $second, 'The UPnP UDN must not change between restarts.');
        self::assertNotSame('', $first);
    }

    /**
     * CONSEQUENCE: configured friendly name and base URL reach the server.
     *
     * `dlna.friendly_name` is a settings key; if the provider ignored it the
     * control would be fake. Uses values that cannot be confused with the
     * defaults.
     *
     * Mutation-verified: hardcoding either value fails this.
     */
    public function test_configured_friendly_name_and_host_are_used(): void
    {
        $server = $this->container([
            'friendly_name'  => 'Front Room Phlix',
            'advertise_host' => '192.168.1.50',
        ])->get(DlnaServer::class);

        self::assertSame('Front Room Phlix', $server->getFriendlyName());
        // DlnaServer composes http://{host}:{port} from the configured port.
        self::assertSame('http://192.168.1.50:8096', $server->getBaseUrl());
    }

    /**
     * CONSEQUENCE: an operator who writes a URL still gets a valid description.
     *
     * `DlnaServer` names its third constructor parameter `$baseUrl` but USES it
     * as a bare host — `getBaseUrl()` returns `"http://{$this->baseUrl}:{$port}"`.
     * So a perfectly reasonable-looking `http://192.168.1.50` in the config
     * would produce `http://http://192.168.1.50:8096` and break every control
     * point. This test was written expecting a URL and caught that mismatch;
     * the provider now strips any scheme and trailing slash.
     *
     * Mutation-verified: removing the preg_replace/rtrim normalisation fails
     * this test with the doubled scheme.
     */
    public function test_a_url_shaped_host_is_normalised_to_a_bare_host(): void
    {
        $server = $this->container(['advertise_host' => 'http://192.168.1.50/'])
            ->get(DlnaServer::class);

        self::assertSame('http://192.168.1.50:8096', $server->getBaseUrl());
    }
}
