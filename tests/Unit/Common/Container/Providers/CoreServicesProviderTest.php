<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use Phlix\Common\Container\Providers\CoreServicesProvider;
use Phlix\Common\Logger\AuditLogger;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * Smoke test for {@see CoreServicesProvider}.
 *
 * @covers \Phlix\Common\Container\Providers\CoreServicesProvider
 */
final class CoreServicesProviderTest extends TestCase
{
    private string $tempDir = '';
    private string $loggerConfigPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        $this->tempDir = sys_get_temp_dir() . '/phlix_core_provider_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $config = "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n";
        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents($this->loggerConfigPath, $config);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        LoggerFactory::reset();
        @unlink($this->loggerConfigPath);
        $files = glob($this->tempDir . '/*') ?: [];
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($this->tempDir);
    }

    public function test_register_adds_logger_and_db_definitions(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        (new CoreServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ]);

        $container = $builder->build();

        $this->assertTrue($container->has(LoggerFactory::class));
        $this->assertTrue($container->has(StructuredLogger::class));
        $this->assertTrue($container->has(AuditLogger::class));
        $this->assertTrue($container->has(Connection::class));

        foreach (array_keys(CoreServicesProvider::channels()) as $alias) {
            $this->assertTrue($container->has($alias), "{$alias} should be registered");
        }
    }

    public function test_channels_returns_full_log_channel_map(): void
    {
        $channels = CoreServicesProvider::channels();

        // Each entry must be alias => channel-name with both strings.
        $this->assertNotEmpty($channels);
        foreach ($channels as $alias => $channel) {
            $this->assertIsString($alias);
            $this->assertIsString($channel);
            $this->assertStringStartsWith('logger.', $alias);
        }
    }

    public function test_logger_alias_returns_structured_logger(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new CoreServicesProvider())->register($builder, [
            'logger_config_path' => $this->loggerConfigPath,
        ]);
        $container = $builder->build();

        $this->assertInstanceOf(StructuredLogger::class, $container->get('logger.application'));
        $this->assertInstanceOf(StructuredLogger::class, $container->get('logger.auth'));
    }
}
