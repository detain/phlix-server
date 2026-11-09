<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Hub;

use Phlix\Hub\Ed25519KeyManager;
use PHPUnit\Framework\TestCase;

/**
 * SH-019 (secaudit finding 3): the hub identity keypair must not DEFAULT to
 * living inside the git-tracked config/ directory — private key material
 * born next to tracked files is one `git add -A` away from leaking. The
 * resolver now prefers an explicit config value, then env PHLIX_HUB_KEY_PATH,
 * then a LEGACY compat probe (an install already holding config/hub-server-
 * key.pem keeps its enrollment identity), and only then the new default
 * var/hub-server-key.pem.
 */
final class Ed25519KeyManagerResolveKeyPathTest extends TestCase
{
    private string $root;
    private string $configDir;
    private string $varDir;
    /** @var string|false prior env value for restore in tearDown */
    private string|false $savedEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phlix-sh019-' . uniqid();
        $this->configDir = $this->root . '/config';
        $this->varDir = $this->root . '/var';
        mkdir($this->configDir, 0700, true);
        mkdir($this->varDir, 0700, true);
        $this->savedEnv = getenv('PHLIX_HUB_KEY_PATH');
        putenv('PHLIX_HUB_KEY_PATH');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->savedEnv === false) {
            putenv('PHLIX_HUB_KEY_PATH');
        } else {
            putenv('PHLIX_HUB_KEY_PATH=' . $this->savedEnv);
        }
        if (is_file($this->configDir . '/hub-server-key.pem')) {
            unlink($this->configDir . '/hub-server-key.pem');
        }
        @rmdir($this->configDir);
        @rmdir($this->varDir);
        @rmdir($this->root);
    }

    public function test_explicit_configured_path_wins_over_everything(): void
    {
        putenv('PHLIX_HUB_KEY_PATH=/env/keys/ignored.pem');
        file_put_contents($this->configDir . '/hub-server-key.pem', 'legacy');

        $this->assertSame(
            '/operator/choice/my-key.pem',
            Ed25519KeyManager::resolveKeyPath('/operator/choice/my-key.pem', $this->configDir)
        );
    }

    public function test_env_overrides_legacy_and_default(): void
    {
        putenv('PHLIX_HUB_KEY_PATH=/env/keys/mine.pem');
        file_put_contents($this->configDir . '/hub-server-key.pem', 'legacy');

        $this->assertSame(
            '/env/keys/mine.pem',
            Ed25519KeyManager::resolveKeyPath(null, $this->configDir)
        );
    }

    public function test_legacy_compat_probe_keeps_existing_installs_on_their_key(): void
    {
        // The breakage law: a pre-cure install's key sits in config/ — the
        // resolver must KEEP serving it (rotating identities silently would
        // de-anchor every live hub enrollment), never birth var/ alongside.
        file_put_contents($this->configDir . '/hub-server-key.pem', 'legacy-secret');

        $resolved = Ed25519KeyManager::resolveKeyPath(null, $this->configDir);

        $this->assertSame($this->configDir . '/hub-server-key.pem', $resolved);
        $this->assertFileDoesNotExist($this->varDir . '/hub-server-key.pem');
    }

    public function test_default_is_outside_the_tracked_config_dir(): void
    {
        // Fresh install (no legacy file): the key must land under var/.
        $resolved = Ed25519KeyManager::resolveKeyPath(null, $this->configDir);

        $this->assertSame($this->varDir . '/hub-server-key.pem', $resolved);
        $this->assertStringNotContainsString('/config/', $resolved);
    }

    public function test_the_checked_in_tree_itself_carries_no_committed_pem(): void
    {
        // SH-019 extinction pin, repo-wide: git's INDEX holds zero *.pem
        // entries (the finding's "committed in tree" fear must stay factually
        // dead), and the shipped default location in config/hub.php is no
        // longer a literal inside config/.
        $repoRoot = dirname(__DIR__, 3);
        $lines = [];
        $status = 1;
        exec(
            'git -C ' . escapeshellarg($repoRoot) . ' ls-files "*.pem" 2>&1',
            $lines,
            $status
        );
        $this->assertSame(0, $status, 'git must be invocable for the extinction pin to mean anything');
        $this->assertSame([], $lines, 'no PEM may ever be committed to the tree');

        $hubConfig = (string) file_get_contents($repoRoot . '/config/hub.php');
        $this->assertStringNotContainsString(
            "'key_path' => __DIR__ . '/hub-server-key.pem'",
            $hubConfig,
            'config/hub.php must not hard-default the key into the tracked dir'
        );
        $this->assertStringContainsString('PHLIX_HUB_KEY_PATH', $hubConfig);
    }

    public function test_relative_config_dir_resolves_like_the_pre_cure_fallback(): void
    {
        // The Application.php manual-fallback construction passes relative
        // 'config' (CWD-bound) — the resolver keeps that convention instead
        // of silently widening scope.
        $resolved = Ed25519KeyManager::resolveKeyPath(null, 'config');
        $this->assertMatchesRegularExpression('#^(\.|/|\w+[\\/])#', $resolved);
        $this->assertStringContainsString('hub-server-key.pem', $resolved);
    }
}
