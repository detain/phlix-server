<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http\Controllers\Admin;

use Phlix\Admin\SettingsRepository;
use Phlix\Plugins\SettingsMasker;
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
     * Install a synthetic float-typed setting key into the controller's static
     * schema caches for the duration of one test, and return a callable that
     * restores them.
     *
     * ## Why this exists
     *
     * These two tests exercise the endpoint's FLOAT arms — `valueMatchesType()`'s
     * float branch, `coerce()`'s float branch, and float bounds enforcement on
     * PUT. They used to borrow whichever real `number`-typed key happened to be in
     * the schema (`hwaccel.probe_timeout`, then
     * `marker_detection.similarity_threshold`). phlix-shared v0.28.0 deleted the
     * last one, so the schema now declares NO `number` property at all.
     *
     * Skipping the tests would have silently dropped coverage of live controller
     * code because an unrelated key was deleted — a coverage cliff triggered by
     * schema churn. Injecting a synthetic key instead keeps the float arms tested
     * regardless of which keys ship, and stops these tests breaking every time the
     * key list changes.
     *
     * @return callable(): void Restores the real caches.
     */
    private function withSyntheticFloatKey(string $key, float $min, float $max): callable
    {
        $ref = new \ReflectionClass(AdminSettingsController::class);

        $keysProp = $ref->getProperty('allowedKeys');
        $keysProp->setAccessible(true);
        $metaProp = $ref->getProperty('schemaMeta');
        $metaProp->setAccessible(true);
        // The THIRD cache. schemaMeta() drives rendering; schemaValidators() is
        // what actually enforces minimum/maximum on PUT. Injecting only the first
        // two produced a synthetic key whose bounds were silently unenforced —
        // the request fell through to persist instead of 400ing.
        $valProp = $ref->getProperty('schemaValidators');
        $valProp->setAccessible(true);

        $origKeys = $keysProp->getValue();
        $origMeta = $metaProp->getValue();
        $origVal  = $valProp->getValue();

        $keys = AdminSettingsController::allowedKeys();
        $meta = AdminSettingsController::schemaMeta();
        $validators = $valProp->getValue();
        if (!is_array($validators)) {
            // Force population, then re-read.
            $vm = $ref->getMethod('schemaValidators');
            $vm->setAccessible(true);
            $validators = $vm->invoke(null);
            $origVal = $valProp->getValue();
        }

        $keys[$key] = 'float';
        // Mirror the exact shape loadSchemaMeta() emits — every optional field is
        // present and explicitly null when absent, so a partial entry would 500.
        $meta[$key] = [
            'label'      => 'Synthetic float (test only)',
            'helpText'   => 'Injected by withSyntheticFloatKey().',
            'helpLinks'  => null,
            'tier'       => 'advanced',
            'group'      => 'transcoding',
            'enum'       => null,
            'enumLabels' => null,
            'optionHelp' => null,
            'minimum'    => $min,
            'maximum'    => $max,
            'default'    => $min,
            'secret'     => false,
            'restart'    => false,
        ];

        $validators[$key] = (object) [
            'type'    => 'number',
            'minimum' => $min,
            'maximum' => $max,
        ];

        $keysProp->setValue(null, $keys);
        $metaProp->setValue(null, $meta);
        $valProp->setValue(null, $validators);

        return static function () use (
            $keysProp,
            $metaProp,
            $valProp,
            $origKeys,
            $origMeta,
            $origVal
        ): void {
            $keysProp->setValue(null, $origKeys);
            $metaProp->setValue(null, $origMeta);
            $valProp->setValue(null, $origVal);
        };
    }
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
        // Lock-in: the schema-derived allow-list must equal this exact
        // dotted-key → internal-type map (step 0.7 sources it from
        // detain/phlix-shared's server-settings.schema.json). A mismatch means
        // the vendored schema is missing, drifted, or mistranslated — all of
        // which would silently change GET/PUT behaviour, so this test fails
        // loudly instead.
        //
        // This list is DELIBERATELY hand-written rather than derived from the
        // vendored schema: deriving it would make the assertion vacuous and
        // let an unreviewed schema key addition (or deletion) land silently.
        // Update it deliberately, in the same commit as the re-vendor.
        //
        // phlix-shared v0.24.0 cut this from 53 keys to 40, deleting every key
        // that resolved to no config path and had no runtime consumer — the
        // `subsystem.*`/`database.*`/`hls.*` families, the duplicated
        // `transcoding.*` tunables (the surviving ones live under `ffmpeg.*` /
        // `server.hls.*` / `process.*`), and the unimplemented
        // `metadata.preferred_*`/`fanart_api_key` and `auth.*` extras.
        //
        // v0.25.0 took it back to 43 by RESTORING the `trakt.*` trio. Those
        // three did have a runtime consumer all along (TraktOAuthController);
        // only their config default was unreachable, so deleting them made
        // Trakt credentials unsettable via PUT. `config/trakt.php` now
        // re-exports `config/scrobblers/trakt.php` and they resolve again,
        // under their ORIGINAL flat names so persisted overrides survive.
        //
        // v0.26.0 dropped it to 42 by DELETING `hwaccel.probe_timeout`. Unlike
        // the trakt trio, that key had the opposite problem: its config default
        // resolved fine, but no runtime consumer ever read the effective value.
        // The real hwaccel probe timeouts are the hardcoded
        // ShellTimeout::FFMPEG_TIMEOUT (10s) / ::GPU_TOOL_TIMEOUT (5s)
        // constants, reached via static calls from seven VendorProbe classes
        // that take no timeout argument, and HwAccelConfig::get() additionally
        // let config/transcoding.php shadow the `hwaccel.*` side outright. It
        // shipped `restart: true`, so the admin UI promised a restart would
        // apply it — the exact false advertising this program exists to remove.
        // See docs/dev/settings-restart-gap.md.
        //
        // v0.27.0 dropped it to 41 by DELETING
        // `transcoding.include_software_fallback` — the same "resolvable but
        // consumerless" shape as `hwaccel.probe_timeout`, found by re-running
        // that audit across the rest of the key set. Its config default
        // (config/transcoding.php:44) resolved, and HwAccelConfig::get()
        // (src/Config/HwAccelConfig.php:118) copied it into the merged hwaccel
        // array, but NOTHING read the merged value: FfmpegRunner::setConfig()'s
        // consumer reads exactly tone_mapping_mode / prefer_hdr_output /
        // preferred_accelerator / enabled / prefer_hardware, and
        // HwaccelRegistry's software-fallback branch
        // (HwaccelRegistry.php:160,206) reads the SEPARATE
        // `hwaccel.fallback_to_software` key out of config/hwaccel_base.php.
        // The toggle was therefore inert in BOTH directions. If a
        // software-fallback switch is wanted, expose
        // `hwaccel.fallback_to_software`, which is genuinely consumed.
        //
        // KEEP THIS LIST HAND-WRITTEN. Deriving it from the shared schema would
        // make the assertion tautological — it exists precisely to catch an
        // unintended schema change reaching the writable allow-list.
        $expected = [
            // Hardware acceleration.
            'hwaccel.enabled'                           => 'bool',
            'hwaccel.prefer_hardware'                   => 'bool',

            // Transcoding behaviour. `preferred_accelerator`'s "auto-detect"
            // option is the EMPTY-STRING enum member (v0.24.0 replaced the old
            // JSON `null` member, which needed a controller-side shim).
            'transcoding.preferred_accelerator'         => 'string',
            'transcoding.tone_mapping_mode'             => 'string',
            'transcoding.prefer_hdr_output'             => 'bool',
            // Software-encode tunables. Single source is EncodeSettings,
            // replacing TEN literals across TranscodeManager, and folded into
            // the transcode job key so a change is not masked by a reused job.
            'transcoding.preset'                        => 'string',
            'transcoding.crf_h264'                      => 'int',
            'transcoding.audio_bitrate'                 => 'string',
            // phlix-shared v0.49.0 (S313): the on-demand segment container,
            // enum mpegts|fmp4. The key has lived in config/transcoding.php
            // since S56 but was DELIBERATELY absent from the schema, and that
            // absence was exactly what made this controller answer "Unknown
            // setting key." to every PUT of it — so the only way to change it
            // was hand-editing the config file inside a running container.
            // S313 declares it so S60's flag flip has a rollback path over the
            // admin API before the flip lands. NOT the flip: the schema default
            // is still mpegts. Enum enforcement and the accept/reject pair are
            // covered in AdminSettingsSegmentFormatTest; the schema-enum vs
            // EncodeSettings::SEGMENT_FORMATS correspondence in
            // SegmentFormatSchemaEnumDriftTest.
            'transcoding.segment_format'                => 'string',
            // Gates BOTH artwork download choke points (poster/backdrop and
            // the separate logo path) via ArtworkDownloadPolicy.
            'artwork.download_enabled'                  => 'bool',
            // MediaScanner::shouldSkipFile() via ScanIgnorePatterns. Note
            // config/scanner.php is NOT in boot $appConfig.
            'scanner.ignore_patterns'                   => 'json',

            // FFmpeg process limits (config/ffmpeg.php).
            'ffmpeg.max_concurrent_transcodes'          => 'int',
            'ffmpeg.transcode_timeout'                  => 'int',
            'ffmpeg.max_concurrent_scan_probes'         => 'int',

            // HLS segment cache + segmenter (config/server.php `hls` block).
            'server.hls.cache_max_age'                  => 'int',
            'server.hls.cache_max_bytes'                => 'int',
            'server.hls.segment_seconds'                => 'int',
            'server.hls.max_concurrent_segments'        => 'int',

            'tmdb.api_key'                              => 'string',
            'auth.signup_mode'                          => 'string',
            'auth.password.min_length'                  => 'int',
            // JWT lifetimes. Single-sourced through JwtHandler ->
            // Phlix\Auth\TokenTtlPolicy, which is what makes them honest:
            // the two `exp` claims, `expires_in` and both cookie Max-Ages all
            // read the same instance, so they cannot disagree.
            'auth.access_ttl'                           => 'int',
            'auth.refresh_ttl'                          => 'int',
            // Profile cap. Single enforcement point is
            // UserProfileManager::maxProfiles(); the admin controller's
            // pre-check calls it too, and that is the guard that fires.
            'auth.max_profiles'                         => 'int',
            // Per-playback fallback in StreamSessionService::getStreamLimit().
            'access.default_concurrent_streams'         => 'int',
            // The server.rate_limit.* block. Class (b) RESTART — the limiters
            // are factory() closures capturing max/window BY VALUE at
            // container-build time, so restart:true is accurate, not cautious.
            'server.rate_limit.register.max'            => 'int',
            'server.rate_limit.register.window'         => 'int',
            'server.rate_limit.refresh.max'             => 'int',
            'server.rate_limit.refresh.window'          => 'int',
            'server.rate_limit.webauthn_start.max'      => 'int',
            'server.rate_limit.webauthn_start.window'   => 'int',
            'server.rate_limit.webauthn_finish.max'     => 'int',
            'server.rate_limit.webauthn_finish.window'  => 'int',
            'server.rate_limit.jwks.max'                => 'int',
            'server.rate_limit.jwks.window'             => 'int',
            'server.rate_limit.ws_connect.max'          => 'int',
            'server.rate_limit.ws_connect.window'       => 'int',
            'webhooks.enabled'                          => 'bool',
            'stats.enabled'                             => 'bool',
            // phlix-shared v0.40.0: metrics recording toggle (config/metrics.php,
            // composed into config/server.php). Class (b) RESTART.
            'metrics.enabled'                           => 'bool',
            // phlix-shared v0.40.0: theme-music producer (config/theme_music.php,
            // composed into config/server.php). Class (b) RESTART.
            'theme_music.enabled'                       => 'bool',
            'theme_music.source'                        => 'string',
            'subtitles.default_language'                => 'string',
            // phlix-shared v0.44.0: flat ordered subtitle-source priority list →
            // internal `json` (array-typed, like matching.noise_suffixes).
            'subtitles.provider_priority'               => 'json',
            'trickplay.enabled'                         => 'bool',
            'newsletter.enabled'                        => 'bool',
            'newsletter.send_hour'                      => 'int',
            'port-forward.port_forwarding.upnp_enabled' => 'bool',

            // phlix-shared v0.21.0: Last.fm scrobbling credentials.
            'lastfm.api_key'                            => 'string',
            'lastfm.shared_secret'                      => 'string',
            'lastfm.enabled'                            => 'bool',

            // phlix-shared v0.25.0: Trakt operator credentials, resolved via
            // the config/trakt.php re-export of config/scrobblers/trakt.php.
            'trakt.client_id'                           => 'string',
            'trakt.client_secret'                       => 'string',
            'trakt.redirect_uri'                        => 'string',

            // Step 13.3: array-typed noise-suffix list → internal `json`.
            'matching.noise_suffixes'                   => 'json',
            // Step 3.3: object-typed per-type priority map → internal `json`;
            // string-typed genres mode → `string`.
            'metadata.provider_priority'                => 'json',
            'metadata.genres_mode'                      => 'string',
            // Phase 3: boolean overwrite gate → internal `bool`. Read-path class
            // (a) LIVE via MetadataOverwritePolicy; consumed by
            // LibraryMetadataMatcher::shouldSkipOverwrite().
            'metadata.overwrite_existing'               => 'bool',

            'relay.reconnect_delay'                     => 'int',
            'relay.ping_interval'                       => 'int',

            // Managed-worker toggles (config/process.php).
            'process.library-scan.enabled'              => 'bool',
            'process.plugin-auto-update.enabled'        => 'bool',
            'process.marker-detection.enabled'          => 'bool',
            'process.media-asset.enabled'               => 'bool',
            'process.similarity.enabled'                => 'bool',

            // SSDP advertiser toggle (config/dlna.php). Gates the DLNA
            // ADVERTISEMENT only, not DLNA browsing — the ContentDirectory
            // service is not currently registered at all. See config/dlna.php.
            'dlna.enabled'                              => 'bool',

            // Casting protocol toggles (config/casting.php). Enforced by
            // CastingEnabledMiddleware on each protocol's route group, so these
            // are class (a) LIVE — see config/casting.php for why that matters
            // (mDNS discovery blocks the worker for ~5s per call).
            'casting.chromecast.enabled'                => 'bool',
            'casting.roku.enabled'                      => 'bool',
            'casting.airplay.enabled'                   => 'bool',

            // DLNA media server (config/dlna.php). `cds_enabled` is the master
            // switch and ships FALSE — DLNA has no authentication at all.
            'dlna.cds_enabled'                          => 'bool',
            'dlna.friendly_name'                        => 'string',
            // phlix-shared v0.45.0 (S50 / updates.md #35): the DLNA IP allowlist,
            // consumed by DlnaAllowlistMiddleware. `allowed_cidrs` is array-typed
            // in the schema → internal `json` (mapSchemaType('array')==='json',
            // like scanner.ignore_patterns / subtitles.provider_priority);
            // `restrict_to_lan` is a boolean → `bool`.
            'dlna.allowed_cidrs'                        => 'json',
            'dlna.restrict_to_lan'                      => 'bool',
        ];

        $actual = AdminSettingsController::allowedKeys();

        // 72 -> 73 in phlix-shared v0.49.0: transcoding.segment_format (S313).
        $this->assertCount(73, $actual);
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
        /** @var array{success: mixed, data: array{settings: array<string, mixed>, overridden: mixed, types: array<string, mixed>, meta: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        $this->assertFalse($body['data']['settings']['hwaccel.enabled']);
        $this->assertSame(['hwaccel.enabled'], $body['data']['overridden']);
        $this->assertArrayHasKey('types', $body['data']);
        $this->assertArrayHasKey('meta', $body['data']);
    }

    public function testSchemaMetaReturnsPerKeyMetaBlockFromSchema(): void
    {
        // schemaMeta() returns a non-empty map for every property in the
        // vendored server-settings.schema.json.
        $meta = AdminSettingsController::schemaMeta();

        $this->assertNotEmpty($meta, 'schemaMeta() must not be empty when schema is present');
        $this->assertArrayHasKey('hwaccel.enabled', $meta);
        $this->assertArrayHasKey('tmdb.api_key', $meta);
        $this->assertArrayHasKey('lastfm.shared_secret', $meta);

        // schemaMeta() covers EVERY declared property, not just the typed ones
        // that reach allowedKeys().
        // 72 -> 73 in phlix-shared v0.49.0: transcoding.segment_format (S313).
        $this->assertCount(73, $meta);
        foreach (array_keys(AdminSettingsController::allowedKeys()) as $key) {
            $this->assertArrayHasKey($key, $meta, sprintf('%s must carry a meta block', $key));
        }
    }

    public function testSchemaMetaHwaccelEnabledHasRequiredMetaFields(): void
    {
        // hwaccel.enabled is a boot-only, standard-tier transcoding setting —
        // verify its meta block is fully populated from the schema.
        $meta = AdminSettingsController::schemaMeta();
        $hwaccel = $meta['hwaccel.enabled'];

        $this->assertSame('Enable hardware acceleration', $hwaccel['label']);
        $this->assertNotEmpty($hwaccel['helpText']);
        $this->assertIsArray($hwaccel['helpLinks']);
        $this->assertNotNull($hwaccel['helpLinks']);
        $this->assertSame('transcoding', $hwaccel['group']);
        $this->assertSame('standard', $hwaccel['tier']);
        $this->assertNull($hwaccel['enum']);
        $this->assertNull($hwaccel['enumLabels']);
        $this->assertNull($hwaccel['optionHelp']);
        $this->assertNull($hwaccel['minimum']);
        $this->assertNull($hwaccel['maximum']);
        // phlix-shared v0.24.0 gave this key an explicit schema `default`; it
        // must be projected through verbatim, not flattened to null.
        $this->assertTrue($hwaccel['default']);
        $this->assertFalse($hwaccel['secret']);
        $this->assertTrue($hwaccel['restart']);
    }

    public function testSchemaMetaTmdbApiKeyHasSecretFlag(): void
    {
        // tmdb.api_key has secret:true in the schema.
        $meta = AdminSettingsController::schemaMeta();
        $tmdb = $meta['tmdb.api_key'];

        $this->assertTrue($tmdb['secret']);
        // restart:true since phlix-shared v0.46.0. This assertion previously
        // read assertFalse() and so encoded the defect: TmdbProvider is a
        // PHP-DI factory() whose result is cached per container, one container
        // is built per worker in onWorkerStart, and phlix-library-scan resolves
        // it eagerly at fork time — so the key is captured BY VALUE at
        // construction and a saved key stays inert until the workers are
        // recycled. See docs/dev/settings-restart-gap.md.
        $this->assertTrue($tmdb['restart']);
        $this->assertSame('metadata', $tmdb['group']);
    }

    public function testSchemaMetaSecretKeysAreExactlyTheExpectedQuintet(): void
    {
        // Lock-in: which keys are secret drives masking on BOTH GET and PUT, so
        // a key silently losing `"secret": true` in a re-vendor would start
        // shipping a credential to the browser in plaintext. Assert the exact
        // set rather than merely "at least one".
        //
        // phlix-shared v0.25.0 grew this from a trio to a quintet by restoring
        // the Trakt operator credentials. `trakt.client_id` is masked even
        // though an OAuth client_id is nominally public: it is the exact
        // analogue of `lastfm.api_key` (Phlix sends it as the `trakt-api-key`
        // header on every request), which this schema already masks.
        // `trakt.redirect_uri` is a public URL and is deliberately NOT secret.
        $this->assertSame(
            [
                'tmdb.api_key',
                'lastfm.api_key',
                'lastfm.shared_secret',
                'trakt.client_id',
                'trakt.client_secret',
            ],
            $this->secretKeys(),
        );
    }

    public function testSchemaMetaLastfmSharedSecretHasSecretFlag(): void
    {
        // lastfm.shared_secret has secret:true in the schema.
        $meta = AdminSettingsController::schemaMeta();
        $lastfm = $meta['lastfm.shared_secret'];

        $this->assertTrue($lastfm['secret']);
        // restart:true since phlix-shared v0.47.0. This assertion previously
        // read assertFalse() and so contradicted the very code it describes:
        // Application::applyLastfmOverrides()'s docblock already stated the
        // overlay "runs at route-build time (once per worker) … That is why the
        // `lastfm.*` schema keys carry \"restart\": true" — the credentials are
        // frozen into LastfmApi's constructor-promoted readonly properties, so
        // a saved key cannot apply until the workers are recycled.
        // See docs/dev/settings-restart-gap.md.
        $this->assertTrue($lastfm['restart']);
    }

    // ---------------------------------------------------------------------
    // SECRETS — GET must not leak them, PUT must not wipe them.
    //
    // The pre-existing tests asserted only that `meta.secret` was PROJECTED
    // correctly; none asserted the VALUE's absence from the response body,
    // which is exactly why plaintext third-party credentials shipped to the
    // browser for a full program cycle without review catching it.
    // ---------------------------------------------------------------------

    /**
     * @return list<string> Every key the vendored schema flags `"secret": true`.
     */
    private function secretKeys(): array
    {
        $keys = [];
        foreach (AdminSettingsController::schemaMeta() as $key => $meta) {
            if (($meta['secret'] ?? false) === true) {
                $keys[] = (string) $key;
            }
        }
        self::assertNotEmpty($keys, 'Schema must declare at least one secret key');

        return $keys;
    }

    public function testIndexNeverEmitsASecretValueAnywhereInTheResponseBody(): void
    {
        $secretKeys = $this->secretKeys();

        // Give every secret key a distinctive, greppable plaintext value.
        // (`tmdb.api_key` used to be excluded here as "not flagged secret yet";
        // it carries `"secret": true` in the schema, so it is covered like the
        // rest — no key gets a free pass.)
        $values = [];
        foreach ($secretKeys as $i => $key) {
            $values[$key] = sprintf('SUPER-SECRET-VALUE-%d-%s', $i, md5($key));
        }

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => $values,
            'overridden' => $secretKeys,
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->index($this->makeRequest(), []);

        $this->assertSame(200, $response->statusCode);

        // The strongest form of this assertion: the raw plaintext must not
        // appear ANYWHERE in the serialized body — not in `settings`, not in
        // `meta.default`, not in any future key someone adds.
        foreach ($values as $key => $plaintext) {
            if (!in_array($key, $secretKeys, true)) {
                continue;
            }
            $this->assertStringNotContainsString(
                $plaintext,
                $response->body,
                sprintf('Secret %s leaked into the GET response body', $key),
            );
        }

        /** @var array{data: array{settings: array<string, mixed>, secretStatus: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);

        foreach ($secretKeys as $key) {
            $this->assertSame(
                SettingsMasker::MASK,
                $body['data']['settings'][$key],
                sprintf('Secret %s must be replaced by the mask sentinel', $key),
            );
            $this->assertTrue($body['data']['secretStatus'][$key]['set']);
            $this->assertGreaterThan(0, $body['data']['secretStatus'][$key]['length']);
        }
    }

    public function testIndexLeavesUnsetSecretsUnmaskedAndReportsThemAsUnset(): void
    {
        $secretKeys = $this->secretKeys();
        $values = array_fill_keys($secretKeys, '');

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffectiveMany')->willReturn(['values' => $values, 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->index($this->makeRequest(), []);

        /** @var array{data: array{settings: array<string, mixed>, secretStatus: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);

        foreach ($secretKeys as $key) {
            $this->assertSame('', $body['data']['settings'][$key]);
            $this->assertFalse($body['data']['secretStatus'][$key]['set']);
            $this->assertSame(0, $body['data']['secretStatus'][$key]['length']);
        }
    }

    public function testUpdateIgnoresASecretResubmittedAsTheMaskSentinel(): void
    {
        // The mask-overwrite bug: without this guard, the FIRST Save on the
        // settings page replaces every stored secret with the literal '***'.
        // Driven off secretKeys() so a newly-flagged secret is covered without
        // touching this test.
        $secretKeys = $this->secretKeys();

        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => array_fill_keys($secretKeys, SettingsMasker::MASK)]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);
        $this->assertTrue($body['success']);
        // The sentinel must never be persisted AS a value, either.
        $this->assertStringNotContainsString('"' . SettingsMasker::MASK . '"', json_encode($body['data'] ?? []) ?: '');
    }

    public function testUpdatePersistsAGenuinelyChangedSecret(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('lastfm.shared_secret', 'brand-new-secret', 'string');
        // The store now reports the new value as effective — the PUT response
        // must still mask it on the way back out.
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => ['lastfm.shared_secret' => 'brand-new-secret'],
            'overridden' => ['lastfm.shared_secret'],
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['lastfm.shared_secret' => 'brand-new-secret']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertStringNotContainsString(
            'brand-new-secret',
            $response->body,
            'A freshly-saved secret must not be echoed back in the PUT response',
        );
        /** @var array{data: array{settings: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);
        $this->assertSame(SettingsMasker::MASK, $body['data']['settings']['lastfm.shared_secret']);
    }

    public function testUpdateSkipsOnlyTheMaskedSecretAndStillPersistsSiblings(): void
    {
        $written = [];

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('set')->willReturnCallback(
            function (string $key, mixed $value, string $type) use (&$written): void {
                $written[$key] = [$value, $type];
            },
        );
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => [
                // Untouched by the admin → must NOT be written.
                'lastfm.shared_secret' => SettingsMasker::MASK,
                // A genuinely edited secret sibling → must be written.
                'lastfm.api_key'       => 'brand-new-api-key',
                // A non-secret sibling → must be written.
                'lastfm.enabled'       => true,
            ]]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
        $this->assertArrayNotHasKey(
            'lastfm.shared_secret',
            $written,
            'The masked secret must not be overwritten with the sentinel',
        );
        $this->assertSame(['brand-new-api-key', 'string'], $written['lastfm.api_key']);
        $this->assertSame([true, 'bool'], $written['lastfm.enabled']);
        $this->assertCount(2, $written);
    }

    public function testUpdateResponseAlsoMasksSecrets(): void
    {
        $secretKeys = $this->secretKeys();

        $values = [];
        foreach ($secretKeys as $i => $key) {
            $values[$key] = sprintf('LEAKY-VALUE-%d-%s', $i, md5($key));
        }

        $repo = $this->createMock(SettingsRepository::class);
        $repo->method('getEffectiveMany')->willReturn([
            'values'     => $values,
            'overridden' => $secretKeys,
        ]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['hwaccel.enabled' => true]]),
            [],
        );

        $this->assertSame(200, $response->statusCode);

        /** @var array{data: array{settings: array<string, mixed>}} $body */
        $body = json_decode($response->body, true);

        foreach ($values as $key => $plaintext) {
            $this->assertStringNotContainsString(
                $plaintext,
                $response->body,
                sprintf('Secret %s leaked into the PUT response body', $key),
            );
            $this->assertSame(
                SettingsMasker::MASK,
                $body['data']['settings'][$key],
                sprintf('Secret %s must be replaced by the mask sentinel', $key),
            );
        }
    }

    // ---------------------------------------------------------------------
    // CONSTRAINT ENFORCEMENT — enum / minimum / maximum are enforced on WRITE,
    // not merely emitted to the UI for display.
    // ---------------------------------------------------------------------

    public function testUpdateRejectsAValueOutsideItsEnum(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['auth.signup_mode' => 'definitely-not-a-mode']]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{errors: array<string, string>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('auth.signup_mode', $body['errors']);
        $this->assertStringContainsString('enumeration', $body['errors']['auth.signup_mode']);
    }

    public function testUpdateAcceptsEveryDeclaredEnumMember(): void
    {
        foreach (['open', 'approval', 'disabled'] as $mode) {
            $repo = $this->createMock(SettingsRepository::class);
            $repo->expects($this->once())->method('set')->with('auth.signup_mode', $mode, 'string');
            $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

            $controller = new AdminSettingsController($repo);
            $response = $controller->update(
                $this->makeRequest(['settings' => ['auth.signup_mode' => $mode]]),
                [],
            );

            $this->assertSame(200, $response->statusCode, sprintf('signup_mode=%s must be accepted', $mode));
        }
    }

    public function testUpdateRejectsAValueBelowItsMinimum(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        // ffmpeg.max_concurrent_transcodes declares minimum 1.
        $response = $controller->update(
            $this->makeRequest(['settings' => ['ffmpeg.max_concurrent_transcodes' => 0]]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{errors: array<string, string>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('ffmpeg.max_concurrent_transcodes', $body['errors']);
        $this->assertStringContainsString('minimum', $body['errors']['ffmpeg.max_concurrent_transcodes']);
    }

    public function testUpdateAcceptsBothEndsOfAnIntegerRange(): void
    {
        // The bound is inclusive on both ends — a rejection test alone would
        // also pass against an off-by-one that rejects the legal extremes.
        foreach ([1, 64] as $value) {
            $repo = $this->createMock(SettingsRepository::class);
            $repo->expects($this->once())
                ->method('set')
                ->with('ffmpeg.max_concurrent_transcodes', $value, 'int');
            $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

            $controller = new AdminSettingsController($repo);
            $response = $controller->update(
                $this->makeRequest(['settings' => ['ffmpeg.max_concurrent_transcodes' => $value]]),
                [],
            );

            $this->assertSame(
                200,
                $response->statusCode,
                sprintf('ffmpeg.max_concurrent_transcodes=%d is within bounds and must be accepted', $value),
            );
        }
    }

    public function testUpdateRejectsAValueAboveItsMaximum(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        // ffmpeg.max_concurrent_transcodes declares maximum 64.
        $response = $controller->update(
            $this->makeRequest(['settings' => ['ffmpeg.max_concurrent_transcodes' => 999999]]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{errors: array<string, string>} $body */
        $body = json_decode($response->body, true);
        $this->assertStringContainsString('maximum', $body['errors']['ffmpeg.max_concurrent_transcodes']);
    }

    public function testUpdateRejectsAFloatOutsideItsBounds(): void
    {
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $restore = $this->withSyntheticFloatKey('ffmpeg.synthetic_float', 0.0, 1.0);

        try {
            $controller = new AdminSettingsController($repo);
            // ffmpeg.synthetic_float is bounded 0..1, so 5.0 must be rejected.
            $response = $controller->update(
                $this->makeRequest(['settings' => ['ffmpeg.synthetic_float' => '5.0']]),
                [],
            );

            $this->assertSame(400, $response->statusCode);
            /** @var array{errors: array<string, string>} $body */
            $body = json_decode($response->body, true);
            $this->assertArrayHasKey('ffmpeg.synthetic_float', $body['errors']);
            $this->assertStringContainsString(
                'maximum',
                $body['errors']['ffmpeg.synthetic_float'],
            );
        } finally {
            $restore();
        }
    }

    public function testUpdateStillAcceptsTheAutoDetectAcceleratorSentinel(): void
    {
        // phlix-shared v0.24.0 replaced the old JSON `null` "auto-detect" enum
        // member with an explicit EMPTY-STRING sentinel, which retired the
        // controller-side `applyNullEnumSentinelShim()` that used to translate
        // the SPA's `String(null)` → "null". The '' sentinel must round-trip
        // through PUT and be persisted verbatim.
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->once())
            ->method('set')
            ->with('transcoding.preferred_accelerator', '', 'string');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['transcoding.preferred_accelerator' => '']]),
            [],
        );

        $this->assertSame(200, $response->statusCode);
    }

    public function testUpdateRejectsTheRetiredNullStringAcceleratorSentinel(): void
    {
        // Regression guard for the removed shim: "null" is NOT an enum member
        // and must now be rejected. If this starts passing, the shim (or an
        // equivalent) has been reintroduced.
        $repo = $this->createMock(SettingsRepository::class);
        $repo->expects($this->never())->method('set');

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['transcoding.preferred_accelerator' => 'null']]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{errors: array<string, string>} $body */
        $body = json_decode($response->body, true);
        $this->assertStringContainsString(
            'enumeration',
            $body['errors']['transcoding.preferred_accelerator'],
        );
    }

    public function testUpdateAcceleratorEnumMatchesTheCorrectedFfmpegHwaccelNames(): void
    {
        // v0.24.0 also corrected the enum for accuracy: 'nvenc' is an FFmpeg
        // ENCODER, not an hwaccel, and 'v4l2' was renamed 'v4l2m2m'. Lock both
        // in so a future edit cannot quietly re-admit an invalid hwaccel name.
        $enum = AdminSettingsController::schemaMeta()['transcoding.preferred_accelerator']['enum'];

        $this->assertSame(
            ['', 'cuda', 'qsv', 'vaapi', 'videotoolbox', 'amf', 'opencl', 'd3d11va', 'dxva2', 'v4l2m2m'],
            $enum,
        );
        $this->assertNotContains('nvenc', $enum, 'nvenc is an encoder, not an hwaccel');
        $this->assertNotContains('v4l2', $enum, "v4l2 was corrected to 'v4l2m2m'");

        // And the enforcement actually rejects the removed members.
        foreach (['nvenc', 'v4l2'] as $retired) {
            $repo = $this->createMock(SettingsRepository::class);
            $repo->expects($this->never())->method('set');

            $controller = new AdminSettingsController($repo);
            $response = $controller->update(
                $this->makeRequest(['settings' => ['transcoding.preferred_accelerator' => $retired]]),
                [],
            );

            $this->assertSame(
                400,
                $response->statusCode,
                sprintf('%s is not a valid hwaccel and must be rejected', $retired),
            );
        }
    }

    public function testUpdateAcceptsEveryDeclaredAcceleratorEnumMember(): void
    {
        /** @var list<string> $enum */
        $enum = AdminSettingsController::schemaMeta()['transcoding.preferred_accelerator']['enum'];

        foreach ($enum as $member) {
            $repo = $this->createMock(SettingsRepository::class);
            $repo->expects($this->once())
                ->method('set')
                ->with('transcoding.preferred_accelerator', $member, 'string');
            $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

            $controller = new AdminSettingsController($repo);
            $response = $controller->update(
                $this->makeRequest(['settings' => ['transcoding.preferred_accelerator' => $member]]),
                [],
            );

            $this->assertSame(
                200,
                $response->statusCode,
                sprintf('accelerator=%s is a declared enum member and must be accepted', var_export($member, true)),
            );
        }
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
        // Any int-typed key works here; `newsletter.send_hour` is declared
        // `minimum: 0, maximum: 23`, so 9 is comfortably legal and the assertion
        // isolates STRING -> INT coercion rather than bounds.
        // (This used hwaccel.probe_timeout until phlix-shared v0.26.0 deleted that
        // consumerless key, then marker_detection.intro_max_duration until v0.28.0
        // deleted that one. Pick a key that is genuinely consumed, and re-check its
        // bounds when you swap: send_hour caps at 23, so the previous sample value
        // of 45 would now be rejected for the wrong reason.)
        $repo->expects($this->once())
            ->method('set')
            ->with('newsletter.send_hour', 9, 'int');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['newsletter.send_hour' => '9']]),
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
        // newsletter.send_hour expects int; a non-numeric string
        // is invalid. (This used hwaccel.probe_timeout until phlix-shared
        // v0.26.0 deleted that consumerless key.)
        $response = $controller->update(
            $this->makeRequest(['settings' => ['newsletter.send_hour' => 'not-a-number']]),
            [],
        );

        $this->assertSame(400, $response->statusCode);
        /** @var array{errors: array<string, mixed>} $body */
        $body = json_decode($response->body, true);
        $this->assertArrayHasKey('newsletter.send_hour', $body['errors']);
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
        // A float key exercises the valueMatchesType() float arm and the coerce()
        // float arm: a numeric string is accepted and coerced to float. Synthetic,
        // because the schema no longer declares any `number` property — see
        // withSyntheticFloatKey().
        $restore = $this->withSyntheticFloatKey('ffmpeg.synthetic_float', 0.0, 1.0);
        $repo->expects($this->once())
            ->method('set')
            ->with('ffmpeg.synthetic_float', 0.42, 'float');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['ffmpeg.synthetic_float' => '0.42']]),
            [],
        );
        $restore();

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
            ->with('trickplay.enabled', false, 'bool');
        $repo->method('getEffectiveMany')->willReturn(['values' => [], 'overridden' => []]);

        $controller = new AdminSettingsController($repo);
        $response = $controller->update(
            $this->makeRequest(['settings' => ['trickplay.enabled' => '0']]),
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
