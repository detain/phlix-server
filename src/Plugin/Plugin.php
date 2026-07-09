<?php

/**
 * Phlix media server component: Plugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugin;

use Phlix\Shared\Plugin\LifecycleInterface;

/**
 * Extended plugin contract providing configuration schema and endpoint metadata.
 *
 * This interface supplements {@see LifecycleInterface} with plugin introspection
 * methods that the host uses to build admin UIs, validate configuration, and
 * discover plugin-scoped REST endpoints.
 *
 * ## Configuration Schema
 * {@see getConfigSchema()} returns a schema in the format consumed by
 * {@see PluginConfigSchema::validateConfig()}:
 * ```
 * [
 *     'key' => [
 *         'type'     => 'string|int|bool|array',
 *         'required' => bool,
 *         'default'  => mixed,
 *     ],
 *     ...
 * ]
 * ```
 *
 * ## Endpoint Metadata
 * {@see getEndpoints()} returns route definitions registered by the plugin,
 * used by {@see PluginRouter} to build the plugin-scoped API surface.
 *
 * @package Phlix\Plugin
 * @see PluginConfigSchema
 * @see PluginRouter
 * @since 0.15.0
 */
interface Plugin extends LifecycleInterface
{
    /**
     * Return the configuration schema for this plugin.
     *
     * The schema defines each configurable field with its type, whether
     * it is required, and its default value. The host uses this to:
     * - Build the plugin settings UI
     * - Validate operator-supplied configuration
     * - Materialise defaults before first enable
     *
     * @return array<string, array{
     *     type?: string,
     *     required?: bool,
     *     default?: mixed
     * }> Configuration field definitions.
     *
     * @since 0.15.0
     */
    public function getConfigSchema(): array;

    /**
     * Return the registered route definitions for this plugin.
     *
     * Each route definition describes a plugin-scoped HTTP endpoint that
     * the plugin has registered via {@see PluginRouter::registerPluginRoute()}.
     *
     * @return list<array{
     *     method: string,
     *     path: string
     * }> Registered route definitions.
     *
     * @since 0.15.0
     */
    public function getEndpoints(): array;
}
