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
    /**
     * @param array<string, mixed> $body
     */
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
            'auth.signup_mode'                          => 'string',
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
            'trakt.client_id'                           => 'string',
            'trakt.client_secret'                       => 'string',
            'trakt.redirect_uri'                        => 'string',
            // Step 13.3: array-typed noise-suffix list → internal `json`.
            'matching.noise_suffixes'                   => 'json',
            // Step 3.3: object-typed per-type priority map → internal `json`;
            // string-typed genres mode → `string`.
            'metadata.provider_priority'                => 'json',
            'metadata.genres_mode'                      => 'string',
        ];

        $actual = AdminSettingsController::allowedKeys();

        $this->assertCount(22, $actual);
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
        /** @var array{success: mixed, data: array{settings: array<string, mixed>, overridden: mixed, types: array<string, mixed>}} $body */
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
        /** @var array<string, mixed> $body */
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
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
    }

    public function testUpdateAcceptsNoiseSuffixesArrayAsJson(): void
    {
        // Step 13.3: matching.noise_suffixes is an array-typed (`json`) key in
        // the vendored schema, so update() must accept an array of phrases and
        // persist it verbatim with the `json` internal type.
        $custom = ['unrated directors cut', 'remux', 'imax edition'];

        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('matching.noise_suffixes', $custom, 'json');
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => ['matching.noise_suffixes' => $custom],
            'overridden' => ['matching.noise_suffixes'],
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['matching.noise_suffixes' => $custom]]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
    }

    public function testUpdateRejectsNoiseSuffixesWhenNotAnArray(): void
    {
        // A non-array value fails the `json` arm of valueMatchesType() and is
        // rejected (400) without persisting anything.
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['matching.noise_suffixes' => 'directors cut']]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertFalse($body['success']);
    }

    public function testIndexReturnsNoiseSuffixesArrayValue(): void
    {
        // GET returns the effective array for the noise-suffix key (it is part
        // of the schema-derived allow-list passed to getEffectiveMany()).
        $effective = ['unrated directors cut', 'remux'];

        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('getEffectiveMany')
            ->with(array_keys(AdminSettingsController::allowedKeys()))
            ->willReturn([
                'values'     => ['matching.noise_suffixes' => $effective],
                'overridden' => ['matching.noise_suffixes'],
            ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array{success: mixed, data: array{settings: array<string, mixed>, types: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertSame($effective, $body['data']['settings']['matching.noise_suffixes']);
        $this->assertSame('json', $body['data']['types']['matching.noise_suffixes']);
    }

    public function testUpdateAcceptsProviderPriorityMapAsJson(): void
    {
        // Step 3.3: metadata.provider_priority is an object-typed (`json`) key in
        // the vendored schema, so update() must accept a per-type source map and
        // persist it verbatim with the `json` internal type.
        $custom = [
            'movie'  => ['imdb', 'tmdb'],
            'series' => ['tmdb', 'imdb'],
        ];

        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('metadata.provider_priority', $custom, 'json');
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => ['metadata.provider_priority' => $custom],
            'overridden' => ['metadata.provider_priority'],
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['metadata.provider_priority' => $custom]]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
    }

    public function testUpdateAcceptsGenresModeString(): void
    {
        // metadata.genres_mode is a string-typed key; update() persists it with
        // the `string` internal type.
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('metadata.genres_mode', 'union', 'string');
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => ['metadata.genres_mode' => 'union'],
            'overridden' => ['metadata.genres_mode'],
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['metadata.genres_mode' => 'union']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
    }

    public function testIndexReturnsProviderPriorityMapValue(): void
    {
        // GET returns the effective map for the provider-priority key (part of
        // the schema-derived allow-list) with its `json` type.
        $effective = [
            'movie'  => ['tmdb', 'imdb'],
            'series' => ['tmdb', 'imdb'],
            'anime'  => ['anidb', 'myanimelist', 'tvdb', 'fanart', 'local'],
        ];

        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('getEffectiveMany')
            ->with(array_keys(AdminSettingsController::allowedKeys()))
            ->willReturn([
                'values'     => ['metadata.provider_priority' => $effective],
                'overridden' => [],
            ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);
        /** @var array{success: mixed, data: array{settings: array<string, mixed>, types: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertSame($effective, $body['data']['settings']['metadata.provider_priority']);
        $this->assertSame('json', $body['data']['types']['metadata.provider_priority']);
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
        /** @var array{success: mixed, errors: array<string, mixed>} $body */
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
        /** @var array{errors: array<string, mixed>} $body */
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
        /** @var array{errors: array<string, mixed>} $body */
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
        /** @var array<string, mixed> $body */
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
