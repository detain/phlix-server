<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use Phlix\Plugins\PluginFieldHelp;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Phlix\Plugins\PluginFieldHelp
 */
final class PluginFieldHelpTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore normal config-file loading for other tests.
        PluginFieldHelp::setMapForTesting(null);
        parent::tearDown();
    }

    public function test_decorate_merges_overlay_keys_over_schema(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => [
                'api_key' => [
                    'label'       => 'Demo API Key',
                    'description' => 'Where to get it.',
                    'link'        => 'https://demo.test/key',
                    'link_text'   => 'Get a key',
                ],
            ],
        ]);

        $schema = [
            'api_key' => ['type' => 'string', 'required' => true, 'secret' => true, 'label' => '', 'description' => ''],
        ];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        $this->assertSame('Demo API Key', $decorated['api_key']['label']);
        $this->assertSame('Where to get it.', $decorated['api_key']['description']);
        $this->assertSame('https://demo.test/key', $decorated['api_key']['link']);
        $this->assertSame('Get a key', $decorated['api_key']['link_text']);
        // Manifest-owned keys are untouched.
        $this->assertTrue($decorated['api_key']['required']);
        $this->assertTrue($decorated['api_key']['secret']);
    }

    public function test_decorate_leaves_uncovered_fields_and_plugins_alone(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => ['api_key' => ['link' => 'https://demo.test']],
        ]);

        $schema = [
            'api_key' => ['type' => 'string', 'label' => 'Key'],
            'other'   => ['type' => 'string', 'label' => 'Other'],
        ];

        // Unknown plugin → schema returned verbatim.
        $this->assertSame($schema, PluginFieldHelp::decorate('phlix-plugin-unknown', $schema));

        // Known plugin → only the covered field gains the overlay key.
        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);
        $this->assertSame('https://demo.test', $decorated['api_key']['link']);
        $this->assertArrayNotHasKey('link', $decorated['other']);
    }

    public function test_decorate_ignores_overlay_entries_for_nonexistent_fields(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => [
                'ghost_field' => ['label' => 'Should not appear'],
            ],
        ]);

        $schema = ['api_key' => ['type' => 'string']];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        $this->assertArrayNotHasKey('ghost_field', $decorated);
        $this->assertSame($schema, $decorated);
    }

    public function test_decorate_ignores_empty_overlay_strings(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => ['api_key' => ['label' => '', 'link' => 'https://demo.test']],
        ]);

        $schema = ['api_key' => ['type' => 'string', 'label' => 'Original']];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        // Empty overlay label does not clobber the manifest label.
        $this->assertSame('Original', $decorated['api_key']['label']);
        $this->assertSame('https://demo.test', $decorated['api_key']['link']);
    }

    public function test_production_overlay_config_is_wellformed(): void
    {
        // Force a real load from config/plugin_field_help.php.
        PluginFieldHelp::setMapForTesting(null);

        $schema = [
            'api_key'  => ['type' => 'string', 'secret' => true, 'label' => '', 'description' => ''],
            'username' => ['type' => 'string', 'label' => '', 'description' => ''],
        ];

        // A known first-party plugin gains a "where to get it" link.
        $decorated = PluginFieldHelp::decorate('phlix-plugin-lastfm', $schema);
        $this->assertArrayHasKey('link', $decorated['api_key']);
        $this->assertStringStartsWith('https://', $decorated['api_key']['link']);
        $this->assertNotSame('', $decorated['api_key']['label']);
    }
}
