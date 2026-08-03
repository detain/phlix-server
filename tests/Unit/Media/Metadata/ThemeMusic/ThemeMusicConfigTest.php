<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Media\Metadata\ThemeMusic;

use PHPUnit\Framework\TestCase;
use Phlix\Media\Metadata\ThemeMusic\ThemeMusicConfig;

/**
 * Unit tests for {@see ThemeMusicConfig::fromArray()} coercion + gating.
 */
final class ThemeMusicConfigTest extends TestCase
{
    public function testDefaultsWhenEmpty(): void
    {
        $c = ThemeMusicConfig::fromArray([]);

        $this->assertTrue($c->enabled);
        $this->assertSame(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX, $c->source);
        $this->assertSame('https://tvthemes.plexapp.com', $c->plexArchiveBase);
        $this->assertSame(8, $c->fetchTimeout);
        $this->assertTrue($c->isActive());
        $this->assertTrue($c->allowsPlexFallback());
    }

    public function testTrailingSlashesTrimmed(): void
    {
        $c = ThemeMusicConfig::fromArray([
            'plex_archive_base' => 'https://example.test/themes/',
            'cache_dir' => '/var/theme-music/',
        ]);

        $this->assertSame('https://example.test/themes', $c->plexArchiveBase);
        $this->assertSame('/var/theme-music', $c->cacheDir);
    }

    public function testInvalidSourceFallsBackToDefault(): void
    {
        $c = ThemeMusicConfig::fromArray(['source' => 'bogus']);
        $this->assertSame(ThemeMusicConfig::SOURCE_LOCAL_THEN_PLEX, $c->source);
    }

    public function testDisabledIsNotActive(): void
    {
        $c = ThemeMusicConfig::fromArray(['enabled' => false]);
        $this->assertFalse($c->isActive());
        $this->assertFalse($c->allowsPlexFallback());
    }

    public function testSourceOffIsNotActive(): void
    {
        $c = ThemeMusicConfig::fromArray(['source' => ThemeMusicConfig::SOURCE_OFF]);
        $this->assertFalse($c->isActive());
        $this->assertFalse($c->allowsPlexFallback());
    }

    public function testLocalOnlyIsActiveButNoPlexFallback(): void
    {
        $c = ThemeMusicConfig::fromArray(['source' => ThemeMusicConfig::SOURCE_LOCAL_ONLY]);
        $this->assertTrue($c->isActive());
        $this->assertFalse($c->allowsPlexFallback());
    }

    public function testNonPositiveTimeoutFallsBackToDefault(): void
    {
        $this->assertSame(8, ThemeMusicConfig::fromArray(['fetch_timeout_seconds' => 0])->fetchTimeout);
        $this->assertSame(8, ThemeMusicConfig::fromArray(['fetch_timeout_seconds' => -3])->fetchTimeout);
        $this->assertSame(8, ThemeMusicConfig::fromArray(['fetch_timeout_seconds' => 'x'])->fetchTimeout);
        $this->assertSame(12, ThemeMusicConfig::fromArray(['fetch_timeout_seconds' => 12])->fetchTimeout);
    }
}
