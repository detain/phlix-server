<?php

declare(strict_types=1);

namespace Phlix\Tests\Integration\Media;

use Phlix\Media\Music\MusicLibraryScanner;

/**
 * Scanner subclass whose tag reader is injected, so a synthetic tree of empty files can
 * stand in for a tagged music library.
 *
 * ⚠ Lives in its OWN FILE, not inside the test that uses it (review r2 F9): PSR-12's
 * "each class must be in a file by itself" was the one phpcs error this branch added in
 * a NEW test file, and `tests/` is autoloaded PSR-4 (`Phlix\\Tests\\` → `tests/`) with
 * PHPUnit collecting only `*Test.php`, so a helper class here is picked up by the
 * autoloader and never mistaken for a test. Existing precedent:
 * `tests/Integration/Plugins/KeyedPluginSettingsStore.php`,
 * `tests/Unit/Roku/RemoteRokuClientAsyncSeamStub.php`.
 */
final class BackfillTaggedScanner extends MusicLibraryScanner
{
    /** @var \Closure(string): array<string, mixed> Path → canonical metadata. */
    public \Closure $tagger;

    /**
     * @param string $path Absolute filesystem path.
     * @return array<string, mixed>|null
     */
    protected function probeViaGetId3(string $path): ?array
    {
        return ($this->tagger)($path);
    }
}
