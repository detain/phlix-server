<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata;

use Phlix\Admin\SettingsRepository;
use Phlix\Media\Metadata\EmbeddedWritePolicy;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S89 — EmbeddedWritePolicy: the destructive-mode gate ships OFF and only an
 * explicit, readable override turns it on. Inverted safe-degradation versus
 * the permissive siblings ({@see MetadataOverwritePolicy}, ArtworkDownloadPolicy):
 * every outage/ambiguity path must answer FALSE, because "as before" for a
 * feature introduced by S89 IS off, and degrading toward ON would silently
 * start rewriting operator media files.
 *
 * AC clause pinned: "Default-off proven by a test — no write happens without
 * explicit opt-in" (policy half; the writer half is EmbeddedMetadataWriterTest,
 * the enqueue half rides the untouched S87 LibraryRow gate).
 */
final class EmbeddedWritePolicyTest extends TestCase
{
    public function test_no_store_at_all_ships_off(): void
    {
        $this->assertFalse((new EmbeddedWritePolicy())->embeddedWriteEnabled());
    }

    public function test_the_shipped_default_constant_is_false(): void
    {
        // The DEFAULT-OFF promise is this constant; flipping it to true here
        // would silently enable embedded writing for every fresh install.
        $this->assertFalse(EmbeddedWritePolicy::DEFAULT_EMBEDDED_WRITE);
    }

    public function test_a_settings_store_outage_degrades_off_never_on(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('store down'));

        $this->assertFalse((new EmbeddedWritePolicy($settings))->embeddedWriteEnabled());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function onSpellings(): array
    {
        return [
            'bool true' => [true],
            'int 1' => [1],
            'string 1' => ['1'],
            'true' => ['true'],
            'yes' => ['yes'],
            'on' => ['on'],
            'padded TRUE' => ["  TRUE\n"],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function offAndAmbiguousSpellings(): array
    {
        return [
            'bool false' => [false],
            'int 0' => [0],
            'string 0' => ['0'],
            'false' => ['false'],
            'no' => ['no'],
            'off' => ['off'],
            'empty' => [''],
            // Anything unrecognised falls to the shipped default (OFF) — for
            // the permissive siblings the same table defaults to true; here an
            // unparseable row must NOT enable a destructive writer. ('false'
            // as a PHP bool cast is true; (bool)'garbage' is true too — the
            // explicit table is what prevents both.)
            'garbage' => ['garbage'],
            'null' => [null],
            'array' => [['nested']],
            'float zero' => [0.0],
        ];
    }

    /**
     * @dataProvider onSpellings
     */
    public function test_explicit_on_spellings_enable(mixed $stored): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with(EmbeddedWritePolicy::SETTING_KEY)->willReturn($stored);

        $this->assertTrue((new EmbeddedWritePolicy($settings))->embeddedWriteEnabled());
    }

    /**
     * @dataProvider offAndAmbiguousSpellings
     */
    public function test_off_and_ambiguous_spellings_stay_off(mixed $stored): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->with(EmbeddedWritePolicy::SETTING_KEY)->willReturn($stored);

        $this->assertFalse((new EmbeddedWritePolicy($settings))->embeddedWriteEnabled());
    }

    public function test_the_shipped_config_default_resolves_off_through_the_real_repository(): void
    {
        // Beyond the mocked-store arms: the REAL read path — no DB override →
        // getEffective falls to config/metadata.php. A key missing from the
        // config file would resolve null (coerced off anyway), so this also
        // pins that the shipped default is an EXPLICIT false, the same
        // resolvability floor SettingsDefaultResolvabilityTest enforces for
        // schema keys (this key has no schema entry until phlix-shared ships
        // one — KNOWN LIMIT documented on the policy).
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([]);
        $repo = new SettingsRepository($db, dirname(__DIR__, 4) . '/config');

        $this->assertSame(false, $repo->getDefault(EmbeddedWritePolicy::SETTING_KEY));
        $this->assertFalse((new EmbeddedWritePolicy($repo))->embeddedWriteEnabled());
    }
}
