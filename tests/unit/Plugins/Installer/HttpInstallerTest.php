<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins\Installer;

use Phlex\Common\Logger\StructuredLogger;
use Phlex\Plugins\Exception\PluginInstallException;
use Phlex\Plugins\Installer\HttpInstaller;
use Phlex\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlex\Plugins\Installer\HttpInstaller
 */
final class HttpInstallerTest extends TestCase
{
    private string $base = '';
    private string $work = '';
    private StructuredLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $root = sys_get_temp_dir() . '/phlex_http_inst_' . uniqid('', true);
        mkdir($root, 0775, true);
        $this->base = $root . '/var/plugins';
        $this->work = $root . '/work';
        mkdir($this->work, 0775, true);
        $this->logger = $this->createMock(StructuredLogger::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @system('rm -rf ' . escapeshellarg(dirname($this->base)));
    }

    private function installer(): HttpInstaller
    {
        return new HttpInstaller($this->base, $this->logger);
    }

    public function test_installFromDirectory_copies_plugin_into_base_dir(): void
    {
        $source = $this->work . '/myplugin';
        mkdir($source . '/src', 0775, true);
        file_put_contents($source . '/plugin.json', json_encode($this->validManifest()));
        file_put_contents($source . '/src/Plugin.php', "<?php echo 'hi';");

        [$manifest, $destination] = $this->installer()->installFromDirectory($source);

        $this->assertSame('phlex-plugin-fromdir', $manifest->name);
        $this->assertSame($this->base . '/phlex-plugin-fromdir', $destination);
        $this->assertFileExists($destination . '/plugin.json');
        $this->assertFileExists($destination . '/src/Plugin.php');
    }

    public function test_installFromDirectory_replaces_existing_install(): void
    {
        // Pre-stage an old version with a sentinel file.
        $existing = $this->base . '/phlex-plugin-fromdir';
        mkdir($existing, 0775, true);
        file_put_contents($existing . '/old-marker.txt', 'old');

        $source = $this->work . '/myplugin';
        mkdir($source, 0775, true);
        file_put_contents($source . '/plugin.json', json_encode($this->validManifest()));

        $this->installer()->installFromDirectory($source);

        $this->assertFileDoesNotExist($existing . '/old-marker.txt');
        $this->assertFileExists($existing . '/plugin.json');
    }

    public function test_installFromDirectory_throws_when_no_plugin_json(): void
    {
        $source = $this->work . '/myplugin';
        mkdir($source, 0775, true);

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('does not contain a plugin.json');
        $this->installer()->installFromDirectory($source);
    }

    public function test_installFromDirectory_rejects_invalid_manifest(): void
    {
        $source = $this->work . '/myplugin';
        mkdir($source, 0775, true);
        $invalid = $this->validManifest();
        $invalid['name'] = 'not-prefixed';
        file_put_contents($source . '/plugin.json', json_encode($invalid));

        try {
            $this->installer()->installFromDirectory($source);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('manifest is invalid', $e->getMessage());
            $this->assertNotEmpty($e->validationErrors());
        }
    }

    public function test_install_rejects_http_scheme_by_default(): void
    {
        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('Plain HTTP plugin sources');
        $this->installer()->install('http://example.test/plugin.zip');
    }

    public function test_install_rejects_unknown_scheme(): void
    {
        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('Unsupported source URL scheme');
        $this->installer()->install('ftp://example.test/plugin.zip');
    }

