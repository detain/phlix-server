<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\WebPortal;

use PHPUnit\Framework\TestCase;
use Phlix\Admin\SettingsRepository;
use Phlix\Server\WebPortal\WebPortalRouter;

/**
 * Consequence tests for `subtitles.default_language`.
 *
 * ## The defect
 *
 * The key shipped in server-settings.schema.json with **no consumer anywhere**.
 * `config/subtitles.php` is composed only into `config/ffmpeg.php` (so it lives
 * at `$config['ffmpeg']['subtitles']`, which the schema key does not address),
 * and nothing in `src/` read `default_language` at all — the identifier had zero
 * occurrences outside the config file itself.
 *
 * An operator on production had saved `'en'` and it did nothing. The key
 * resolved to a real default, so `SettingsDefaultResolvabilityTest` passed it
 * and always would.
 *
 * It now backs the server-wide fallback for `preferred_subtitle_language` in
 * `GET /api/v1/user/settings` — the value returned for users who have never
 * chosen one.
 *
 * ## Vocabulary
 *
 * The consuming field is ISO 639-1 (two-letter). `config/subtitles.php` shipped
 * `'eng'` (ISO 639-2), disagreeing with its own only consumer. Per §4 rule 8 the
 * consumer defines the vocabulary, so the config default was corrected to `'en'`
 * rather than converting at the boundary. `test_config_default_matches_the_consumer_vocabulary()`
 * pins that so the two cannot drift apart again.
 */
class DefaultSubtitleLanguageTest extends TestCase
{
    private function invoke(?SettingsRepository $settings): string
    {
        $ref = new \ReflectionClass(WebPortalRouter::class);
        /** @var WebPortalRouter $router */
        $router = $ref->newInstanceWithoutConstructor();

        $prop = $ref->getProperty('settings');
        $prop->setAccessible(true);
        $prop->setValue($router, $settings);

        $method = $ref->getMethod('defaultSubtitleLanguage');
        $method->setAccessible(true);

        /** @var string $result */
        $result = $method->invoke($router);

        return $result;
    }

    /**
     * CONSEQUENCE: a saved override must change the language handed to clients.
     *
     * Mutation-verified: reverting `preferred_subtitle_language` to the literal
     * 'en' in the $defaults array fails the companion source assertion, and
     * neutering defaultSubtitleLanguage() fails this one.
     */
    public function test_override_changes_the_default_subtitle_language(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn('fr');

        $this->assertSame('fr', $this->invoke($settings));
    }

    /**
     * With no settings store the historical hardcoded value stands, so the
     * change is backward compatible.
     */
    public function test_absent_settings_falls_back_to_en(): void
    {
        $this->assertSame('en', $this->invoke(null));
    }

    /**
     * A settings-store failure must not strip the field or emit garbage — the
     * endpoint keeps returning a valid language code.
     */
    public function test_settings_failure_falls_back_to_en(): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willThrowException(new \RuntimeException('db down'));

        $this->assertSame('en', $this->invoke($settings));
    }

    /**
     * A non-string or empty override must not reach the client as a malformed
     * language code.
     *
     * @dataProvider junkProvider
     */
    public function test_junk_override_falls_back_to_en(mixed $value): void
    {
        $settings = $this->createMock(SettingsRepository::class);
        $settings->method('getEffective')->willReturn($value);

        $this->assertSame('en', $this->invoke($settings));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function junkProvider(): array
    {
        return [
            'empty string' => [''],
            'null'         => [null],
            'int'          => [42],
            'array'        => [['en']],
            'bool'         => [true],
        ];
    }

    /**
     * The shipped config default must be in the vocabulary its consumer uses.
     *
     * `config/subtitles.php` shipped 'eng' while the only consumer speaks
     * ISO 639-1. Nobody noticed because nothing read the key. Now that something
     * does, pin the agreement.
     */
    public function test_config_default_matches_the_consumer_vocabulary(): void
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../../../config/subtitles.php';

        $default = $config['default_language'] ?? null;
        $this->assertIsString($default);
        $this->assertMatchesRegularExpression(
            '/^[a-z]{2}$/',
            $default,
            'subtitles.default_language must be an ISO 639-1 two-letter code — that '
            . 'is the vocabulary of its consumer, the preferred_subtitle_language '
            . 'field of GET /api/v1/user/settings. It shipped as the ISO 639-2 '
            . '"eng", which disagreed with its own consumer.'
        );
    }

    /**
     * CONSEQUENCE: the defaults array must not re-hardcode the language.
     */
    public function test_defaults_array_reads_the_setting(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../../src/Server/WebPortal/WebPortalRouter.php');
        $this->assertIsString($raw);

        $this->assertStringContainsString(
            "'preferred_subtitle_language' => \$this->defaultSubtitleLanguage(),",
            $raw,
            'The per-user settings defaults must source preferred_subtitle_language '
            . 'from the subtitles.default_language setting, not a literal.'
        );
    }
}
