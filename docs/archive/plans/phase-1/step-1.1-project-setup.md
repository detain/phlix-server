# Step 1.1: Project Setup

**Phase:** 1 - Core Media Server Foundation  
**Plan File:** step-1.1-project-setup.md  
**Objective:** Initialize Workerman/Webman project structure with autoloading and dependencies

---

## Overview

This step establishes the foundational project structure for the Phlex Media Server using Workerman/Webman. You will create the directory structure, initialize Composer, configure autoloading, and set up the basic entry point.

---

## Tasks

### 1.1.1 Create Directory Structure

Create the following directory structure under `/home/sites/phlex/`:

```
phlex/
├── src/
│   ├── Server/
│   │   ├── Core/
│   │   ├── Http/
│   │   └── WebSocket/
│   ├── Media/
│   │   ├── Library/
│   │   ├── Streaming/
│   │   ├── Transcoding/
│   │   └── Metadata/
│   ├── Session/
│   │   ├── Playback/
│   │   └── SyncPlay/
│   ├── Auth/
│   ├── Dlna/
│   ├── LiveTv/
│   └── Common/
│       ├── Database/
│       ├── Cache/
│       ├── Logger/
│       └── Events/
├── config/
├── tests/
│   ├── unit/
│   ├── integration/
│   └── e2e/
├── public/
└── migrations/
```

### 1.1.2 Initialize Composer

```bash
cd /home/sites/phlex
composer init --name="phlex/media-server" --type="project" --license="proprietary"
```

Add dependencies:
```bash
composer require workerman/workerman:^5.0
composer require workerman/webman-framework:^2.0
composer require workerman/mysql:^8.0
composer require workerman/redis:^2.0
composer require symfony/yaml:^7.0
composer require monolog/monolog:^3.0
```

Add dev dependencies:
```bash
composer require --dev phpunit/phpunit:^10.0 mockery/mockery:^1.6
```

### 1.1.3 Configure PSR-4 Autoloading

Edit `composer.json`:
```json
{
    "autoload": {
        "psr-4": {
            "Phlex\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Phlex\\Tests\\": "tests/"
        }
    }
}
```

Run `composer dump-autoload`.

### 1.1.4 Create Configuration Files

Create `config/server.php`:
```php
<?php

return [
    'server' => [
        'name' => 'Phlex Media Server',
        'host' => '0.0.0.0',
        'port' => 8096,
        'context' => [],
    ],
    'worker' => [
        'count' => 'auto',
        'stdout_file' => '/var/log/phlex/stdout.log',
        'pid_file' => '/var/run/phlex/pid',
    ],
    'process' => [
        'reloadable' => true,
        'reuse_port' => true,
    ],
];
```

Create `config/database.php`:
```php
<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'phlex',
            'username' => 'phlex',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'pool_size' => 20,
            'timeout' => 5,
        ],
    ],
];
```

Create `config/logger.php`:
```php
<?php

return [
    'default' => 'file',
    'handlers' => [
        'file' => [
            'type' => 'rotating_file',
            'path' => '/var/log/phlex/app.log',
            'max_files' => 30,
            'level' => 'debug',
        ],
        'error' => [
            'type' => 'rotating_file',
            'path' => '/var/log/phlex/error.log',
            'max_files' => 30,
            'level' => 'error',
        ],
    ],
];
```

Create `config/ffmpeg.php`:
```php
<?php

return [
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'ffprobe_path' => '/usr/bin/ffprobe',
    'transcode_dir' => '/var/transcodes',
    'segment_dir' => '/var/segments',
    'max_concurrent_transcodes' => 4,
    'transcode_timeout' => 7200,
];
```

### 1.1.5 Create Application Entry Point

Create `public/index.php`:
```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Phlex\Server\Core\Application;

// Load configuration
$configPath = __DIR__ . '/../config/server.php';
$app = new Application($configPath);
$app->run();
```

Create `src/Server/Core/Application.php`:
```php
<?php

namespace Phlex\Server\Core;

use Workerman\Worker;

class Application
{
    private string $configPath;
    private array $config;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $this->config = include $configPath;
    }

    public function run(): void
    {
        $serverConfig = $this->config['server'];
        
        $worker = new Worker("http://{$serverConfig['host']}:{$serverConfig['port']}");
        $worker->onMessage = [$this, 'handleRequest'];
        
        Worker::runAll();
    }

    public function handleRequest($connection, $request): void
    {
        $response = new \Phlex\Server\Http\Response();
        $response->json(['status' => 'ok', 'message' => 'Phlex Media Server running']);
        $connection->send($response);
    }
}
```

### 1.1.6 Create Basic Unit Tests

Create `tests/unit/Server/Core/ApplicationTest.php`:
```php
<?php

namespace Phlex\Tests\Unit\Server\Core;

use PHPUnit\Framework\TestCase;
use Phlex\Server\Core\Application;

class ApplicationTest extends TestCase
{
    public function testApplicationCanBeInstantiated(): void
    {
        $configPath = __DIR__ . '/../../../../config/server.php';
        $app = new Application($configPath);
        
        $this->assertInstanceOf(Application::class, $app);
    }
}
```

---

## Verification

After completing all tasks:

1. Run `composer dump-autoload` to ensure autoloading works
2. Run the unit tests:
```bash
cd /home/sites/phlex
./vendor/bin/phpunit tests/unit/Server/Core/ApplicationTest.php --testdox
```

3. Verify the directory structure exists:
```bash
ls -la /home/sites/phlex/src/Server/
ls -la /home/sites/phlex/config/
ls -la /home/sites/phlex/public/
```

---

## Git Workflow

After verification, commit your changes:

```bash
cd /home/sites/phlex
git checkout -b step-1.1-project-setup
git add .
git commit -m "Step 1.1: Initialize Workerman project structure with autoloading"
unset GITHUB_TOKEN
gh pr create --title "Step 1.1: Project Setup" --body "Initializes the Phlex Media Server project with Workerman, establishes directory structure, configures Composer autoloading, and creates basic configuration files."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 1.2: Database Layer** (`plans/phase-1/step-1.2-database-layer.md`).
