<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\ContainerBuilder;
use InvalidArgumentException;
use Phlix\Common\Container\Providers\PluginsProvider;
use Phlix\Plugins\Manifest;
use Phlix\Plugins\Signature\SignatureVerifier;
use Phlix\Plugins\Signature\TrustedSignaturesConfig;
use PHPUnit\Framework\TestCase;

/**
 * L3 regression: the container must hand SignatureVerifier the operator's
 * configured allowlist. Before the fix the wiring passed a literal `[]`,
 * so a signed-but-unknown digest was optimistically accepted no matter
 * what the environment said — the allowlist arm was dead code.
 */
final class PluginsProviderSignatureWiringTest extends TestCase
{
    private string $tmpDir = '';

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/phlix_sigwire_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
        $this->savedEnv = [
            TrustedSignaturesConfig::ENV_LIST => getenv(TrustedSignaturesConfig::ENV_LIST),
            TrustedSignaturesConfig::ENV_FILE => getenv(TrustedSignaturesConfig::ENV_FILE),
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        if (is_file($this->tmpDir . '/plugin.json')) {
            @unlink($this->tmpDir . '/plugin.json');
        }
        if (is_dir($this->tmpDir)) {
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    public function test_env_allowlist_reaches_the_verifier_and_gates_unknown_digests(): void
    {
        file_put_contents($this->tmpDir . '/plugin.json', '{"name":"x"}');
        $onDisk = 'sha256:' . hash_file('sha256', $this->tmpDir . '/plugin.json');
        $listedElsewhere = 'sha256:' . hash('sha256', 'someone-elses-plugin');

        putenv(TrustedSignaturesConfig::ENV_LIST . '=' . $listedElsewhere);

        $verifier = $this->verifierFromContainer();

        // The content-matching but UNLISTED digest must now be rejected —
        // this is the exact optimistic-accept hole the empty hardcode left.
        $this->assertSame(
            SignatureVerifier::RESULT_INVALID,
            $verifier->verify($this->manifestSignedWith($onDisk), $this->tmpDir)
        );
    }

    public function test_env_allowlist_accepts_the_listed_digest(): void
    {
        file_put_contents($this->tmpDir . '/plugin.json', '{"name":"x"}');
        $onDisk = 'sha256:' . hash_file('sha256', $this->tmpDir . '/plugin.json');

        putenv(TrustedSignaturesConfig::ENV_LIST . '=' . $onDisk);

        $verifier = $this->verifierFromContainer();

        $this->assertSame(
            SignatureVerifier::RESULT_VALID,
            $verifier->verify($this->manifestSignedWith($onDisk), $this->tmpDir)
        );
    }

    public function test_malformed_env_allowlist_halts_the_container_build(): void
    {
        putenv(TrustedSignaturesConfig::ENV_LIST . '=not-a-digest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed trusted-signature entry');

        $this->verifierFromContainer();
    }

    private function verifierFromContainer(): SignatureVerifier
    {
        $builder = new ContainerBuilder();
        (new PluginsProvider())->register($builder, []);
        $container = $builder->build();

        /** @var SignatureVerifier $verifier */
        $verifier = $container->get(SignatureVerifier::class);

        return $verifier;
    }

    private function manifestSignedWith(string $signature): Manifest
    {
        return Manifest::fromArray([
            'name' => 'phlix-plugin-sigwire',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlix\\Tests\\Sig\\Plugin',
            'signature' => $signature,
        ]);
    }
}
