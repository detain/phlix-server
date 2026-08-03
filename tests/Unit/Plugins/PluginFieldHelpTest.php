<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use Phlix\Plugins\PluginFieldHelp;
use PHPUnit\Framework\TestCase;

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

    /**
     * CONSEQUENCE: the overlay can promote a field to the Advanced tier.
     *
     * This is the point of routing `tier` through the overlay at all: an
     * ALREADY-INSTALLED plugin gets correct tiering without waiting for a
     * plugin release that carries it in its own manifest.
     *
     * Mutation-verified: removing the tier branch from decorate() fails this.
     */
    public function test_decorate_applies_a_valid_tier_from_the_overlay(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => ['debug_mode' => ['tier' => 'advanced']],
        ]);

        $schema = ['debug_mode' => ['type' => 'bool', 'required' => false, 'tier' => 'standard']];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        $this->assertSame('advanced', $decorated['debug_mode']['tier']);
    }

    /**
     * CONSEQUENCE: an operator typo in the overlay cannot hide a field.
     *
     * `tier` is deliberately NOT in OVERLAY_KEYS, whose loop accepts any
     * non-empty string. Routing it through SettingsMasker::normaliseTier()
     * folds anything outside the vocabulary back to `standard`.
     *
     * Mutation-verified: adding 'tier' to OVERLAY_KEYS and deleting the
     * dedicated branch makes the raw string win and fails this test.
     */
    public function test_decorate_folds_an_invalid_overlay_tier_to_standard(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => ['thing' => ['tier' => 'advnaced']],
        ]);

        $schema = ['thing' => ['type' => 'string', 'required' => false, 'tier' => 'standard']];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        $this->assertSame('standard', $decorated['thing']['tier']);
    }

    /**
     * CONSEQUENCE: the overlay cannot hide a REQUIRED field.
     *
     * SettingsMasker::schema() refuses to file a required field as advanced.
     * The overlay runs AFTER that projection, so without re-asserting the
     * invariant here the overlay would be a back door around it — and a
     * required field the admin cannot see means a plugin that silently never
     * works, since nothing enforces `required` on save.
     *
     * Mutation-verified: passing `false` instead of the descriptor's own
     * `required` flag into normaliseTier() fails this test.
     */
    public function test_decorate_cannot_hide_a_required_field(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => ['api_key' => ['tier' => 'advanced']],
        ]);

        $schema = ['api_key' => ['type' => 'string', 'required' => true, 'tier' => 'standard']];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        $this->assertSame(
            'standard',
            $decorated['api_key']['tier'],
            'The overlay must not be a back door around the required-fields-are-'
            . 'never-hidden invariant enforced in SettingsMasker::normaliseTier().'
        );
    }

    /**
     * CONSEQUENCE: an overlay that says nothing about tier leaves it alone.
     *
     * Mutation-verified: replacing the array_key_exists('tier', ...) guard with
     * an unconditional assignment rewrites every covered field to `standard`
     * and fails this test.
     */
    public function test_decorate_leaves_tier_untouched_when_overlay_omits_it(): void
    {
        PluginFieldHelp::setMapForTesting([
            'phlix-plugin-demo' => ['thing' => ['label' => 'Renamed']],
        ]);

        $schema = ['thing' => ['type' => 'string', 'required' => false, 'tier' => 'advanced']];

        $decorated = PluginFieldHelp::decorate('phlix-plugin-demo', $schema);

        $this->assertSame('Renamed', $decorated['thing']['label']);
        $this->assertSame('advanced', $decorated['thing']['tier']);
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
