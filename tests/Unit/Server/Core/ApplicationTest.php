<?php

namespace Phlix\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;
use Phlix\Server\Core\Application;

class ApplicationTest extends TestCase
{
    public function testApplicationCanBeInstantiated(): void
    {
        // Application's constructor eagerly resolves controllers
        // (loadApiRoutes -> getMediaItemController -> ItemRepository
        // -> Connection), so this smoke test needs a reachable MySQL.
        // CI provides one as a service container; skip locally when
        // the host doesn't.
        if (!$this->isMysqlReachable('127.0.0.1', 3306)) {
            $this->markTestSkipped('No MySQL on 127.0.0.1:3306 — skipping Application boot smoke test.');
        }

        // Application::fromConfigPath() expects the same shape that
        // public/index.php builds: server config + paths to the db and
        // logger configs.
        $configFile  = __DIR__ . '/../../../../config/server.php';
        $tmpDbConfig = $this->writeTempDbConfig();

        $merged = require $configFile;
        $merged['db_config_path']     = $tmpDbConfig;
        $merged['logger_config_path'] = __DIR__ . '/../../../../config/logger.php';

        $tmpConfig = tempnam(sys_get_temp_dir(), 'phlix-app-test-');
        file_put_contents($tmpConfig, "<?php\nreturn " . var_export($merged, true) . ';');

        try {
            $app = Application::fromConfigPath($tmpConfig);
            $this->assertInstanceOf(Application::class, $app);
        } finally {
            @unlink($tmpConfig);
            @unlink($tmpDbConfig);
        }
    }

    private function isMysqlReachable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
        if ($sock === false) {
            return false;
        }
        fclose($sock);
        return true;
    }

    private function writeTempDbConfig(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phlix-db-test-');
        file_put_contents($path, "<?php\nreturn " . var_export([
            'connections' => [
                'mysql' => [
                    'host'     => '127.0.0.1',
                    'port'     => 3306,
                    'username' => 'root',
                    'password' => '',
                    'database' => 'phlix_test',
                ],
            ],
        ], true) . ';');
        return $path;
    }
}
