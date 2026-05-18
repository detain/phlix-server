<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Theming;

use PHPUnit\Framework\TestCase;
use Phlex\Theming\Theme;
use Phlex\Theming\ThemeRegistry;
use Workerman\MySQL\Connection;

class ThemeRegistryTest extends TestCase
{
    private ThemeRegistry $registry;
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Connection::class);
        $this->registry = new ThemeRegistry($this->db, 'var/themes/');
    }

    public function testRegisterBuiltInAddsToList(): void
    {
        $theme = new Theme(
            id: 'test-theme',
            name: 'Test Theme',
            type: 'builtin',
            cssUrl: '/assets/css/test.css',
            jsUrl: null,
            thumbnailUrl: '/assets/images/test.png',
            version: '1.0.0',
            pluginName: null,
            dark: true
        );

        $this->registry->registerBuiltIn($theme);

        $this->assertCount(1, $this->registry->getAllThemes());
        $this->assertSame($theme, $this->registry->getTheme('test-theme'));
    }

    public function testGetThemeReturnsCorrectTheme(): void
    {
        $theme = new Theme(
            id: 'test-theme',
            name: 'Test Theme',
            type: 'builtin',
            cssUrl: '/assets/css/test.css',
            jsUrl: null,
            thumbnailUrl: null,
            version: '1.0.0',
            pluginName: null,
            dark: false
        );

        $this->registry->registerBuiltIn($theme);

        $found = $this->registry->getTheme('test-theme');

        $this->assertSame($theme, $found);
        $this->assertEquals('test-theme', $found->id);
        $this->assertEquals('Test Theme', $found->name);
        $this->assertEquals('/assets/css/test.css', $found->cssUrl);
        $this->assertFalse($found->dark);
    }

    public function testGetThemeReturnsNullForUnknown(): void
    {
        $result = $this->registry->getTheme('nonexistent-theme');

        $this->assertNull($result);
    }

    public function testGetAllThemesReturnsAllRegistered(): void
    {
        $theme1 = new Theme(
            id: 'theme-1',
            name: 'Theme 1',
            type: 'builtin',
            cssUrl: '/assets/css/1.css',
            jsUrl: null,
            thumbnailUrl: null,
            version: '1.0.0',
            pluginName: null,
            dark: true
        );

        $theme2 = new Theme(
            id: 'theme-2',
            name: 'Theme 2',
            type: 'builtin',
            cssUrl: '/assets/css/2.css',
            jsUrl: null,
            thumbnailUrl: null,
            version: '1.0.0',
            pluginName: null,
            dark: false
        );

        $this->registry->registerBuiltIn($theme1);
        $this->registry->registerBuiltIn($theme2);

        $themes = $this->registry->getAllThemes();

        $this->assertCount(2, $themes);
    }

    public function testGetActiveThemeForUserReturnsDefaultWhenNotSet(): void
    {
        // Register the default theme first
        $this->registry->registerBuiltIn(new Theme(
            id: ThemeRegistry::DEFAULT_THEME_ID,
            name: 'Phlex Dark',
            type: 'builtin',
            cssUrl: '/assets/css/themes/phlex-dark.css',
            jsUrl: null,
            thumbnailUrl: null,
            version: '1.0.0',
            pluginName: null,
            dark: true
        ));

        $this->db->method('query')->willReturn([]);

        $theme = $this->registry->getActiveThemeForUser('user-123');

        $this->assertEquals(ThemeRegistry::DEFAULT_THEME_ID, $theme->id);
    }

    public function testSetActiveThemeForUserPersistsToDb(): void
    {
        $theme = new Theme(
            id: 'custom-theme',
            name: 'Custom Theme',
            type: 'builtin',
            cssUrl: '/assets/css/custom.css',
            jsUrl: null,
            thumbnailUrl: null,
            version: '1.0.0',
            pluginName: null,
            dark: true
        );
        $this->registry->registerBuiltIn($theme);

        $this->db->expects($this->once())
            ->method('query')
            ->with(
                $this->stringContains('UPDATE user_profiles'),
                $this->callback(function ($params) {
                    return $params[0] === 'custom-theme' && $params[1] === 'user-123';
                })
            );

        $this->registry->setActiveThemeForUser('user-123', 'custom-theme');
    }

    public function testRegisterFromPluginExtractsThemeFromManifest(): void
    {
        $manifest = [
            'type' => 'ui-theme',
            'name' => 'My Custom Theme Plugin',
            'theme' => [
                'id' => 'my-custom-theme',
                'name' => 'My Custom Theme',
                'css' => '/plugins/my-theme/dist/theme.css',
                'js' => '/plugins/my-theme/dist/theme.js',
                'thumbnail' => '/plugins/my-theme/screenshots/preview.png',
                'dark' => true,
                'version' => '2.0.0',
            ],
        ];

        $this->registry->registerFromPlugin($manifest, 'my-theme-plugin');

        $theme = $this->registry->getTheme('my-custom-theme');

        $this->assertNotNull($theme);
        $this->assertEquals('my-custom-theme', $theme->id);
        $this->assertEquals('My Custom Theme', $theme->name);
        $this->assertEquals('ui-theme-plugin', $theme->type);
        $this->assertEquals('/plugins/my-theme/dist/theme.css', $theme->cssUrl);
        $this->assertEquals('/plugins/my-theme/dist/theme.js', $theme->jsUrl);
        $this->assertEquals('/plugins/my-theme/screenshots/preview.png', $theme->thumbnailUrl);
        $this->assertEquals('2.0.0', $theme->version);
        $this->assertEquals('my-theme-plugin', $theme->pluginName);
        $this->assertTrue($theme->dark);
    }
}
