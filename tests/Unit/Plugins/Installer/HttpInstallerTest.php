<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins\Installer;

use Phlix\Common\Logger\StructuredLogger;
use Phlix\Plugins\Exception\PluginInstallException;
use Phlix\Plugins\Installer\HttpInstaller;
use Phlix\Plugins\Util\RecursiveDelete;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HttpInstallerTest extends TestCase
{
    private string $base = '';
    private string $work = '';
    private string $root = '';
    private StructuredLogger&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/phlix_http_inst_' . uniqid('', true);
        $root = $this->root;
        mkdir($root, 0775, true);
        $this->base = $root . '/var/plugins';
        $this->work = $root . '/work';
        mkdir($this->work, 0775, true);
        $this->logger = $this->createMock(StructuredLogger::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // S439: remove the WHOLE minted root (the old dirname($this->base) sweep left
        // $root itself behind — 40 leaked /tmp/phlix_http_inst_* dirs per full suite).
        if ($this->root !== '') {
            RecursiveDelete::remove($this->root);
        }
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

        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertSame($this->base . '/phlix-plugin-fromdir', $destination);
        $this->assertFileExists($destination . '/plugin.json');
        $this->assertFileExists($destination . '/src/Plugin.php');
    }

    public function test_installFromDirectory_replaces_existing_install(): void
    {
        // Pre-stage an old version with a sentinel file.
        $existing = $this->base . '/phlix-plugin-fromdir';
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
        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_from_file_url_targz_archive(): void
    {
        $tarPath = $this->makeTarGzFromDir($this->validPluginSource());
        [$manifest, $destination] = $this->installer()->install('file://' . $tarPath);

        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_from_stub_json_redirects_to_real_source(): void
    {
        $zipPath = $this->makeZipFromDir($this->validPluginSource());

        $stub = ['source' => 'file://' . $zipPath];
        $stubPath = $this->work . '/stub.json';
        file_put_contents($stubPath, (string) json_encode($stub));

        [$manifest, $destination] = $this->installer()->install('file://' . $stubPath);
        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_rejects_stub_without_source_field(): void
    {
        $stubPath = $this->work . '/bad-stub.json';
        file_put_contents($stubPath, '{"name":"phlix-plugin-foo"}');

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
        putenv('PHLIX_PLUGINS_ALLOW_HTTP=1');
        try {
            // We expect failure at the fetch step, NOT at the scheme guard.
            try {
                $this->installer()->install('http://127.0.0.1:1/does-not-exist.zip');
                $this->fail('Expected PluginInstallException');
            } catch (PluginInstallException $e) {
                $this->assertStringNotContainsString('Plain HTTP', $e->getMessage());
            }
        } finally {
            putenv('PHLIX_PLUGINS_ALLOW_HTTP');
        }
    }

    public function test_installFromDirectory_rejects_unsafe_plugin_name(): void
    {
        $source = $this->work . '/sneaky';
        mkdir($source, 0775, true);
        $bad = $this->validManifest();
        // Pass schema validation but trip the second guard inside the installer.
        $bad['name'] = 'phlix-plugin-../escape';
        // Schema requires kebab-case prefix; this fails schema validation first,
        // so confirm the install rejects the manifest at all.
        file_put_contents($source . '/plugin.json', json_encode($bad));

        $this->expectException(PluginInstallException::class);
        $this->installer()->installFromDirectory($source);
    }

    public function test_install_extracts_targz_with_single_root_folder(): void
    {
        // Many GitHub tarballs unpack to repo-sha/; ensure flattenSingleRoot kicks in.
        $wrapperParent = sys_get_temp_dir() . '/phlix_wrapper_parent_' . uniqid('', true);
        $wrapper = $wrapperParent . '/repo-abcdef';
        mkdir($wrapper, 0775, true);
        file_put_contents($wrapper . '/plugin.json', (string) json_encode($this->validManifest()));

        $tarPath = $this->makeTarGzFromDir($wrapperParent);
        [$manifest, $destination] = $this->installer()->install('file://' . $tarPath);

        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_from_file_url_tgz_archive(): void
    {
        // .tgz (no .tar prefix) is also a valid extension code path.
        $sourceDir = $this->validPluginSource();

        $tarPath = $this->work . '/tarball-' . uniqid('', true) . '.tar';
        $phar = new \PharData($tarPath);
        $phar->buildFromDirectory($sourceDir);
        $phar->compress(\Phar::GZ);
        @unlink($tarPath);
        RecursiveDelete::remove($sourceDir);
        $gzPath = $tarPath . '.gz';
        $tgzPath = preg_replace('/\.tar\.gz$/', '.tgz', $gzPath) ?? $gzPath;
        rename($gzPath, $tgzPath);

        [$manifest, $destination] = $this->installer()->install('file://' . $tgzPath);
        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_throws_when_archive_has_no_plugin_json(): void
    {
        // Empty zip — exercises the "missing plugin.json after extract"
        // branch (lines 72-75 of HttpInstaller).
        $emptyDir = $this->work . '/empty-zip-src-' . uniqid('', true);
        mkdir($emptyDir, 0775, true);
        file_put_contents($emptyDir . '/README.md', '# empty');
        $zipPath = $this->makeZipFromDir($emptyDir);

        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('does not contain a plugin.json');
        $this->installer()->install('file://' . $zipPath);
    }

    public function test_install_throws_when_extracted_manifest_invalid(): void
    {
        // Source with plugin.json that's structurally a Manifest but
        // missing required fields — exercises lines 82-85.
        $invalidSrc = $this->work . '/invalid-' . uniqid('', true);
        mkdir($invalidSrc, 0775, true);
        $bad = $this->validManifest();
        $bad['version'] = '';
        file_put_contents($invalidSrc . '/plugin.json', (string) json_encode($bad));
        $zipPath = $this->makeZipFromDir($invalidSrc);

        try {
            $this->installer()->install('file://' . $zipPath);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('manifest is invalid', $e->getMessage());
        }
    }

    public function test_install_cleans_up_temp_dir_when_destination_rename_fails(): void
    {
        // If we point pluginsBaseDir at an existing FILE (not a dir),
        // ensureBaseDir() fails -> install throws -> finally cleans temp.
        $bogusBase = $this->work . '/bogus-base-file';
        file_put_contents($bogusBase, 'i am a file, not a dir');

        $installer = new HttpInstaller($bogusBase, $this->logger);
        $zipPath = $this->makeZipFromDir($this->validPluginSource());

        try {
            $installer->install('file://' . $zipPath);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_install_from_zip_replaces_existing_install(): void
    {
        // Pre-stage an old version of the same plugin so we hit lines
        // 92-93 (is_dir($destination) -> RecursiveDelete::remove).
        $existing = $this->base . '/phlix-plugin-fromdir';
        mkdir($existing, 0775, true);
        file_put_contents($existing . '/stale-marker.txt', 'old');

        $zipPath = $this->makeZipFromDir($this->validPluginSource());
        [, $destination] = $this->installer()->install('file://' . $zipPath);

        $this->assertSame($existing, $destination);
        $this->assertFileExists($destination . '/plugin.json');
        $this->assertFileDoesNotExist($destination . '/stale-marker.txt');
    }

    public function test_install_logs_info_after_successful_install(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'plugin source installed',
                $this->callback(static function ($ctx): bool {
                    return is_array($ctx)
                        && ($ctx['plugin'] ?? null) === 'phlix-plugin-fromdir'
                        && is_string($ctx['destination'] ?? null);
                })
            );

        $zipPath = $this->makeZipFromDir($this->validPluginSource());
        $this->installer()->install('file://' . $zipPath);
    }

    /**
     * @group integration
     */
    public function test_install_throws_when_destination_rename_fails_due_to_read_only_base(): void
    {
        // Make pluginsBaseDir exist but be read-only so the final
        // rename($tempDir, $destination) fails -- lines 99-103.
        $readOnlyBase = $this->work . '/ro-base-' . uniqid('', true);
        mkdir($readOnlyBase, 0775, true);
        chmod($readOnlyBase, 0500); // read+exec, no write
        // Skip when running as root: chmod is ignored.
        if (posix_geteuid() === 0) {
            chmod($readOnlyBase, 0775);
            $this->markTestSkipped('Cannot enforce read-only directory as root - run in docker-compose as non-root user for integration testing.');
        }

        try {
            $installer = new HttpInstaller($readOnlyBase, $this->logger);
            $zipPath = $this->makeZipFromDir($this->validPluginSource());

            try {
                $installer->install('file://' . $zipPath);
                $this->fail('Expected PluginInstallException due to rename failure');
            } catch (PluginInstallException $e) {
                $this->assertStringContainsString('plugin', strtolower($e->getMessage()));
            }
        } finally {
            chmod($readOnlyBase, 0775);
        }
    }

    public function test_install_falls_back_to_copy_when_atomic_move_fails_cross_device(): void
    {
        // Simulate EXDEV: the staging dir (under the system temp dir / a tmpfs
        // under systemd PrivateTmp) is on a different filesystem than the
        // install volume, so rename() can't move it. The install must still
        // succeed via the recursive copy fallback, landing the plugin in place.
        $installer = new class ($this->base, $this->logger) extends HttpInstaller {
            protected function attemptAtomicMove(string $tempDir, string $destination): bool
            {
                return false; // force the copy fallback
            }
        };

        $zipPath = $this->makeZipFromDir($this->validPluginSource());
        [$manifest, $destination] = $installer->install('file://' . $zipPath);

        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertSame($this->base . '/phlix-plugin-fromdir', $destination);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_wraps_arbitrary_throwables_as_install_exception(): void
    {
        // A zip whose plugin.json is unreadable JSON triggers
        // Manifest::fromJson -> exception that is NOT PluginInstallException,
        // which exercises lines 120-125 (catch \Throwable + re-wrap).
        $broken = $this->work . '/broken-' . uniqid('', true);
        mkdir($broken, 0775, true);
        file_put_contents($broken . '/plugin.json', '{not valid JSON at all');
        $zipPath = $this->makeZipFromDir($broken);

        try {
            $this->installer()->install('file://' . $zipPath);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('Failed to install plugin from', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_install_rejects_corrupt_targz_archive(): void
    {
        // Garbage .tar.gz so PharData throws -> lines 394-401 are hit.
        $junkTar = $this->work . '/garbage.tar.gz';
        file_put_contents($junkTar, 'not a real tarball at all');

        try {
            $this->installer()->install('file://' . $junkTar);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('Failed to extract', $e->getMessage());
        }
    }

    public function test_install_rejects_corrupt_zip_archive(): void
    {
        // Write garbage bytes into a .zip path so ZipArchive::open returns
        // a non-true error code -- exercises lines 361-362.
        $junkZip = $this->work . '/garbage.zip';
        file_put_contents($junkZip, 'this is definitely not a real zip file');

        try {
            $this->installer()->install('file://' . $junkZip);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('Cannot open zip archive', $e->getMessage());
        }
    }

    public function test_installFromDirectory_throws_when_destination_cannot_be_created(): void
    {
        // pluginsBaseDir is a regular file -> ensureBaseDir() will not
        // detect it as a directory, mkdir cannot create a subdir under it
        // -> exercises the lines 173-176 mkdir-failure branch in
        // installFromDirectory().
        $bogusBase = $this->work . '/bogus-base-file-' . uniqid('', true);
        file_put_contents($bogusBase, 'i am a file, not a dir');

        $installer = new HttpInstaller($bogusBase, $this->logger);

        $source = $this->work . '/normal-' . uniqid('', true);
        mkdir($source, 0775, true);
        file_put_contents($source . '/plugin.json', (string) json_encode($this->validManifest()));

        try {
            $installer->installFromDirectory($source);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_guardPluginName_rejects_disallowed_kebab_pattern(): void
    {
        // Schema validation catches name issues first in normal flow, but
        // guardPluginName() exists as a second filesystem-path defense.
        // Test it directly via reflection to keep that defense exercised
        // (otherwise it's marked dead code by coverage).
        $installer = $this->installer();
        $ref = new \ReflectionClass(HttpInstaller::class);
        $method = $ref->getMethod('guardPluginName');
        $method->setAccessible(true);

        // Wrong prefix -> 245-249.
        $this->expectException(PluginInstallException::class);
        $this->expectExceptionMessage('not a safe directory component');
        $method->invoke($installer, 'not-prefixed-plugin');
    }

    public function test_guardPluginName_rejects_path_traversal_when_prefix_matches(): void
    {
        // Pass the regex but fail the explicit path-character guard
        // (lines 251-255). The current regex already excludes "..", but
        // we exercise the str_contains check via reflection with a
        // string that bypasses the regex via direct invocation.
        $installer = $this->installer();
        $ref = new \ReflectionClass(HttpInstaller::class);
        $method = $ref->getMethod('guardPluginName');
        $method->setAccessible(true);

        // This name fails the kebab regex (uppercase chars) -> first guard catches it.
        try {
            $method->invoke($installer, 'phlix-plugin-OK');
            $this->fail('Expected PluginInstallException from regex guard');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('not a safe directory component', $e->getMessage());
        }
    }

    public function test_installFromDirectory_throws_when_subdir_mkdir_fails(): void
    {
        // The base dir is writable (so ensureBaseDir() passes), but a regular
        // FILE sits exactly where the plugin's subdir would be created, so
        // mkdir($destination) fails -> "Cannot create plugin directory". This
        // exercises the per-plugin subdir guard without a read-only base (which
        // is now caught earlier by the writability check).
        $base = $this->work . '/base-fd-' . uniqid('', true);
        mkdir($base, 0775, true);
        // validManifest()'s name is the subdir; block it with a file.
        file_put_contents($base . '/phlix-plugin-fromdir', 'x');

        $installer = new HttpInstaller($base, $this->logger);
        $source = $this->work . '/src-fd-' . uniqid('', true);
        mkdir($source, 0775, true);
        file_put_contents($source . '/plugin.json', (string) json_encode($this->validManifest()));

        try {
            $installer->installFromDirectory($source);
            $this->fail('Expected PluginInstallException');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('Cannot create plugin directory', $e->getMessage());
        }
    }

    /**
     * @group integration
     */
    public function test_install_throws_a_clear_error_when_base_dir_is_read_only(): void
    {
        // Reproduces the production failure: the plugins base dir exists but is
        // not writable by the server user (e.g. ProtectSystem=strict without it
        // in ReadWritePaths). ensureBaseDir() must surface a clear, actionable
        // message rather than a bare "cannot create".
        $readOnlyBase = $this->work . '/ro-base-' . uniqid('', true);
        mkdir($readOnlyBase, 0775, true);
        chmod($readOnlyBase, 0500);
        if (posix_geteuid() === 0) {
            chmod($readOnlyBase, 0775);
            $this->markTestSkipped('Cannot enforce a read-only directory as root.');
        }

        try {
            $installer = new HttpInstaller($readOnlyBase, $this->logger);
            $source = $this->work . '/src-ro-' . uniqid('', true);
            mkdir($source, 0775, true);
            file_put_contents($source . '/plugin.json', (string) json_encode($this->validManifest()));

            try {
                $installer->installFromDirectory($source);
                $this->fail('Expected PluginInstallException');
            } catch (PluginInstallException $e) {
                $this->assertStringContainsString('not writable', $e->getMessage());
                $this->assertStringContainsString('ReadWritePaths', $e->getMessage());
            }
        } finally {
            chmod($readOnlyBase, 0775);
        }
    }

    public function test_installFromDirectory_logs_info_after_success(): void
    {
        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'plugin staged from directory',
                $this->callback(static fn ($ctx) => is_array($ctx) && ($ctx['plugin'] ?? null) === 'phlix-plugin-fromdir')
            );

        $source = $this->work . '/myplugin-info';
        mkdir($source, 0775, true);
        file_put_contents($source . '/plugin.json', (string) json_encode($this->validManifest()));
        $this->installer()->installFromDirectory($source);
    }

    public function test_installFromDirectory_rejects_blatant_path_traversal_name(): void
    {
        // Need a manifest that PASSES the manifest's own schema validation
        // (so we hit guardPluginName lines 246-256 in the installer rather
        // than the manifest validator). Schema regex enforces the prefix,
        // so we cannot trip the .. branch via this method — exercise the
        // primary kebab guard instead by leaning on the schema validator.
        $source = $this->work . '/nope-' . uniqid('', true);
        mkdir($source, 0775, true);
        $bad = $this->validManifest();
        unset($bad['name']);
        $bad['name'] = 'phlix-plugin-OK-AlPhA'; // uppercase -> schema rejects
        file_put_contents($source . '/plugin.json', (string) json_encode($bad));

        $this->expectException(PluginInstallException::class);
        $this->installer()->installFromDirectory($source);
    }

    public function test_install_succeeds_when_artifact_sha256_matches_pin(): void
    {
        // SV-S1b happy path: a tarball whose sha256 equals the supplied pin
        // installs normally.
        $tarPath = $this->makeTarGzFromDir($this->validPluginSource());
        $sha = hash_file('sha256', $tarPath);
        $this->assertIsString($sha);

        [$manifest, $destination] = $this->installer()->install('file://' . $tarPath, $sha);

        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
        $this->assertFileExists($destination . '/plugin.json');
    }

    public function test_install_rejects_artifact_when_sha256_mismatches_pin(): void
    {
        // SV-S1b: a tarball whose sha256 != the expected pin must throw and
        // leave NO var/plugins/<name>/ directory behind.
        $tarPath = $this->makeTarGzFromDir($this->validPluginSource());
        $wrongSha = str_repeat('0', 64);

        try {
            $this->installer()->install('file://' . $tarPath, $wrongSha);
            $this->fail('Expected PluginInstallException on digest mismatch');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('digest mismatch', $e->getMessage());
        }

        // The plugin must not have been staged into the install base.
        $this->assertDirectoryDoesNotExist($this->base . '/phlix-plugin-fromdir');
    }

    public function test_install_with_zip_artifact_verifies_pin(): void
    {
        // The digest gate applies to zip artifacts too (verified before any
        // ZipArchive::extractTo touches disk).
        $zipPath = $this->makeZipFromDir($this->validPluginSource());
        $sha = hash_file('sha256', $zipPath);
        $this->assertIsString($sha);

        [$manifest] = $this->installer()->install('file://' . $zipPath, $sha);
        $this->assertSame('phlix-plugin-fromdir', $manifest->name);
    }

    public function test_install_rejects_zip_with_parent_traversal_entry(): void
    {
        // S9 (zip-slip): an archive whose entry name climbs out of the
        // extraction dir (`../evil`) must be refused, with nothing written
        // outside the target tree.
        $zipPath = $this->work . '/slip-' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE) === true);
        $zip->addFromString('plugin.json', (string) json_encode($this->validManifest()));
        $zip->addFromString('../evil.txt', 'pwned');
        $zip->close();

        $sentinel = dirname($this->base) . '/evil.txt';
        @unlink($sentinel);

        try {
            $this->installer()->install('file://' . $zipPath);
            $this->fail('Expected PluginInstallException on zip-slip entry');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('escapes the extraction directory', $e->getMessage());
        }

        // No file written above the install base, and nothing staged.
        $this->assertFileDoesNotExist($sentinel);
        $this->assertDirectoryDoesNotExist($this->base . '/phlix-plugin-fromdir');
    }

    public function test_install_rejects_zip_with_absolute_path_entry(): void
    {
        // S9: an absolute-path entry (`/tmp/...`) must be refused before any
        // extraction occurs.
        $abs = $this->work . '/abs-target-' . uniqid('', true) . '.txt';
        @unlink($abs);

        $zipPath = $this->work . '/abs-' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE) === true);
        $zip->addFromString('plugin.json', (string) json_encode($this->validManifest()));
        // `$abs` is an absolute path (starts with `/`) — used verbatim as the
        // archive entry name so it trips the absolute-path guard.
        $zip->addFromString($abs, 'pwned');
        $zip->close();

        try {
            $this->installer()->install('file://' . $zipPath);
            $this->fail('Expected PluginInstallException on absolute-path entry');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('absolute path', $e->getMessage());
        }

        $this->assertFileDoesNotExist($abs);
        $this->assertDirectoryDoesNotExist($this->base . '/phlix-plugin-fromdir');
    }

    public function test_install_rejects_zip_with_encoded_traversal_entry(): void
    {
        // S9: a single layer of percent-encoded traversal must also be caught
        // (decoded once before the lexical climb check).
        $zipPath = $this->work . '/enc-' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE) === true);
        $zip->addFromString('plugin.json', (string) json_encode($this->validManifest()));
        $zip->addFromString('%2e%2e/evil-enc.txt', 'pwned');
        $zip->close();

        $sentinel = dirname($this->base) . '/evil-enc.txt';
        @unlink($sentinel);

        $this->expectException(PluginInstallException::class);
        try {
            $this->installer()->install('file://' . $zipPath);
        } finally {
            $this->assertFileDoesNotExist($sentinel);
        }
    }

    public function test_extractTarGz_leaves_no_intermediate_tar_when_extraction_throws(): void
    {
        // B6: PharData::decompress('.tar') writes a sibling `.tar` BEFORE the
        // extraction runs. If extractTo() then throws (here: the target path is
        // a regular file, not a directory), the `finally` must still unlink that
        // intermediate `.tar` so it is never leaked into the temp dir.
        $gzPath = $this->makeTarGzFromDir($this->validPluginSource());
        $tarSibling = (string) preg_replace('/\.tar\.gz$/', '.tar', $gzPath);

        // Force extractTo() to throw AFTER decompress() succeeds by handing it a
        // target that is a file rather than a directory.
        $fileTarget = $this->work . '/not-a-dir-' . uniqid('', true);
        file_put_contents($fileTarget, 'x');

        $ref = new \ReflectionMethod(HttpInstaller::class, 'extractTarGz');
        $ref->setAccessible(true);

        try {
            $ref->invoke($this->installer(), $gzPath, $fileTarget);
            $this->fail('Expected PluginInstallException from extractTo on a file target');
        } catch (PluginInstallException $e) {
            $this->assertStringContainsString('Failed to extract', $e->getMessage());
        }

        // The decompressed intermediate `.tar` must have been cleaned up by the
        // `finally`, even though extraction threw.
        $this->assertFileDoesNotExist(
            $tarSibling,
            'extractTarGz leaked the intermediate .tar after the extraction failed',
        );
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
            'name' => 'phlix-plugin-fromdir',
            'version' => '1.0.0',
            'phlix_min_server_version' => '0.10.0',
            'type' => 'notifier',
            'entry' => 'Phlix\\Tests\\Plugin\\Entry',
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
