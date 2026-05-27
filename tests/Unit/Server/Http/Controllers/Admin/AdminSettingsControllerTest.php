<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Server\Http\Controllers\Admin\AdminSettingsController;
use Phlix\Server\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the admin settings JSON API (Step 0.5).
 *
 * Auth (401/403) is enforced by {@see \Phlix\Server\Http\Middleware\AdminMiddleware}
 * upstream of this controller and is covered by the middleware's own tests;
 * here we assert the controller's GET/PUT behaviour (effective merge, happy
 * path, validation failures) given an already-admin request.
 *
 * @covers \Phlix\Server\Http\Controllers\Admin\AdminSettingsController
 */
final class AdminSettingsControllerTest extends TestCase
{
    private function makeRequest(array $body = []): Request
    {
        $request = new Request();
        $request->body = $body;

        return $request;
    }

    public function testAllowedKeysAreDerivedFromSharedSchemaWithExpectedTypes(): void
    {
        // Lock-in: the schema-derived allow-list must equal the exact 15
        // dotted-key → internal-type map the former ALLOWED_KEYS const declared
        // (step 0.7 sources it from detain/phlix-shared's
        // server-settings.schema.json). A mismatch means the vendored schema
        // is missing, drifted, or mistranslated — all of which would silently
        // change GET/PUT behaviour, so this test fails loudly instead.
        $expected = [
            'hwaccel.enabled'                           => 'bool',
            'hwaccel.prefer_hardware'                   => 'bool',
            'hwaccel.probe_timeout'                     => 'int',
            'tmdb.api_key'                              => 'string',
            'marker_detection.similarity_threshold'     => 'float',
            'marker_detection.intro_max_duration'       => 'int',
            'subtitles.enabled'                         => 'bool',
            'subtitles.default_language'                => 'string',
            'subtitles.burn_in_by_default'              => 'bool',
            'discovery.discovery_port'                  => 'int',
            'trickplay.enabled'                         => 'bool',
            'trickplay.interval_seconds'                => 'int',
            'newsletter.enabled'                        => 'bool',
            'newsletter.send_hour'                      => 'int',
            'port-forward.port_forwarding.upnp_enabled' => 'bool',
        ];

        $actual = AdminSettingsController::allowedKeys();

        $this->assertCount(15, $actual);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Lock the JSON-Schema → internal type-mapping contract used by the
     * schema-derived allow-list. The shared server-settings.schema.json today
     * only declares boolean/integer/number/string, so the `array`/`object` →
     * `json` arm and the `default` → null arm of the private static
     * AdminSettingsController::mapSchemaType() (controller line 172 + the
     * default arm) are NOT exercised by the schema as it stands. This test
     * invokes mapSchemaType() directly via reflection and asserts the full
     * mapping table so the contract is locked in even before the schema grows
     * an array/object property.
     */
    public function testMapSchemaTypeMapsAllJsonSchemaTypesToInternalVocabulary(): void
    {
        $method = new \ReflectionMethod(AdminSettingsController::class, 'mapSchemaType');
        $method->setAccessible(true);

        // [JSON-Schema type => expected internal type (null = no equivalent)]
        $expected = [
            'boolean' => 'bool',
            'integer' => 'int',
            'number'  => 'float',
            'string'  => 'string',
            'array'   => 'json',
            'object'  => 'json',
            'null'    => null,
            'weird'   => null,
        ];

        foreach ($expected as $jsonType => $internal) {
            $this->assertSame(
                $internal,
                $method->invoke(null, $jsonType),
                sprintf('mapSchemaType(%s) should map to %s', $jsonType, var_export($internal, true)),
            );
        }
    }

    public function testIndexReturnsEffectiveValuesAndOverriddenKeys(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('getEffectiveMany')
            ->with(array_keys(AdminSettingsController::allowedKeys()))
            ->willReturn([
                'values'     => ['hwaccel.enabled' => false, 'tmdb.api_key' => ''],
                'overridden' => ['hwaccel.enabled'],
            ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertFalse($body['data']['settings']['hwaccel.enabled']);
        $this->assertSame(['hwaccel.enabled'], $body['data']['overridden']);
        $this->assertArrayHasKey('types', $body['data']);
    }

    public function testIndexReturns500OnRepositoryError(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffectiveMany')->willThrowException(new \RuntimeException('boom'));

        $controller = new AdminSettingsController($repo);
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertFalse($body['success']);
    }

    public function testUpdatePersistsValidSettings(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('hwaccel.enabled', false, 'bool');
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => ['hwaccel.enabled' => false],
            'overridden' => ['hwaccel.enabled'],
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.enabled' => false]]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
    }

    public function testUpdateCoercesNumericStringsBeforePersisting(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('hwaccel.probe_timeout', 45, 'int');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.probe_timeout' => '45']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateRejectsUnknownKey(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['totally.unknown' => 'x']]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('totally.unknown', $body['errors']);
    }

    public function testUpdateRejectsWrongType(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        // hwaccel.probe_timeout expects int; a non-numeric string is invalid.
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.probe_timeout' => 'not-a-number']]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('hwaccel.probe_timeout', $body['errors']);
    }

    public function testUpdateRejectsEmptyOrMissingSettingsObject(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $controller = new AdminSettingsController($repo);

        $missing = $controller->update($this->makeRequest([]), []);
        $this->assertSame(400, $missing->statusCode);

        $empty = $controller->update($this->makeRequest(['settings' => []]), []);
        $this->assertSame(400, $empty->statusCode);
    }

    public function testUpdateReportsAllInvalidKeysAndPersistsNoneWhenAnyFails(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // One valid + one invalid key → whole request rejected, nothing set.
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => [
                'hwaccel.enabled' => true,
                'unknown.key'     => 1,
            ]]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('unknown.key', $body['errors']);
    }

    public function testUpdateReturns500WhenRepositorySetThrows(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // A valid payload reaches the persist loop; set() then blows up, which
        // must be caught and surfaced as a 500. Covers
        // AdminSettingsController.php lines 204-209 (the update() catch path).
        $repo->method('set')->willThrowException(new \RuntimeException('db down'));

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.enabled' => true]]),
            [],
        );

        $this->assertSame(500, $response->statusCode);
        $body = json_decode($response->body, true);
        $this->assertFalse($body['success']);
        $this->assertSame('Failed to update settings', $body['error']);
        $this->assertSame('db down', $body['message']);
    }

    public function testUpdateAcceptsFloatKeyAndCoercesIt(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // marker_detection.similarity_threshold is a float key — exercises the
        // valueMatchesType() float arm (line 231-232) and the coerce() float
        // arm (line 256). A numeric string is accepted and coerced to float.
        $repo->expects($this->once())
            ->method('set')
            ->with('marker_detection.similarity_threshold', 0.42, 'float');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['marker_detection.similarity_threshold' => '0.42']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateAcceptsStringKeyAndPassesItThroughUnchanged(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // tmdb.api_key is a string key — exercises the valueMatchesType()
        // string arm (line 234) and the coerce() default passthrough (line 257):
        // the value is persisted unchanged.
        $repo->expects($this->once())
            ->method('set')
            ->with('tmdb.api_key', 'secret-key-123', 'string');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['tmdb.api_key' => 'secret-key-123']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateCoercesBoolFromTruthyString(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // A bool key submitted as the string "true" — exercises the coerce()
        // bool-from-string branch (lines 252-253): "true"/"1" → true.
        $repo->expects($this->once())
            ->method('set')
            ->with('hwaccel.enabled', true, 'bool');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.enabled' => 'true']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateCoercesBoolFromFalsyString(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // "0" is a valid bool-ish string that coerces to false (lines 252-253:
        // not in ['1','true'] → false).
        $repo->expects($this->once())
            ->method('set')
            ->with('subtitles.burn_in_by_default', false, 'bool');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['subtitles.burn_in_by_default' => '0']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateCoercesBoolFromIntegerOne(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        // Integer 1 is accepted by valueMatchesType() (line 228) and coerce()
        // returns the value as-is via (bool) $value (line 254 branch).
        $repo->expects($this->once())
            ->method('set')
            ->with('hwaccel.prefer_hardware', true, 'bool');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.prefer_hardware' => 1]]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }
}