    public function test_install_from_file_url_zip_archive(): void
    {
        $zipPath = $this->makeZipFromDir($this->validPluginSource());

        [$manifest, $destination] = $this->installer()->install('file://' . $zipPath);
        $this->assertSame('phlex-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_from_file_url_targz_archive(): void
    {
        $tarPath = $this->makeTarGzFromDir($this->validPluginSource());
        [$manifest, $destination] = $this->installer()->install('file://' . $tarPath);

        $this->assertSame('phlex-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_from_stub_json_redirects_to_real_source(): void
    {
        $zipPath = $this->makeZipFromDir($this->validPluginSource());

        $stub = ['source' => 'file://' . $zipPath];
        $stubPath = $this->work . '/stub.json';
        file_put_contents($stubPath, (string) json_encode($stub));

        [$manifest, $destination] = $this->installer()->install('file://' . $stubPath);
        $this->assertSame('phlex-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_rejects_stub_without_source_field(): void
    {
        $stubPath = $this->work . '/bad-stub.json';
        file_put_contents($stubPath, '{"name":"phlex-plugin-foo"}');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('Stub plugin.json must contain a "source"');
        $this->installer()->install('file://' . $stubPath);
    }

    public function test_install_rejects_unsupported_extension(): void
    {
        $unknown = $this->work . '/weird.txt';
        file_put_contents($unknown, 'whatever');

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('Unsupported plugin source extension');
        $this->installer()->install('file://' . $unknown);
    }

    public function test_install_fails_when_file_does_not_exist(): void
    {
        $this->expectException(PluginInstallException::class);
        // Either "Failed to fetch" (from downloadToTemp) or wrapped.
        $this->installer()->install('file:///definitely/not/a/real/path.zip');
    }

    public function test_install_allows_http_when_env_set(): void
    {
        putenv('PHLEX_PLUGINS_ALLOW_HTTP=1');
        try {
            // We expect failure at the fetch step, NOT at the scheme guard.
            try {
                $this->installer()->install('http://127.0.0.1:1/does-not-exist.zip');
                $this->fail('Expected PluginInstallException');
            } catch (PluginInstallException $e) {
                $this->assertStringNotContainsString('Plain HTTP', $e->getMessage());
            }
        } finally {
            putenv('PHLEX_PLUGINS_ALLOW_HTTP');
        }
    }

    public function test_installFromDirectory_rejects_unsafe_plugin_name(): void
    {
        $source = $this->work . '/sneaky';
        mkdir($source, 0775, true);
        $bad = $this->validManifest();
        // Pass schema validation but trip the second guard inside the installer.
        $bad['name'] = 'phlex-plugin-../escape';
        // Schema requires kebab-case prefix; this fails schema validation first,
        // so confirm the install rejects the manifest at all.
        file_put_contents($source . '/plugin.json', json_encode($bad));

        $this->expectException(PluginInstallException::class);
        $this->installer()->installFromDirectory($source);
    }

    public function test_install_extracts_targz_with_single_root_folder(): void
    {
        // Many GitHub tarballs unpack to repo-sha/; ensure flattenSingleRoot kicks in.
        $wrapperParent = sys_get_temp_dir() . '/phlex_wrapper_parent_' . uniqid('', true);
        $wrapper = $wrapperParent . '/repo-abcdef';
        mkdir($wrapper, 0775, true);
        file_put_contents($wrapper . '/plugin.json', (string) json_encode($this->validManifest()));

        $tarPath = $this->makeTarGzFromDir($wrapperParent);
        [$manifest, $destination] = $this->installer()->install('file://' . $tarPath);

        $this->assertSame('phlex-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    private function validPluginSource(): string
    {
        $source = $this->work . '/plugin-source-' . uniqid('', true);
        mkdir($source, 0775, true);
        file_put_contents($source . '/plugin.json', json_encode($this->validManifest()));
        return $source;
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(): array
    {
        return [
            'name' => 'phlex-plugin-fromdir',
            'version' => '1.0.0',
            'phlex_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlex\\Tests\\Plugin\\Entry',
            'events' => [],
        ];
    }

    private function makeZipFromDir(string $dir): string
    {
        $zipPath = $this->work . '/zip-' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE) === true);
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        /** @var \SplFileInfo $file */
        foreach ($items as $file) {
            $rel = substr($file->getPathname(), strlen($dir) + 1);
            $zip->addFile($file->getPathname(), $rel);
        }
        $zip->close();
        RecursiveDelete::remove($dir);
        return $zipPath;
    }

    private function makeTarGzFromDir(string $dir): string
    {
        $tarPath = $this->work . '/tarball-' . uniqid('', true) . '.tar';
        $phar = new \PharData($tarPath);
        $phar->buildFromDirectory($dir);
        $phar->compress(\Phar::GZ);
        @unlink($tarPath);
        RecursiveDelete::remove($dir);
        return $tarPath . '.gz';
    }
}
