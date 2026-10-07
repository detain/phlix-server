<?php

/**
 * S431 — the S427 census, EXECUTABLE: the five census numbers re-derived from
 * tokens at run time and pinned, instead of asserted as prose.
 *
 * ## Why this file exists
 *
 * S427 shipped `Request::__get`/`__isset` (posture (a): unguarded dynamic reads
 * of undeclared names throw, guarded shapes keep their defaults) and published
 * the tokenized census that licensed that posture as DECLARED PROSE inside
 * {@see RequestDynamicPropertyGuardTest}'s docblocks: 1,756 PHP files, 331
 * dynamic-free property reads on Request roots, 0 surviving dynamic reads,
 * 0 dynamic writes, 1,037 declared write sites. Prose drifts silently the
 * moment source moves — the numbers were measured once by a lane script that
 * never shipped, so nothing could re-check them. The W30 barrier recorded the
 * gap and spawned this step. Here the census becomes a test: every value is
 * re-tokenized from the working tree on every suite run, against the pins
 * below, exactly as {@see \Phlix\Tests\Unit\Support\WriteResultAdoptionGuardTest}
 * re-pins the S342 insert-consumer denominators (the ported pattern).
 *
 * ## The numbers, adjudicated at this tip (declared prose vs. runtime)
 *
 * | # | S427 prose | This test (measured, tip) | Verdict |
 * |---|------------|---------------------------|---------|
 * | 1 | 1,756 PHP files | 1,760 | DRIFT +4 — prose was measured at the lane's
 *   BASE commit `4b620f59`; the estate grew by S427's own guard test (+1), S228's
 *   two files (+2) and this file (+1); the prose did not even match the tree
 *   S427 merged into (1,757 at `aea41d37`). |
 * | 2 | 331 declared reads | 391 | DRIFT +60 — see reconciliation below;
 *   the load-bearing property — EVERY counted read names a declared member —
 *   holds (0 undeclared). |
 * | 3 | 0 dynamic reads | 0 | MATCH — the guard's throw can only fire on would-be-new code. |
 * | 4 | 0 dynamic writes | 0 | MATCH. |
 * | 5 | 1,037 declared writes | 940 | DRIFT −97 — exactly the 97
 *   offset-write sites (`$root->body['k'] = …`) this walk classifies as reads;
 *   see below. |
 *
 * ## The prose cannot be reproduced as written (the finding S431 exists to catch)
 *
 * The lane's scan script was never shipped, so its exact rules are unknown —
 * but its arithmetic cannot be reproduced from the published signals. Counting
 * every property site exactly once, this estate contains **1,331** named-name
 * sites on Request roots (this walk: 391 read-classified + 940
 * write-classified; under setter semantics the same population splits
 * 294 + 1,037). Strikingly, the prose WRITE number 1,037 is EXACTLY this
 * scan's 940 plain writes + the 97 offset-write sites — so the lane counted
 * `$root->body['k'] = …` as a write — yet its READ number 331 then cannot be
 * this population's 294: the lane's root set saw ~37 read sites this walk does
 * not (and sums to 1,368 vs 1,331 total), a difference no published signal
 * explains. The prose also carries an unshipped adjudication step ("332 reads,
 * 1 false positive ruled out by hand"), which no executable test could ever
 * re-derive. What survived the drift review: every qualitative
 * claim S427 made on top of the numbers is TRUE at this tip — zero undeclared
 * name reads, zero dynamic-name reads, zero dynamic writes — and those zero
 * pins, not the denominators, are what licenses the throwing `__get`.
 *
 * ## What is scanned, and what each number means
 *
 * Estate: every `*.php` below the repository root, excluding `vendor/` and
 * `node_modules/` path segments — the set `git ls-files '*.php'` matches
 * exactly on a clean checkout (measured, this commit). Site scanning carves
 * out ONLY the two census-fixture files listed in GUARD_FIXTURE_FILES: the
 * S427 guard test deliberately performs dynamic reads of undeclared names (it
 * is testing the runtime tripwire — counting its own probes would make the
 * zero-pins unfalsifiable from day one), and this file spells property names
 * in prose. Neither is production code; the 1,756-file estate count still
 * includes both.
 *
 * A **Request root** is a variable that carries a `Phlix\Server\Http\Request`
 * at run time, inferred per function scope from (union over enclosing scopes,
 * so `use ($request)` captures count):
 *  - typed parameters naming the class (`Request $x`, fully/relatively
 *    qualified, union/intersect members, aliases resolved through the file's
 *    `use` statements — `Workerman\Protocols\Http\Request` never matches);
 *  - `@param`/`@var Request $x` docblock hints on the function;
 *  - factory assignments: `$x = new Request()` / `new self()` / `new static()`
 *    (the latter two only inside Request.php), `$x = Request::fromGlobals(…)`,
 *    `…::fromWorkerman(…)`;
 *  - same-file factory CALLS: a function whose own body `return`s one of the
 *    above (or a visible root) is a Request factory, so `$x = $this->makeRequest(…)`
 *    inherits (this is what covers the test-suite helpers `authedRequest()`,
 *    `bearerRequest()`, `request()` …);
 *  - copy and clone propagation from a visible root (`$x = $root`,
 *    `$x = clone $root`, `$x = $root ?? <factory-expr>`);
 *  - `$this` inside `src/Server/Http/Request.php` itself.
 *
 * Per access `$root->name` (also `?->`):
 *  - **write**  — `name` directly followed by an assignment operator;
 *  - **read**   — every other non-call use, INCLUDING `$root->name['k'] = …`
 *    (only `__get` fires on that shape — there is no `__set`, and for a guard
 *    whose question is "can the throw ever fire in existing code", the getter
 *    classification is the behaviorally true one; 97 such offset sites exist
 *    at this tip and each hits the slot, never the guard, because the name is
 *    declared);
 *  - **dynamic** — `$root->$k` / `$root->{$expr}` — the PHPStan-blind shape
 *    S271's ghost property lived in;
 *  - **method call** — `name` followed by `(` — skipped (not a property site).
 *
 * The four zero-pinned sets (undeclared reads/writes, dynamic reads/writes)
 * name every offending `file:line name` in the failure text — plant a
 * `$request->jsonBody` anywhere and the suite points at it.
 *
 * ## Known limits — escapes accepted, not overlooked
 *
 *  - Root inference is name-scope textual, not a type system: cross-file
 *    helper indirection (`$x = (new Suite())->request()`), arrays/iterators of
 *    Requests (`foreach ($requests as $r)`), typed class properties
 *    (`$this->request->x` — none exist today), and `Request` sub-classes
 *    (none exist today) are NOT roots; a site only they reach escapes.
 *  - A factory recognised by name inside one file applies to same-named
 *    methods of sibling classes in that same file too (first definition wins);
 *    mis-marked roots can only ADD counted sites, and an undeclared property
 *    on such a lookalike would surface as a named undeclared-site failure —
 *    the loud direction, which is the right trade (WriteResultAdoptionGuardTest's
 *    "false positive costs more than an escape" discipline, with the opposite
 *    sign: here a false positive names itself and is reviewable).
 *  - Reads inside string interpolation (`"{$request->userId}"`) ARE counted —
 *    the engine really calls `__get` there, and `HttpHandler.php:143` is the
 *    live example.
 *
 * ## Re-pin procedure (the point of pinning)
 *
 * When a legitimate change moves the landscape, update the constant(s) in the
 * SAME commit and say why in the message — same law as S342's
 * EXPECTED_TOTAL_INSERT_CALLS. The four zeros are different: they are posture
 * claims, not bookkeeping; a non-zero value there is a regression to fix in
 * source, not a number to re-pin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Http;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RequestDynamicPropertyCensusExecutableTest extends TestCase
{
    /**
     * Code-resident lane token (never in any .md): proves at merge time that
     * this file — the executable census — is really tracked in the merged tree.
     */
    public const EXECUTABLE_CENSUS_TOKEN = 'S431-executable-census@e74cdc88';

    /** The class whose roots the census walks. */
    private const TARGET_CLASS = 'Phlix\\Server\\Http\\Request';

    /** The 17 declared public members of Request (mirrors RequestDynamicPropertyGuardTest). */
    private const DECLARED_MEMBERS = [
        'method', 'path', 'queryString', 'headers', 'query', 'body', 'rawBody',
        'files', 'remoteIp', 'remotePort', 'protocol', 'bearerToken', 'cookies',
        'userId', 'profileId', 'hubUser', 'pathParams',
    ];

    /**
     * Census number 1 — every `*.php` in the estate (excluding vendor/ and
     * node_modules/ segments), measured at this tip. S427 prose said 1,756 —
     * the count at its BASE `4b620f59`, never re-taken at its own merge tip.
     * Re-pinned 1760→1764 by S433: the delivery path adds four first-party
     * files (HookDelivery, HookDeliveryException, the probe test, the guard
     * test); the other four pins — both denominators and both posture zeros —
     * are untouched, as they must be for files that never name Request.
     * Re-pinned 1764→1768 by S434: the socket-guard path adds four first-party
     * files (CoroutineSocketGuard, CoroutineSocketFault, the refused exception,
     * the guard test); same reasoning — none of them names Request.
     * Re-pinned 1768→1769 by S436: adds the real-DB collection-members integration test.
     * Re-pinned 1769→1771 by S435: the boundary-decode path adds two first-party
     * files (RouterPathParamDecodingTest, MusicEncodedRouteParamE2eTest); the fix
     * itself lives in the existing Router.php, which never joined or left the tree.
     * Re-pinned 1771→1772 by S437: adds the health-route auth guard test. Unlike
     * the S433/S434/S435 additions, this one DOES name Request (its dispatch helper
     * assigns declared members), so it moves both this denominator and the
     * declared-write count — both re-pinned here in the same commit.
     * Re-pinned 1772→1773 by S438: adds the real-DB playback-finish integration
     * test; its request() helper assigns four declared Request members, so the
     * declared-write denominator moves with it in this same commit.
     * Re-pinned 1773→1775 by S439: the zero-residue census path adds two first-party
     * files (ZeroResidueCensusExtension, ZeroResidueCensusTest); neither names
     * Request, so every other pin here stays untouched — the S433/S434 pattern again.
     * Re-pinned 1775→1783 by S215: the collection-queue path adds eight first-party
     * files (CollectionJob, CollectionJobStore, CollectionWorker, config/collection_jobs.php,
     * run-collection-worker.php, the store test, the worker test, the worker fork test);
     * none names Request — the S433/S434 pattern again, only this denominator moves.
     * Re-pinned 1783→1785 by S252/S220: two test files (AuthProviderBadgeSignalsTest,
     * BlankRowHideRealDbIntegrationTest); neither names a Request property — the
     * S433/S434/S215 pattern again, only this denominator moves.
     * Re-pinned 1785→1787 by S289: the SyncPlay identity arc adds two first-party
     * test files (SyncPlayIdentityRestTest, SyncPlayIdentitySharedStoreIntegrationTest);
     * the first names Request in its dispatch helper (so the declared-write count
     * moves with it below), the second does not name Request at all.
     * Re-pinned 1787→1789 by S443: the UUID-entropy arc adds two test files
     * (UuidOrderSeedFuzzTest, UuidSeedReplayPkCollisionIntegrationTest); neither
     * names Request — the S433/S434/S215/S252/S289 pattern again, only this
     * denominator moves.
     * Re-pinned 1789→1790 by S161: the index-shape arc adds one test file
     * (UniqueIndexShapeGuardTest); it does not name Request — same pattern.
     * Re-pinned 1790→1786 by S227: the theming-island deletion removes five
     * .php files (ThemeRegistry, Theme, ThemePluginInterface, config/themes.php,
     * ThemeRegistryTest) and adds one guard test (ThemingIslandRemovedTest) —
     * a net −4; neither names Request — same pattern.
     * Re-pinned 1786→1787 by S155: the music-index-collapse arc adds one test
     * file (MusicMediaItemUniqueIndexGuardTest); it does not name Request —
     * same pattern.
      * Re-pinned 1787→1800 by S87: the metadata-write-back plumbing arc adds thirteen
      * first-party files (five Writer src classes, config/metadata_write_jobs.php,
      * six test files incl. the RecordingWriter double); none names Request —
      * same pattern.
       * Re-pinned 1800→1803 by S130: the size-bounded log policy adds three
       * first-party files (SizeRotatingFileHandler src + its unit test + the
       * LoggerConfigPolicyTest config guard); none names Request — same pattern.
       * Re-pinned 1803→1807 by S88: the sidecar writer arc adds four first-party
       * files (SidecarWriter + SidecarNotWritableException src, SidecarWriterTest +
       * SidecarWriterRegistrationTest); none names Request — same pattern.
        * Re-pinned 1807→1808 by S154: the migration-104 guard test
        * RescanEnumCommentGuardTest.php is one new file; it names no Request.
        * Re-pinned 1808→1823 by S61: the CLI user/admin arc adds fifteen
        * first-party files (Concerns/JsonOutput + seven User*Command src classes,
        * and one *CommandTest per command); none names Request — same pattern.
        * Re-pinned 1823→1832 by S89: the embedded-tag writer arc adds nine
        * first-party files (EmbeddedWritePolicy, EmbeddedMetadataWriter,
        * ExternalCommandRunnerInterface + ExecExternalCommandRunner,
        * EmbeddedWriteFailedException under src/; EmbeddedWritePolicyTest,
        * ExternalCommandRunnerTest, EmbeddedMetadataWriterTest,
        * EmbeddedMetadataWriterRealBinaryTest under tests/); none names
        * Request — same pattern, only this denominator moves.
        * Re-pinned 1832→1833 by S187: the unused-import reflow adds exactly one
        * first-party file (tests/Unit/Support/UnusedImportGuardTest.php, the
        * permanent detector); the reflow itself deletes zero files — 150 whole
        * `use` lines out of 100 existing ones. The guard names no Request.
        * Re-pinned 1833→1834 by S211: the absolute-config-dir fix adds exactly
        * one first-party file (tests/Unit/Server/Core/ApplicationConfigDirResolutionTest.php,
        * the CWD-independence regression + source guard); zero files deleted.
        * The guard names no Request.
        * Re-pinned 1834→1835 by S114: the stats_storage unique-key arc adds
        * exactly one first-party file
        * (tests/Integration/Stats/StatsStorageUniqueKeyUpsertGuardTest.php, the
        * real-MySQL proof for migration 105 — NULL-distinctness, the 1138
        * refusal, merge arithmetic and the accumulating upsert are observable
         * only against a live server); the write path is an edit, not a file.
         * The guard names no Request.
         * Re-pinned 1835→1838 by S266: the Timer-probe determinism fix adds three
         * first-party files (tests/Support/Workerman/WorkermanTimerFixture.php,
         * the shared fixture that makes the six LiveTv/Relay Timer cases always
         * run; tests/Unit/Support/WorkermanStaticStateLeakGuardTest.php and its
         * tests/Support/Workerman/StaticProbeBootstrap.php, the leak guard that
         * keeps Worker::$workers/Timer::$event from leaking again); the polluter
         * save/restore edits and the gate removals touch no file counts.
         * The guard names no Request.
         * Held at 1838 by S171: deleting the unserved front controller
         * public/index.php (−1) and adding its permanent removal guard
         * (tests/Unit/Docker/PublicFrontControllerRemovalGuardTest.php, +1)
         * cancel out — measured on the rebased tree, not predicted. The guard
         * names no Request.
         * Re-pinned 1840→1842 by S71: the generic image service extraction adds
         * two first-party files (src/Media/Storage/ImageResizer.php and
         * tests/Unit/Media/Storage/ImageResizerTest.php) — measured 1842 on this
         * tree, not predicted. The new service names no Request property.
         * Re-pinned 1842→1843 by S253 on the rebased tree: the web-ui bundle
         * gate adds exactly one first-party file
         * (tests/Unit/Support/WebUiBundleGateTest.php, the permanent guard
         * pinning the workflow's compare step, widened paths, exact node pin and
         * zero-corpus tripwire, with negative fuzz proving the pins bite); the
         * rest of the step is YAML plus a rebuilt bundle, so no other
         * denominator moves — measured 1843 on this tree, not predicted. The
          * guard names no Request.
          * Re-pinned 1843→1845 by S304: the executed PHP-extension contract gate
          * adds exactly two first-party files (scripts/assert-php-extensions.php,
          * the CI assertion itself, and tests/Unit/Support/PhpExtensionAssertGateTest.php,
          * its executed guard test) — measured 1845 by CI on this tree (Server
          * Component Tests red with "1845 is identical to 1843"), not predicted.
          * Neither file names a Request property.
          * Re-pinned 1845→1848 by S457: parallelizing the suite adds exactly three
          * first-party PHP files (scripts/parallel/merge-junit.php and
          * scripts/parallel/merge-coverage.php, the artifact reconstitution pair, and
          * tests/Unit/Support/ParallelTestWiringTest.php, their executed guard) —
          * measured 1848 by the paraunit p8 run on this tree, not predicted. The
          * scripts/parallel/php PATH shim and the shell scripts carry no .php suffix
          * and were already outside the scan's extension filter. None names a Request
          * property.
          * Re-pinned 1848→1849 by S153: the orphan-container reap adds exactly one
          * first-party file (tests/Integration/Media/Library/
          * OrphanMusicContainerReapIntegrationTest.php, the real-MySQL proof of the
          * CASCADE-safe reap and its four gates — the step changes src/ in place and
           * needs no migration) — measured 1849 on this tree, not predicted. Neither
           * file names a Request property.
           * Re-pinned 1849→1851 by S72: the person-keyed shared artwork cache adds
           * exactly two first-party files (tests/Unit/Media/Metadata/
           * LibraryMetadataMatcherPeopleCacheTest.php and tests/Unit/Media/Storage/
           * PersonCacheSharedDirectoryTest.php) — measured 1851 on this tree, not
           * predicted. The step ships no new src/ file (the cache rides the S71
           * ImageResizer and existing ArtworkStorage unchanged); neither file names
           * a Request property.
           * Re-pinned 1851→1857 by S73: the lazy-resize/SSRF step adds six
           * first-party files — src/Common/Net/ProviderUrlAllowlist.php and
           * src/Server/Http/FastPath/ArtworkByteResponder.php (the shared
           * conditional-GET responder extracted from serveArtwork), plus
           * tests/Unit/Common/Net/ProviderUrlAllowlistTest.php,
           * tests/Unit/Media/Storage/ArtworkFetchSsrfTest.php,
           * tests/Unit/Media/Storage/ArtworkLazyVariantTest.php and
           * tests/Unit/Server/WebPortal/WebPortalRouterPersonPhotoTest.php —
           * measured 1857 on this tree, not predicted. None names a Request
           * property (the responder reads only declared members).
           * Re-pinned 1857→1858 by S65: the machine-readable OpenAPI spec adds exactly
           * one first-party PHP file (tests/Unit/Server/Contracts/OpenapiSpecCurrencyTest.php,
           * the currency guard that recomposes both routers live and fails on any drift
           * between openapi.yaml and the served route registry) — measured on this
           * tree, not predicted. openapi.yaml and redocly.yaml are not PHP and so never
           * enter the estate count. The new test reads/writes no Request property (it
           * touches only the static RequestContext setters and the two Router objects),
           * so every other census denominator is unchanged.
           * Re-pinned 1858→1859 by S456: the resize-consolidation census adds exactly
           * one first-party PHP file (tests/Unit/Media/Storage/
           * ResizeImplementationCensusGuardTest.php — the guard pinning ImageResizer as
           * the estate's sole imagecopyresampled()/imagecopyresized() home, plus the two
            * golden-byte delegation pins); measured on this tree, not predicted. Its one
            * Request write (`$request->query = […]`, a declared member, the S427 license
            * intact) lifts EXPECTED_DECLARED_WRITES by one in this same commit.
            * Re-pinned 1859→1860 by S309: pinning the php-uv CI clones adds exactly one
            * first-party PHP file (tests/Unit/Support/ThirdPartyClonePinGuardTest.php — the
            * guard that parses the workflow YAML and pins the clone to an immutable SHA,
            * failing if any site drifts back to unpinned); measured on this tree, not
            * predicted (CI computed 1860). The new test reads/writes no Request property (it
             * touches only YAML + Dockerfile text), so every other census denominator is
             * unchanged.
             * Re-pinned 1860→1861 by S474: the docker immutable-tags wiring guard adds
             * exactly one first-party PHP file (tests/Unit/Support/
             * ServerRuntimeImmutableTagsWiringTest.php — the change-detector that parses
             * docker.yml and pins the sha-suffixed immutable tag list on the runtime
             * publish path, with mutation negative controls); measured on this tree, not
             * predicted (phpunit computed 1861). The new test reads/writes no Request
             * property (it touches only workflow YAML text), so every other census
             * denominator is unchanged.
             * Re-pinned 1861→1862 by S475: adds exactly one first-party PHP file
             * (tests/Unit/Support/ImagePinDriftGuardTest.php — the helm/example
             * `:latest` drift guard); measured on this tree, not predicted. It
             * reads/writes no Request property (it touches only YAML, compose and
             * doc text), so every other census denominator is unchanged.
             * Re-pinned 1862→1863 by S485: adds exactly one first-party PHP file
             * (tests/Unit/Support/ParaunitVerdictExactnessTest.php — the guard that
             * executes run-suite.sh's paraunit verdict against REAL paraunit 2.11.0
             * artifacts: warnings-only non-fatal, ERRORS/FAILURES/[UNKNOWN]/RISKY/
             * abnormal fatal, verbatim propagation, plus load-bearing mutation
             * controls); measured on this tree, not predicted. It reads/writes no
             * Request property (it touches only scratch files and subprocesses),
             * so every other census denominator is unchanged.
             * Re-pinned 1863→1864 by S417: the outbound frame-shape conformance adds
             * exactly one first-party PHP file (tests/Unit/Session/SyncPlay/
             * OutboundFrameShapeGuardTest.php — the per-site guard pinning every
             * outbound SyncPlay frame to the Messages factory envelope
             * {protocol_version, timestamp:ms}, with planted-bypass mutation proof and
             * source tripwires against the retired seconds-based sendFlat spellings);
             * measured on this tree, not predicted. It reads/writes no Request property
             * (it drives WebSocket connections only), so every other census denominator
             * is unchanged.
             * Re-pinned 1864→1872 by S445: the write-through publish bridge adds eight
             * first-party PHP files — src/Session/SyncPlay/{SyncPlayBridge,
             * SyncPlayBridgePublisher,SyncPlayBridgeListener}.php (the internal envelope
             * factory, the HTTP-side bounded publisher, the WS-side unix listener),
             * scripts/syncplay-bridge-smoke.php (the two-process fork smoke),
             * tests/Support/SyncPlay/InMemorySyncPlaySnapshotService.php (the DB-free
             * store the identity-rest unit venue now injects), and the three bridge tests
             * (Unit envelope/transport, Unit apply, Integration AC) — measured 1872 on
             * this tree, not predicted (phpunit computed it). None reads or writes a
             * Request property in src (the controller rails keep their existing reads;
             * the leave rail's group id rides the router's $params array), so the read
             * denominator is unchanged.
             * Re-pinned 1872→1873 by S240: one file joined the tree —
             * tests/Integration/Server/Http/MusicQueryParamRouteTest.php, the AC venue
             * for the additive music name-on-query-param routes. Measured 1873 from the
             * phpunit red, not predicted.
             * Re-pinned 1873→1874 by S446: the member-sync nudge policy adds exactly
             * one first-party PHP file (tests/Unit/Session/SyncPlay/
             * SyncPlayMemberSyncNudgeTest.php — the live end-to-end pair that retires
             * S291's isInSync dead-code guard: out-of-sync report yields exactly one
             * bounded nudge, in-sync yields none, plus cooldown, positionless-report
             * inertness, frame-envelope conformance and the bridge non-clobber rule);
             * measured on this tree by phpunit, not predicted. It reads/writes no
             * Request property (it drives WebSocket connections and in-memory managers
             * only), so every other census denominator is unchanged.
             * Re-pinned 1877→1889 by S518: the quick-connect + telemetry bundle adds
             * twelve first-party PHP files — src/Auth/{QuickConnectPair,
             * QuickConnectStateStoreInterface,QuickConnectStateStore}.php,
             * src/Stats/{ClientHeartbeatStoreInterface,ClientHeartbeatStore}.php,
             * src/Server/Http/Controllers/Auth/QuickConnectController.php, and six test
             * files (Unit store/controller/rate-limit/heartbeat, Integration real-DB
             * store lifecycle + migration-106 upsert guard); measured 1889 from the
             * phpunit red, not predicted. The controller adds four declared-member READS
             * and the two test request builders ten declared WRITES — pinned below in
             * this same commit, S427 license intact.
             * Re-pinned 1889→1892 by W2 (error-code emit lane): three first-party test
             * files joined the tree — tests/Support/Contracts/ErrorCodeScan.php and
             * tests/Unit/Contracts/{ErrorCodesContractTest,ErrorCodesOpenApiEnumContractTest}.php.
             * None reads or writes a Request property (they tokenize src/, resolve class
             * constants and parse the vendored registry), so every other census
             * denominator is unchanged — the four sibling pins passing in the same run
             * proves it. Measured 1892 from the phpunit red, not predicted.
              * Re-pinned 1892→1893 by the twin-flip lane: one first-party test file
              * joined the tree — tests/Unit/Session/SyncPlay/
              * SyncPlayTwinFlipErrorFrameTest.php. It drives the WS manager through its
              * own test doubles and touches no Request property, so every other census
              * denominator is unchanged — the four sibling pins passing in the same run
              * proves it. Measured 1893 from the phpunit red, not predicted.
              * Re-pinned 1893→1892 by the ce295f9d..9ec30913 rework: net −1
              * first-party PHP file across the four-push batch — the signature-wiring
              * commit added three (src/Plugins/Signature/TrustedSignaturesConfig.php,
              * tests/Unit/Common/Container/Providers/PluginsProviderSignatureWiringTest.php,
              * tests/Unit/Plugins/Signature/TrustedSignaturesConfigTest.php) and the
              * hygiene sweep deleted four vestigial src/Plugin/*.php files; the
              * relay-consumer lane modified only existing files. None of the added or
              * deleted files reads or writes a Request property, so every other census
              * denominator is unchanged — the four sibling pins passing in the same run
              * proves it. Measured 1892 from the phpunit red (and `git ls-files
              * '*.php' | wc -l` = 1892), not predicted. The next lane that moves a
              * first-party PHP file re-pins again the same way.
              * Re-pinned 1892→1898 by the auth-security lane (H-1/M-1..M-6, 2026-09-29):
              * the lane adds five first-party PHP files — src/Auth/WebAuthn/
              * WebAuthnChallengeStore.php plus four test files (Unit/Auth/WebAuthn/
              * WebAuthnCeremonyTest, Unit/Auth/{AuthManagerLogoutRevocationTest,
              * AuthManagerOpdsThrottleTest}, Unit/Access/AccessScheduleServiceWriteResultTest).
              * The HEAD it landed on (58bdd652) already measured 1893/990 against this
              * pin's 1892/983 — one file and seven site-verified write sites drifted
              * in WITHOUT a re-pin (the media/discovery lane's filter gates do not
              * name this test, and 13cdaf60 — which set the current pins — measured
              * green, so the drift is 58bdd652's alone); this commit absorbs the
              * whole measured gap so master goes green whole, not green-per-filter.
              * None of the five new files reads or writes a Request property; the
              * +2 reads / +1 write deltas are pinned with their provenance under
              * census numbers 2 and 5 below. Measured 1898/397/991 from the phpunit
              * red on the final tree, not predicted.
              * Re-pinned 1898→1897 by the SyncPlay security lane (2026-09-29):
              * deletes the dormant second-membership-truth file src/Server/
              * WebSocket/SyncPlay/SyncPlayRoom.php (LOW-4 — zero src instantiation;
              * grep-verified it names no Request property, so reads/writes are
              * untouched) and adds zero first-party PHP files — every new test
              * case was appended into an existing test file. Measured from
              * `git ls-files '*.php'` plus the phpunit red on the clean worktree
              * of the landed commit, not predicted.
              * Re-pinned 1897→1906 by the LiveTv/Mdns security lane (2026-09-29):
              * the lane ADDS seven first-party PHP files — src/LiveTv/
              * BoundedBodyReader.php, src/LiveTv/Tuners/Iptv/StreamUrlGuard.php
              * and M3UPlaylistOversizedException.php, plus four test files
              * (Unit/Discovery/Mdns/MdnsNameDecompressionTest, Unit/LiveTv/
              * Tuners/Iptv/StreamUrlGuardTest, Unit/LiveTv/Epg/SchedulesDirect/
              * SdEpgServiceFactoryTokenCacheTest, Unit/LiveTv/RecorderSpawnLogTest);
              * all other lane work appended into existing files. The pin ALSO
              * absorbs the auth lane's +2 (231d76f1 added the tests
              * FirstAdminElectionBackfill109RealDbTest and
              * AuthManagerSessionTeardownWiringGuardTest without re-pinning —
              * `git ls-tree -r 231d76f1 | grep -c '\.php$'` = 1899 vs pin 1897),
              * so master goes green whole, not green-per-filter. None of the
              * nine files names an undeclared Request property beyond what the
              * other five rails already pin, so every other census denominator
              * is untouched (proved by the sibling rails passing on the clean
              * worktree of the landed commit). Measured `git ls-files
              * '*.php' | wc -l` = 1906 on the staged tree, not predicted.
              * Re-pinned 1906→1910 by the device-integration audit lane: adds
              * four first-party PHP files (src/Common/Net/LanEndpointGuard.php,
              * tests/Unit/Common/Net/LanEndpointGuardTest.php,
              * tests/Unit/Dlna/DeviceRegistrySsrfTest.php and
              * tests/Unit/LiveTv/Tuners/HdHomeRun/HdHomeRunDiscoverySsrfTest.php);
              * none names a Request property — the S433/S434 pattern again, only
              * this denominator moves. Measured 1910 from the phpunit red, not
              * predicted.
              * Re-pinned 1910→1911 by the device-lane REWORK-LITE close (the
              * review of d052b488): adds one first-party PHP file
              * (tests/Unit/Dlna/RendererControlClientLanGateTest.php — the
              * executed constructor LAN-gate coverage the Exception 6 citation
              * demanded; the legacy @group network file guards nothing in the
              * default suite). Every other rework test appended into an existing
              * file. Names no Request property; only this denominator moves.
              * Measured 1911 from the phpunit red, not predicted.
              * (Bridge note — da70cfa8 M-1/M-2/M-3 lane moved 1911→1913 with
              * its two new test files, StreamLimitSyntheticSessionBucketTest and
              * MusicScanAdminGateTest, without prose; verified via
              * `git diff --name-status 9e765895..da70cfa8`.)
              * Re-pinned 1913→1916 by the M-4/M-6/L-1/L-2 security lane
              * (2026-09-30): adds three first-party test files — Unit/Server/
              * Integrations/Trakt/SodiumTokenCipherTest.php, Unit/Server/Core/
              * ServersAndJobsAuthGateTest.php and Unit/Server/Core/
              * TraktOAuthFactoryWiringGuardTest.php; all other lane work appended
              * into existing files. Measured 1916 from the phpunit red, not
              * predicted.
              */
    /**
     * Re-pinned 1916→1920 by the L-bundle security-hygiene lane (2026-09-30):
     * −2 first-party files (L-5 deleted the dead-wired HubJwtMiddleware class
     * and its test), +6 new test files (SystemInfoPayloadHygiene,
     * LibraryControllerPathsRedaction, SecurityHeadersCaseInsensitiveGuard,
     * PreRouterFastPathsSyntheticSessionBucket, ChromecastControllerErrorHygiene,
     * WebPortalRouterPathsRedaction). Re-pinned 1920→1921 by the F-08 metadata
     * allowlist lane (+1 test file, MediaItemControllerMetadataMerge). Measured
     * from the phpunit red on the final tree (the scan is filesystem-recursive,
     * so the count is commit-invariant).
     */
    /**
     * Re-pinned 1921→1926 by the NAT-PMP full-RFC-citizenship lane (2026-10-01):
     * +5 first-party files — src/Network/NatPmpMaintenance.php (pure decision
     * core) and src/Network/NatPmpMaintenanceWorker.php (resident listener/timer
     * worker) plus their three test files (NatPmpMaintenanceTest,
     * NatPmpMaintenanceWorkerTest, NatPmpAnnouncementWireTest). The lane touches
     * no Request property; the reads/writes denominators are re-verified from
     * the same run that reddened this pin (they stayed green). Measured 1926
     * from the phpunit red, not predicted.
     * Re-pinned 1926→1927 by the M-5 collections interim admin-gate lane
     * (2026-10-01): +1 file — tests/Unit/Server/Core/CollectionsAdminGateTest.php.
     * Measured from the phpunit red, not predicted.
     * Re-pinned 1938→1941 by the MED-2 room-visibility lane (2026-10-02):
     * +3 test files — Unit WS visibility, Unit REST visibility and the
     * Integration real-DB membership proof. Measured from the phpunit red,
     * not predicted.
     * Re-pinned 1941→1942 by the LiveTV parental-gate lane (2026-10-02):
     * +1 file — tests/Unit/Server/Http/Controllers/LiveTvRecordingParentalGateTest.php.
     * Measured from the phpunit red, not predicted.
      * Re-pinned 1942→1943 by the device-M1 ship-review micro-lane (2026-10-02):
      * +1 file — tests/Unit/Casting/CastingWiringGuardTest.php (the real-DB
      * cross-connection proof joined the EXISTING CastingSessionStoreRealDbTest,
      * so EXPECTED_ADOPTERS stays 65 — per-file census). Measured from the
      * phpunit red, not predicted.
      * Re-pinned 1944→1945 by the collections-ownership lane (2026-10-02):
      * net +1 — +2 new files (tests/Unit/Server/Core/CollectionsOwnerGateTest.php
      * replacing the deleted tests/Unit/Server/Core/CollectionsAdminGateTest.php
      * 1:1, plus the new
      * tests/Integration/Collections/CollectionsOwnershipMigration112RealDbTest.php).
      * Measured from the phpunit red, not predicted.
      * Re-pinned 1945→1951 by the W3 settings-program metadata lane (2026-10-03):
      * net +6 — 2 new src/ files (src/Media/Metadata/MatchConfidencePolicy.php,
      * src/Media/Metadata/MetadataCachePolicy.php) and 4 new tests/Unit/Media/
      * Metadata/ files (MatchConfidencePolicyTest, MetadataCachePolicyTest,
      * MovieMetadataResolverConfidenceTest, MetadataManagerCacheTtlTest). Zero
      * Request-root read/write sites added (metadata services touch no HTTP
      * Request), so EXPECTED_DECLARED_READS/WRITES stay untouched. Measured
      * from the phpunit red, not predicted.
      * Re-pinned 1951→1961 by the F7 auth-method-policy lane (2026-10-03):
      * net +10 — 3 new src/Auth/ files (AuthMethodPolicy.php,
      * AuthMethodLockoutException.php, AuthMethodDisabledException.php) and 7
      * new tests/Unit/ files (Auth/AuthMethodPolicyTest,
      * Auth/AuthManagerAuthMethodGateTest,
      * Auth/WebAuthn/WebAuthnControllerAuthMethodGateTest,
      * Auth/AuthProviderControllerDisableGuardTest,
      * Auth/AuthMethodPolicyWiringGuardTest,
      * Server/Http/Controllers/Admin/AdminSettingsControllerAuthGuardTest,
      * Admin/AuthMethodFlagsReachabilityTest). Measured from the phpunit red
      * (1961), not predicted.
      * Re-pinned 1961→1972 by the W2+W4 discovery/security settings lane
      * (2026-10-03): net +11 — 2 new src/ policy classes
      * (src/Discovery/DiscoveryPolicy.php,
      * src/Server/Http/Middleware/SecurityHeadersPolicy.php), 1 new config/
      * file (config/security.php — the W4 defaults, net-new-file precedent
      * stats.php/dlna.php), and 8 new tests (Unit/Discovery/: DiscoveryPolicy-
      * Test, DiscoveryServerGateTest, DiscoveryPolicyWiringGuardTest;
      * Unit/Server/Http/Middleware/: SecurityHeadersPolicyTest,
      * SecurityHeadersEmissionTest, SecurityHeadersPolicyWiringGuardTest;
      * Unit/Admin/PhaseW2W4SettingsReachabilityTest;
      * Unit/Server/Core/StatsBootstrapSingleSourceTest). Zero Request-root
     * read/write sites added — the emission tests construct Requests only
     * via declared constructor params (S427 shape), so the reads/writes
     * denominators stay untouched. Measured from the phpunit red (1972),
     * not predicted.
     * Re-pinned 1972→1973 by the F7-rework + W3-wiring-guard lane (2026-10-03):
     * net +1 — 1 new tests/Unit/Media/Metadata/ file
     * (MetadataPoliciesWiringGuardTest.php, the W3 settings-policies
     * binding-guard the P2 review demanded). The AuthMethodPolicy staleness
     * fix touched only existing files. Zero Request-root read/write sites
     * added, so EXPECTED_DECLARED_READS/WRITES stay untouched. Measured from
     * the phpunit red (1973), not predicted.
     * Re-pinned 1973→1974 by the v0.51.0 re-vendor lane (2026-10-05):
     * net +1 — the deferred F7 integration proof
     * tests/Integration/Admin/AdminSettingsRealSchemaPutTest.php (real-MySQL
     * PUT admission/bounds/guard/persistence through the newly vendored
     * 84-key schema). Measured from the phpunit red (1974), not predicted.
     * Re-pinned 1974→1976 by the B2 auth-lock lane (2026-10-07):
     * net +2 — the in-transaction re-validation proofs
     * tests/Unit/Server/Http/Controllers/Admin/AdminSettingsControllerAuthLock-
     * Test.php (statement-order/liveness/rollback/zero-lock pins on a
     * recording fake Connection) and
     * tests/Integration/Admin/AdminSettingsAuthLockB2Test.php (real-MySQL
     * sequential double-disable + two-connection FOR UPDATE blocking probe).
     * Measured from the phpunit red (1976), not predicted.
     * Re-pinned 1976→1980 by the B2 provider-parity lane (2026-10-07):
     * net +4 — the shared-protocol extraction
     * src/Auth/AuthMethodGuardUnwiredException.php +
     * src/Auth/AuthMethodGuardCheckFailedException.php (typed protocol
     * failures both guarded surfaces map to their byte-identical 500
     * envelopes), tests/Unit/Server/Http/Controllers/Admin/
     * AdminSettingsProviderLockParityTest.php (statement-order/lock-set/
     * rollback/enable-zero-txn pins on the same recording-fake lens as the
     * admin suite) and tests/Integration/Admin/
     * AdminSettingsProviderLockParityB2Test.php (real-MySQL cross-surface
     * blocking probes in both directions). Measured from the phpunit red
     * (1980), not predicted.
     */
    private const EXPECTED_PHP_FILES = 1980;

    /**
     * Census number 2 — dynamic-free property READS on Request roots, all on
     * declared members. S427 prose said 331 (drift +60 here; the prose's own
     * 332-minus-hand-ruled-1 arithmetic was not executable — see header).
     * Re-pinned 391→390 by S289: SyncPlayController::leaveGroup no longer copies
     * `$request->body` (a declared-member read) — identity is now the JWT subject
     * `$request->userId` (already read for the empty-guard), so the mutation reads
     * one fewer Request member. The other four rails are unchanged.
     * Re-pinned 390→391 by S73: the conditional-GET responder extracted out of
     * `PreRouterFastPaths::serveArtwork()` into
     * `src/Server/Http/FastPath/ArtworkByteResponder.php` is VERBATIM — its
     * Request-member reads (`userId`, `query` ×2 for the signed-URL arms) moved
     * with the code, so the extraction nets zero. The +1 is the new
     * `$request->query['w']` read in `WebPortalRouter::getPersonPhoto()`.
     * Every read stays on a declared property (S427 license — the
     * undeclared-read test in this file, not this number, guards that).
     * Re-pinned 391→395 by S518: `QuickConnectController` adds exactly four
     * declared-member reads — `$request->body` snapshotted once in each of
     * approve/token/heartbeat and `$request->userId` in approve's auth gate
     * (headers reach only through the `getHeader()` method, and the rate keys
     * only through `getTrustedClientIp()` — neither is a property read).
     * Re-pinned 395→397 by the auth-security lane (M-1/M-4, 2026-09-29):
     * `AuthController::logout` snapshots `$request->userId` for its server-side
     * revocation branch, and `SessionController::endSession` now reads
     * `$request->userId` a second time to pass the owner into the ownership-
     * scoped `SessionManager::endSession` (the comparison already read it
     * inline). The signed-URL/OPDS middleware changes call only methods
     * (`getTrustedClientIp()`), never properties; the five new files touch no
     * Request at all. Measured from the phpunit red on the final tree.
     * Re-pinned 398→423 by the M-4/M-6/L-1/L-2 security lane (2026-09-30): the
     * M-6 fix replaces the dead `setUserId()` property with request-identity
     * reads (`$request->userId` ×6 across the five book/audiobook handlers)
     * and canonical-body reads (`$request->body` ×2 in the two POST handlers);
     * the M-4 fix reads `$request->userId` in `authorize()` (initiator binding)
     * and `callback()` (identity match), and its test doubles read the declared
     * members their fixtures assert on. Every read names a DECLARED member.
     * Measured 423 from the phpunit red on the MERGED tree (which already
     * carries the da70cfa8 M-1/M-2/M-3 lane: an undocumented 397→398 bump from
     * its MediaItemController read sites), not predicted.
     * Re-pinned 423→422 by the L-bundle security-hygiene lane (2026-09-30):
     * net −1 declared-member READ — the L-5 deletion removed more Request
     * property reads (HubJwtMiddleware::__invoke + its test's hubUser asserts)
     * than the six new/updated test fixtures added. Every read still names a
     * declared member. Measured from the phpunit red, not predicted.
     * Re-pinned 444→446 by the MED-2 room-visibility lane (2026-10-02): +2
     * declared-member READS — the SyncPlayController read rails now gate on
     * the requester's identity ($request->userId in listGroups + getGroup).
     * Measured from the phpunit red, not predicted.
     * Re-pinned 446→448 by the LiveTV parental-gate lane (2026-10-02): +2
     * declared-member READS — LiveTvStreamController::recordingOverCap()
     * ($request->userId, production) and the new test's anonymous-signature
     * pin ($anonymous->userId assertNull). Measured from the phpunit red,
     * not predicted.
     */
    private const EXPECTED_DECLARED_READS = 448;

    /**
     * Census number 5 — property WRITES (name directly assigned) on Request
     * roots. S427 prose said 1,037 (drift −97 — the 97 offset-write sites; unreachable under either
     * bracket convention — see header).
     * Re-pinned 940→949 by S435: the two new test classes assign nine declared
     * Request members directly (method/path ×4 unit sites, userId in the e2e
     * dispatch helper — every one on a DECLARED property, the S427 license intact).
     * Re-pinned 949→952 by S437: HealthRoutesAuthGuardTest's request() helper
     * assigns three declared Request members directly (method/path/remoteIp).
     * Re-pinned 952→956 by S438: PlaybackFinishIntegrationTest's postRequest()
     * helper assigns four declared Request members directly
     * (method/path/userId/body) — S427 license intact.
     * Re-pinned 956→960 by S289: SyncPlayIdentityRestTest's request() helper assigns
     * four declared Request members directly (method/path/userId/body) — the same
     * S438 shape, S427 license intact. The SyncPlayController identity change itself
     * adds no write sites (it reads $request->userId, already a declared read).
     * Re-pinned 960→964 by S73: tests/Unit/Server/WebPortal/
     * WebPortalRouterPersonPhotoTest.php's request() helper assigns four declared
     * Request members directly (method/path/userId/query) — the same S438/S289
     * shape, S427 license intact. The src/ change adds no write site.
     * Re-pinned 964→965 by S456: tests/Unit/Media/Storage/
     * ResizeImplementationCensusGuardTest.php's photo golden-byte pin assigns one
     * declared Request member directly ($request->query = […]) — the same
     * S438/S289/S73 shape, S427 license intact. The consolidation itself moves GD
     * resize arithmetic into ImageResizer and touches no Request property.
     * Re-pinned 965→969 by S445: tests/Integration/Session/SyncPlay/
     * SyncPlayWriteThroughBridgeTest.php's httpRequest() helper assigns four declared
     * Request members directly (method/path/userId/body) — the same S438/S289/S73
     * shape, S427 license intact. The src/ bridge adds no Request write site.
     * Re-pinned 969→970 by S240: tests/Integration/Server/Http/MusicQueryParamRouteTest.php's
     * dispatchWire() helper assigns one declared Request member directly
     * ($request->userId — the entry-point identity stamp, same S435 lane shape,
     * S427 license intact). The src/ handlers reach the name only through the
     * queryString() method; the READS denominator is measured unchanged (green at
     * the existing pin), and the zero-dynamic posture is untouched.
     * Re-pinned 970→973 by S508: tests/Unit/Server/Http/Controllers/
     * MediaItemControllerTest.php's three new playback-constraint pins each assign one
     * declared Request member directly (`$request->query = [...]` — the S435/S438
     * entry-point shape, S427 license intact). The src/ S508 change adds no write site.
     * The READS denominator stays pinned at 391 (green): the new `queryTruthy` reads in
     * `MediaItemController::playbackConstraintsFromQuery(Request $request): ?array`
     * are NOT counted for the same deterministic reason the pre-existing
     * `resolveRatingFilter(Request $request): ?array` reads are not — the final-parameter
     * branch of `rootsDeclaredInParams()` is dead: at the params-closing paren `$depth--`
     * runs BEFORE the `$depth !== 1` segment-boundary check, so depth is already 0 when the
     * boundary test fires and the last segment never registers. EVERY last parameter is
     * thereby unregistered as a Request root — any arity, any type or return shape, not just
     * single-param `?array` privates (`functionScopes()` itself does emit these method
     * scopes). This is a long-standing, version-independent property of the
     * tokenizer walk (identical under CI's PHP 8.3.16), not a change S508 introduces;
     * fixing the blind spot is out of scope and would move the denominator for code
     * S508 never touched.
     * Re-pinned 973→983 by S518: the two new controller-test request builders and
     * their per-case overrides assign ten declared Request members directly
     * (QuickConnectControllerTest: userId/body/headers/remoteIp in request(), plus
     * a query-override and a body-override site; QuickConnectControllerRateLimitTest:
     * the same quartet in xffRequest()) — the S435/S438/S289 entry-point shape,
     * S427 license intact. The src/ controller adds no write site.
     * Re-pinned 983→991 by the auth-security lane (2026-09-29), absorbing two
     * batches measured from the phpunit red on the final tree (HEAD probe: the
     * census at 13cdaf60 measured green at its own 1892/395/983; the media/
     * discovery lane 58bdd652 landed +1 file/+7 writes WITHOUT a re-pin — seven
     * site-verified test-builder assignments on $request->userId: six
     * `'user-1'/'viewer-1'` stamps and one `= null` — its one new src read
     * nets to zero against a replaced read; the census is not in that lane's
     * filter-gate vocabulary, which is how it slipped) plus this lane's own
     * +1 write (AuthControllerTest's logout identity-stamp, the same S435
     * entry-point shape) and +2 reads (documented under census number 2).
     * Every assignment lands on a DECLARED property; S427 license intact.
     * Re-pinned 1000→1014 by the M-4/M-6/L-1/L-2 security lane (2026-09-30):
     * the lane's request builders assign declared members only — `method`/
     * `path`/`remoteIp` in the two new production-router guard tests' helpers
     * (ServersAndJobsAuthGateTest, TraktOAuthFactoryWiringGuardTest), `query`/
     * `userId` in the rewritten Trakt callback fixture, and the book/audiobook
     * progress fixtures' `userId`/`body` stamps (the S435/S438 entry-point
     * shape again). Measured 1014 from the phpunit red on the MERGED tree
     * (absorbing da70cfa8's undocumented 991→1000 bump — its StreamLimit/
     * synthetic-session test request stamps), not predicted.
     * Re-pinned 1014→1018 by the L-bundle security-hygiene lane (2026-09-30):
     * net +4 declared-member WRITE — the six added test files stamp request
     * fixtures (`method`/`path`/`userId`/`headers`/`body`), minus the writes
     * removed with the L-5 HubJwtMiddleware deletion (its `$request->hubUser`
     * assignment) and its test. Re-pinned 1018→1019 by the F-08 metadata
     * allowlist lane: the new MediaItemControllerMetadataMerge test's single
     * `$request->body` patch-builder stamp. Measured from the phpunit red on
     * the final tree, not predicted.
     * Re-pinned 1024→1032 by the MED-2 room-visibility lane (2026-10-02):
     * +8 declared-member WRITES — the two new request() fixture builders in
     * SyncPlayVisibilityRestTest and SyncPlaySnapshotMembershipsRealDbTest
     * each stamp `method`/`path`/`userId`/`body` (4×2). Measured from the
     * phpunit red, not predicted.
      * Re-pinned 1032→1033 by the LiveTV parental-gate lane (2026-10-02):
      * +1 declared-member WRITE — the new test's cappedRequest() fixture
      * stamps `$req->userId`. Measured from the phpunit red, not predicted.
      * Re-pinned 1033→1030 by the collections-ownership lane (2026-10-02):
      * net −3 declared-member WRITES — CollectionsOwnerGateTest replaces
      * CollectionsAdminGateTest at +3 (the dispatch() quintet survives
      * verbatim; a new structural GET probe adds method/path/body), while
      * CollectionControllerTest collapses its eight per-test inline body
      * stamps into one shared request() helper at 2 write sites
      * (`$request->userId` + conditional `$request->body`, the S435
      * entry-point shape, S427 license intact). The src/ change reads only
      * (one new `$request->userId` read inside actorUserId()); the READS
      * denominator was re-verified green at its existing pin in the same
       * run — the last-parameter root-registration blind spot documented
       * under census number 2 keeps `actorUserId(Request $request)`'s single
       * parameter out of the counted roots. Measured from the phpunit red,
       * not predicted.
       * Re-pinned 1030→1033 by the shaper `library_id` lane (2026-10-02):
       * +3 declared-member WRITES — the new dispatch()-seam wire pin in
       * WebPortalRouterMediaTest stamps `$request->method`/`->path`/`->userId`
       * on its own `new Request()` root, the same S101 dispatch-test shape it
       * sits beside. All three name declared members; S427 license intact.
       * Measured from the phpunit red (1033), not predicted.
       * Re-pinned 1033→1039 by the F7 auth-method-policy lane (2026-10-03):
       * +6 declared-member WRITES, all request-stamp helpers in the new/
       * extended tests — WebAuthnControllerAuthMethodGateTest's request()
       * helper (`->body` + `->userId`, 2), AdminSettingsControllerAuthGuard-
       * Test's makeRequest (`->body`, 1), and the F7 site pins' inline stamps
       * (AccountLinkControllerTest `->userId` + `->body`, 2;
       * AuthControllerLdapLoginTest `->body`, 1). All name declared members;
       * S427 license intact. Measured from the phpunit red (1039), not
       * predicted.
       * Re-pinned 1039→1040 by the v0.51.0 re-vendor lane (2026-10-05):
       * +1 declared-member WRITE — AdminSettingsRealSchemaPutTest's put()
       * helper stamps `$request->body` (the declared Request member, same
     * S101 shape as the F7 lane's helpers above). S427 license intact.
     * Measured from the phpunit red (1040), not predicted.
     * Re-pinned 1040→1042 by the B2 auth-lock lane (2026-10-07):
     * +2 declared-member WRITEs — the put() helpers of
     * AdminSettingsControllerAuthLockTest.php and
     * AdminSettingsAuthLockB2Test.php each stamp `$request->body`
     * (same S101 shape as the helpers above). S427 license intact.
     * Measured from the phpunit red (1042), not predicted.
       */
    private const EXPECTED_DECLARED_WRITES = 1042;

    /** Census numbers 3 and 4 — the posture claims; never re-pin, fix source. */
    private const EXPECTED_DYNAMIC_READS = 0;
    private const EXPECTED_DYNAMIC_WRITES = 0;

    /**
     * Files carved out of SITE scanning (never out of the estate count): the
     * S427 guard test probes the dynamic tripwire on purpose, and this test
     * spells the shapes in prose. The original census ran on a tree where
     * neither file existed; excluding them reproduces its scope, not its bugs.
     */
    private const GUARD_FIXTURE_FILES = [
        'tests/Unit/Server/Http/RequestDynamicPropertyGuardTest.php',
        'tests/Unit/Server/Http/RequestDynamicPropertyCensusExecutableTest.php',
    ];

    /** Estate path segments that are not first-party PHP. */
    private const EXCLUDED_SEGMENTS = ['vendor', 'node_modules'];

    /** Tokens that never carry meaning for any rule here. */
    private const IGNORED_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    /**
     * The whole-estate scan, computed once per PHPUnit process.
     *
     * @var array{
     *     files: int,
     *     declaredReads: int,
     *     declaredWrites: int,
     *     undeclaredReads: list<string>,
     *     undeclaredWrites: list<string>,
     *     dynamicReads: list<string>,
     *     dynamicWrites: list<string>,
     * }|null
     */
    private static ?array $census = null;

    public function testTheEstateFileCountIsPinned(): void
    {
        $this->assertSame(
            self::EXPECTED_PHP_FILES,
            self::census()['files'],
            'S431 [' . self::EXECUTABLE_CENSUS_TOKEN . ']: the estate now holds a different '
            . 'number of first-party PHP files than the '
            . 'pinned census denominator. A file joined or left the tree — update '
            . 'EXPECTED_PHP_FILES in the same commit (the enumeration must not drift silently; '
            . 'that silence is the exact defect S431 was spawned to end).',
        );
    }

    public function testTheDeclaredPropertyReadDenominatorIsPinned(): void
    {
        $this->assertSame(
            self::EXPECTED_DECLARED_READS,
            self::census()['declaredReads'],
            'S431: the count of declared-member property READS on Request roots changed. '
            . 'A Request property read was added or removed — update EXPECTED_DECLARED_READS '
            . 'in the same commit and re-check that the S427 census license (all reads on '
            . 'declared members) still holds: the undeclared-read test in this file, not '
            . 'this number, is what guards that.',
        );
    }

    public function testTheDeclaredPropertyWriteDenominatorIsPinned(): void
    {
        $this->assertSame(
            self::EXPECTED_DECLARED_WRITES,
            self::census()['declaredWrites'],
            'S431: the count of declared-member property WRITES on Request roots changed. '
            . 'Update EXPECTED_DECLARED_WRITES in the same commit (S427 licensed the guard '
            . 'against "1,037 declared write sites" — the executable number governs now).',
        );
    }

    /**
     * Posture claim, census number 3: zero dynamic-NAME reads (`->$k`,
     * `->{$expr}`) on Request roots. THIS is the test a planted S271-shaped
     * read reddens, naming the exact `file:line`.
     */
    public function testZeroDynamicPropertyReadsSurviveOnRequestRoots(): void
    {
        $found = self::census()['dynamicReads'];

        $this->assertSame(
            self::EXPECTED_DYNAMIC_READS,
            count($found),
            'S431: dynamic-NAME property reads on Request roots survived — the '
            . '`$request->$name` / `->{$expr}` shape. S271\'s `jsonBody` was invisible to PHPStan at any '
            . 'level exactly because the name is not in the source text; the S427 census '
            . 'measured zero such sites and that zero is what licenses the throwing __get. '
            . 'Remedy: read a declared member. Offending sites: ' . implode('; ', $found),
        );
    }

    /** Posture claim, census number 4: zero dynamic-name writes. */
    public function testZeroDynamicPropertyWritesSurviveOnRequestRoots(): void
    {
        $found = self::census()['dynamicWrites'];

        $this->assertSame(
            self::EXPECTED_DYNAMIC_WRITES,
            count($found),
            'S431: dynamic-NAME property WRITES on Request roots survived. Request declares '
            . 'no magic properties and no __set — such a write silently creates a dynamic slot '
            . '(PHP 8.2 deprecation territory) and reopens the exact bug class S427 closed. '
            . 'Offending sites: ' . implode('; ', $found),
        );
    }

    /**
     * The census invariant under the denominators: every NAMED property site
     * on a Request root references one of the 17 declared members. This is
     * the executable form of "331 reads — ALL on declared members" and of
     * "1,037 writes all declared": a static-name `jsonBody` anywhere —
     * guarded or not — names itself here (S271's shape in new code).
     */
    public function testEveryNamedPropertySiteOnRequestRootsIsADeclaredMember(): void
    {
        $readOffenders = self::census()['undeclaredReads'];
        $writeOffenders = self::census()['undeclaredWrites'];

        $this->assertSame(
            [],
            $readOffenders,
            'S431: property READS of undeclared names on Request roots exist. An unguarded '
            . 'one now throws LogicException at run time (the S427 tripwire); a `?? $default` '
            . 'one stays the S271 silent-null bug — either way it must be rewritten against '
            . 'a declared member (->body carries the decoded request body). Offenders: '
            . implode('; ', $readOffenders),
        );

        $this->assertSame(
            [],
            $writeOffenders,
            'S431: property WRITES to undeclared names on Request roots exist — silently '
            . 'dynamic slots, the same bug class from the other side. Offenders: '
            . implode('; ', $writeOffenders),
        );
    }

    /**
     * One tokenise-inspect-discard pass over the estate.
     *
     * @return array{
     *     files: int,
     *     declaredReads: int,
     *     declaredWrites: int,
     *     undeclaredReads: list<string>,
     *     undeclaredWrites: list<string>,
     *     dynamicReads: list<string>,
     *     dynamicWrites: list<string>,
     * }
     */
    private static function census(): array
    {
        if (self::$census !== null) {
            return self::$census;
        }

        $root = dirname(__DIR__, 4);

        $files = 0;
        $declaredReads = 0;
        $declaredWrites = 0;
        $undeclaredReads = [];
        $undeclaredWrites = [];
        $dynamicReads = [];
        $dynamicWrites = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);

            $segments = explode('/', $relative);
            if (array_intersect(self::EXCLUDED_SEGMENTS, $segments) !== []) {
                continue;
            }

            $files++;

            if (in_array($relative, self::GUARD_FIXTURE_FILES, true)) {
                continue;
            }

            /** @var list<array{0: int, 1: string, 2: int}|string> $tokens */
            $tokens = token_get_all((string) file_get_contents($path));

            $site = self::tokenizeRequestRoots($tokens, $relative);

            $declaredReads += $site['declaredReads'];
            $declaredWrites += $site['declaredWrites'];
            $undeclaredReads = array_merge($undeclaredReads, $site['undeclaredReads']);
            $undeclaredWrites = array_merge($undeclaredWrites, $site['undeclaredWrites']);
            $dynamicReads = array_merge($dynamicReads, $site['dynamicReads']);
            $dynamicWrites = array_merge($dynamicWrites, $site['dynamicWrites']);

            unset($tokens);
        }

        sort($undeclaredReads);
        sort($undeclaredWrites);
        sort($dynamicReads);
        sort($dynamicWrites);

        self::$census = [
            'files' => $files,
            'declaredReads' => $declaredReads,
            'declaredWrites' => $declaredWrites,
            'undeclaredReads' => $undeclaredReads,
            'undeclaredWrites' => $undeclaredWrites,
            'dynamicReads' => $dynamicReads,
            'dynamicWrites' => $dynamicWrites,
        ];

        return self::$census;
    }

    /**
     * Per-file: resolve aliases, infer Request roots per scope, classify sites.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{
     *     declaredReads: int,
     *     declaredWrites: int,
     *     undeclaredReads: list<string>,
     *     undeclaredWrites: list<string>,
     *     dynamicReads: list<string>,
     *     dynamicWrites: list<string>,
     * }
     */
    private static function tokenizeRequestRoots(array $tokens, string $relative): array
    {
        $count = count($tokens);
        $inRequestFile = $relative === 'src/Server/Http/Request.php';

        [$namespace, $aliasMap] = self::namespaceAndImports($tokens);

        $scopes = self::functionScopes($tokens);

        $scopeRoots = [-1 => []];

        foreach ($scopes as $si => $scope) {
            foreach (self::rootsDeclaredInParams($tokens, $scope, $aliasMap, $namespace, $inRequestFile) as $var) {
                $scopeRoots[$si][$var] = true;
            }
            foreach (
                self::rootsDeclaredInDocblock((string) $scope['doc'], $aliasMap, $namespace, $inRequestFile) as $var
            ) {
                $scopeRoots[$si][$var] = true;
            }
        }

        if ($inRequestFile) {
            foreach (array_keys($scopes) as $si) {
                $scopeRoots[$si]['$this'] = true;
            }
        }

        $namedFns = [];
        foreach ($scopes as $si => $scope) {
            $name = $scope['name'];
            if ($name !== null) {
                $namedFns[$name] ??= $si;
            }
        }

        $visible = static function (int $idx) use ($scopes, &$scopeRoots): array {
            $vars = $scopeRoots[-1] ?? [];
            foreach ($scopes as $si => $scope) {
                if ($idx > $scope['body'][0] && $idx < $scope['body'][1]) {
                    foreach ($scopeRoots[$si] ?? [] as $var => $_) {
                        $vars[$var] = true;
                    }
                }
            }

            return $vars;
        };

        $isRequestExpr = static function (
            array $tokens,
            int $v
        ) use (
            $count,
            $aliasMap,
            $namespace,
            $inRequestFile
        ): bool {
            if ($v >= $count || !is_array($tokens[$v])) {
                return false;
            }
            if ($tokens[$v][0] === T_NEW) {
                $w = self::nextSignificant($tokens, $v + 1);

                return $w !== null && self::nameTargets($tokens[$w], $aliasMap, $namespace, $inRequestFile);
            }
            if (in_array($tokens[$v][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $colon = self::nextSignificant($tokens, $v + 1);
                if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
                    return false;
                }
                $method = self::nextSignificant($tokens, $colon + 1);

                return $method !== null && is_array($tokens[$method]) && $tokens[$method][0] === T_STRING
                    && in_array($tokens[$method][1], ['fromGlobals', 'fromWorkerman'], true)
                    && self::nameTargets($tokens[$v], $aliasMap, $namespace, $inRequestFile);
            }

            return false;
        };

        // Fixed point: assignments and same-file factory call sites.
        $factories = [];
        for ($iteration = 0; $iteration < 8; $iteration++) {
            $changed = false;
            $factories = self::computeFactories($tokens, $scopes, $namedFns, $visible, $isRequestExpr);

            foreach ($scopes as $si => $scope) {
                [$bodyOpen, $bodyClose] = $scope['body'];
                for ($j = $bodyOpen; $j < $bodyClose; $j++) {
                    $t = $tokens[$j];
                    if (!is_array($t) || $t[0] !== T_VARIABLE) {
                        continue;
                    }
                    $eq = self::nextSignificant($tokens, $j + 1);
                    if ($eq === null || $tokens[$eq] !== '=') {
                        continue;
                    }
                    $v = self::nextSignificant($tokens, $eq + 1);
                    if ($v === null) {
                        continue;
                    }

                    $mark = $isRequestExpr($tokens, $v);

                    if (!$mark && is_array($tokens[$v]) && $tokens[$v][0] === T_CLONE) {
                        $w = self::nextSignificant($tokens, $v + 1);
                        $mark = $w !== null && is_array($tokens[$w]) && $tokens[$w][0] === T_VARIABLE
                            && isset($visible($bodyOpen)[$tokens[$w][1]]);
                    } elseif (!$mark && is_array($tokens[$v]) && $tokens[$v][0] === T_VARIABLE) {
                        $next = self::nextSignificant($tokens, $v + 1);
                        $chain = $next !== null && is_array($tokens[$next])
                            && in_array(
                                $tokens[$next][0],
                                [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON],
                                true
                            );
                        if (!$chain) {
                            $mark = isset($visible($bodyOpen)[$tokens[$v][1]])
                                || ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_COALESCE
                                    && self::afterCoalesced($tokens, $next, $isRequestExpr));
                        } else {
                            $call = self::callShape($tokens, $v);
                            $mark = $call !== null && isset($factories[$call]);
                        }
                    } elseif (
                        !$mark && is_array($tokens[$v])
                        && in_array($tokens[$v][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    ) {
                        $call = self::callShape($tokens, $v);
                        $mark = $call !== null && isset($factories[$call]);
                    }

                    if ($mark && !isset($scopeRoots[$si][$t[1]])) {
                        $scopeRoots[$si][$t[1]] = true;
                        $changed = true;
                    }
                }
            }

            if (!$changed && $iteration > 0) {
                break;
            }
        }

        $out = [
            'declaredReads' => 0,
            'declaredWrites' => 0,
            'undeclaredReads' => [],
            'undeclaredWrites' => [],
            'dynamicReads' => [],
            'dynamicWrites' => [],
        ];

        for ($j = 0; $j < $count; $j++) {
            $t = $tokens[$j];
            if (!is_array($t) || $t[0] !== T_VARIABLE) {
                continue;
            }
            if (!isset($visible($j)[$t[1]])) {
                continue;
            }
            $op = self::nextSignificant($tokens, $j + 1);
            if (
                $op === null || !is_array($tokens[$op])
                || !in_array($tokens[$op][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                continue;
            }
            $name = self::nextSignificant($tokens, $op + 1);
            if ($name === null) {
                continue;
            }
            $site = $relative . ':' . $t[2];

            $nm = $tokens[$name];

            if (is_array($nm) && $nm[0] === T_VARIABLE) {
                $after = self::nextSignificant($tokens, $name + 1);
                $isWrite = $after !== null && is_array($tokens[$after])
                    && in_array($tokens[$after][0], self::ASSIGN_OPERATORS, true);
                if ($isWrite) {
                    $out['dynamicWrites'][] = $site;
                } else {
                    $out['dynamicReads'][] = $site;
                }

                continue;
            }

            if ($nm === '{') {
                // `->{$expr}` — a property-read of a computed name. Find the
                // matching `}` and classify by what follows it.
                $depth = 0;
                $close = null;
                for ($k = $name; $k < $count; $k++) {
                    if (
                        $tokens[$k] === '{'
                        || (is_array($tokens[$k])
                            && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
                    ) {
                        $depth++;
                    } elseif ($tokens[$k] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $close = $k;
                            break;
                        }
                    }
                }
                $after = $close === null ? null : self::nextSignificant($tokens, $close + 1);
                $isWrite = $after !== null && (
                    $tokens[$after] === '='
                    || (is_array($tokens[$after]) && in_array($tokens[$after][0], self::ASSIGN_OPERATORS, true))
                );
                if ($isWrite) {
                    $out['dynamicWrites'][] = $site;
                } else {
                    $out['dynamicReads'][] = $site;
                }

                continue;
            }

            if (!is_array($nm) || !in_array($nm[0], [T_STRING, T_ARRAY, T_CLASS], true)) {
                continue;
            }
            $propertyName = $nm[1];
            $after = self::nextSignificant($tokens, $name + 1);
            if ($after === null) {
                continue;
            }
            if ($tokens[$after] === '(') {
                continue; // method call
            }
            $isWrite = $tokens[$after] === '='
                || (is_array($tokens[$after]) && in_array($tokens[$after][0], self::ASSIGN_OPERATORS, true));
            $isDeclared = in_array($propertyName, self::DECLARED_MEMBERS, true);

            if ($isWrite) {
                if ($isDeclared) {
                    $out['declaredWrites']++;
                } else {
                    $out['undeclaredWrites'][] = $site . ' ' . $propertyName;
                }
            } elseif ($isDeclared) {
                $out['declaredReads']++;
            } else {
                $out['undeclaredReads'][] = $site . ' ' . $propertyName;
            }
        }

        return $out;
    }

    /** Compound/plain assignment operator ids beyond the `'='` char token. */
    private const ASSIGN_OPERATORS = [
        T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_MOD_EQUAL,
        T_CONCAT_EQUAL, T_COALESCE_EQUAL, T_POW_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
        T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL,
    ];

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: string, 1: array<string,string>}
     */
    private static function namespaceAndImports(array $tokens): array
    {
        $count = count($tokens);
        $namespace = '';
        $aliasMap = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }
            if ($t[0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $q = $tokens[$j];
                    if (is_array($q) && in_array($q[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $q[1];
                        break;
                    }
                    if (!is_array($q) && ($q === ';' || $q === '{')) {
                        break;
                    }
                }
            }
            if ($t[0] !== T_USE) {
                continue;
            }
            $prev = self::prevSignificant($tokens, $i);
            if ($prev !== null && is_array($tokens[$prev]) === false && $tokens[$prev] === ')') {
                continue; // closure `use (...)`
            }
            for ($j = $i + 1; $j < $count; $j++) {
                $q = $tokens[$j];
                if (!is_array($q)) {
                    if ($q === ';') {
                        break;
                    }
                    continue;
                }
                if ($q[0] === T_FUNCTION || $q[0] === T_CONST) {
                    for (; $j < $count; $j++) {
                        $r = $tokens[$j];
                        if (!is_array($r) && $r === ';') {
                            break;
                        }
                    }
                    break;
                }
                if (in_array($q[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $fqn = ltrim($q[1], '\\');
                    $as = self::nextSignificant($tokens, $j + 1);
                    if ($as !== null && is_array($tokens[$as]) && $tokens[$as][0] === T_AS) {
                        $alias = self::nextSignificant($tokens, $as + 1);
                        if ($alias !== null && is_array($tokens[$alias])) {
                            $aliasMap[strtolower($tokens[$alias][1])] = $fqn;
                            $j = $alias;
                        }
                    } else {
                        $aliasMap[strtolower(self::shortName($fqn))] = $fqn;
                    }
                }
            }
        }

        return [$namespace, $aliasMap];
    }

    /**
     * Every T_FUNCTION/T_FN with a body: name, param range, body range, docblock.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{name: string|null, params: array{0:int,1:int}, body: array{0:int,1:int}, doc: string|null}>
     */
    private static function functionScopes(array $tokens): array
    {
        $count = count($tokens);
        $scopes = [];

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }
            $isArrow = $t[0] === T_FN;

            $name = null;
            $probe = self::nextSignificant($tokens, $i + 1);
            if ($probe !== null && is_array($tokens[$probe]) && $tokens[$probe][0] === T_STRING) {
                $name = $tokens[$probe][1];
            }

            $pOpen = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    $pOpen = $j;
                    break;
                }
            }
            if ($pOpen === null) {
                continue;
            }
            $pClose = self::matching($tokens, $pOpen, '(', ')');
            if ($pClose === null) {
                continue;
            }

            $bodyOpen = null;
            $bodyClose = null;
            for ($j = $pClose + 1; $j < $count; $j++) {
                $q = $tokens[$j];
                if ($isArrow && $q === '=>') {
                    $depth = 0;
                    for ($k = $j + 1; $k < $count; $k++) {
                        $r = $tokens[$k];
                        if ($r === '(' || $r === '[') {
                            $depth++;
                        } elseif ($r === ')' || $r === ']') {
                            if ($depth === 0) {
                                $bodyClose = $k;
                                break;
                            }
                            $depth--;
                        } elseif ($depth === 0 && $r === ';') {
                            $bodyClose = $k;
                            break;
                        }
                    }
                    $bodyOpen = $j;
                    break;
                }
                if ($q === '{') {
                    $bodyOpen = $j;
                    $bodyClose = self::matchingBrace($tokens, $j);
                    break;
                }
                if ($q === ';') {
                    break; // abstract/interface signature
                }
            }
            if ($bodyOpen === null || $bodyClose === null) {
                continue;
            }

            $doc = null;
            // $j >= max(0, $i - 60) restated conjunctively: same predicate, and Psalm
            // narrows $j to int<0, max> through the explicit >= 0 guard.
            for ($j = $i - 1; $j >= 0 && $j >= $i - 60; $j--) {
                $q = $tokens[$j];
                if (is_array($q) && $q[0] === T_DOC_COMMENT) {
                    $doc = $q[1];
                    break;
                }
                if (is_array($q) && in_array($q[0], [T_WHITESPACE, T_COMMENT], true)) {
                    continue;
                }
                $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY];
                if (is_array($q) && in_array($q[0], $modifiers, true)) {
                    continue;
                }
                break;
            }

            $scopes[] = [
                'name' => $name,
                'params' => [$pOpen, $pClose],
                'body' => [$bodyOpen, $bodyClose],
                'doc' => $doc,
            ];
        }

        return $scopes;
    }

    /**
     * Parameters whose type chunk names the target class.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array{name: string|null, params: array{0: int, 1: int},
     *              body: array{0: int, 1: int}, doc: string|null} $scope
     * @param array<string,string> $aliasMap
     * @return list<string>
     */
    private static function rootsDeclaredInParams(
        array $tokens,
        array $scope,
        array $aliasMap,
        string $namespace,
        bool $inRequestFile
    ): array {
        [$pOpen, $pClose] = $scope['params'];
        $vars = [];
        $depth = 0;
        $segmentStart = $pOpen + 1;

        for ($j = $pOpen; $j <= $pClose; $j++) {
            $q = $tokens[$j];
            if ($q === '(' || $q === '[') {
                $depth++;
                continue;
            }
            $isFinal = $q === ')' && $depth === 1 && $j === $pClose;
            if ($q === ')' || $q === ']') {
                $depth--;
            }
            if ($depth !== 1 || ($q !== ',' && !$isFinal)) {
                continue;
            }
            for ($k = $segmentStart; $k < $j; $k++) {
                $r = $tokens[$k];
                if (is_array($r) && $r[0] === T_VARIABLE) {
                    if (self::chunkTargets($tokens, $segmentStart, $k - 1, $aliasMap, $namespace, $inRequestFile)) {
                        $vars[] = $r[1];
                    }
                    break;
                }
            }
            $segmentStart = $j + 1;
        }

        return $vars;
    }

    /**
     * `@param Request $x` / `@var Request $x` docblock hints.
     *
     * @param array<string,string> $aliasMap
     * @return list<string>
     */
    private static function rootsDeclaredInDocblock(
        string $doc,
        array $aliasMap,
        string $namespace,
        bool $inRequestFile
    ): array {
        $vars = [];
        if (!preg_match_all('/@(?:param|var)\s+([\\\\\w|&\[\]]+)\s+\$(\w+)/', $doc, $matches, PREG_SET_ORDER)) {
            return $vars;
        }
        foreach ($matches as $one) {
            foreach (preg_split('/[|&]/', $one[1]) ?: [] as $type) {
                $type = rtrim($type, '[]');
                $lower = strtolower(ltrim($type, '\\'));
                $hit = false;
                if ($lower === 'self' || $lower === 'static') {
                    $hit = $inRequestFile;
                } elseif (str_starts_with($lower, 'phlix\\server\\http\\')) {
                    $hit = $lower === strtolower(self::TARGET_CLASS);
                } else {
                    $short = strtolower(self::shortName($type));
                    if (isset($aliasMap[$short]) || $short === 'request') {
                        $resolved = $aliasMap[$short] ?? ($namespace . '\\' . $type);
                        $hit = strcasecmp($resolved, self::TARGET_CLASS) === 0;
                    }
                }
                if ($hit) {
                    $vars[] = '$' . $one[2];
                    break;
                }
            }
        }

        return $vars;
    }

    /**
     * Named functions in this file whose own body (excluding nested function
     * bodies) returns a Request expression or a visible root variable.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<array{name: string|null, params: array{0: int, 1: int},
     *                   body: array{0: int, 1: int}, doc: string|null}> $scopes
     * @param array<string,int> $namedFns
     * @param callable(int): array<string,bool> $visible
     * @param callable(list<array{0: int, 1: string, 2: int}|string>, int): bool $isRequestExpr
     * @return array<string,true>
     */
    private static function computeFactories(
        array $tokens,
        array $scopes,
        array $namedFns,
        callable $visible,
        callable $isRequestExpr
    ): array {
        $count = count($tokens);
        $factories = [];

        foreach ($namedFns as $name => $si) {
            [$bodyOpen, $bodyClose] = $scopes[$si]['body'];
            $nested = [];
            foreach ($scopes as $sj => $other) {
                if ($sj !== $si && $other['body'][0] > $bodyOpen && $other['body'][1] < $bodyClose) {
                    $nested[] = $other['body'];
                }
            }
            for ($j = $bodyOpen + 1; $j < $bodyClose; $j++) {
                $t = $tokens[$j];
                if (!is_array($t) || $t[0] !== T_RETURN) {
                    continue;
                }
                $insideNested = false;
                foreach ($nested as [$n0, $n1]) {
                    if ($j > $n0 && $j < $n1) {
                        $insideNested = true;
                        break;
                    }
                }
                if ($insideNested) {
                    continue;
                }
                $v = self::nextSignificant($tokens, $j + 1);
                if ($v === null) {
                    break;
                }
                if ($isRequestExpr($tokens, $v)) {
                    $factories[$name] = true;
                } elseif (
                    is_array($tokens[$v])
                    && $tokens[$v][0] === T_VARIABLE
                    && isset($visible($j)[$tokens[$v][1]])
                ) {
                    $factories[$name] = true;
                }
                break; // first return decides
            }
        }

        return $factories;
    }

    /**
     * Does the type chunk between two token indices name the target class?
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string,string> $aliasMap
     */
    private static function chunkTargets(
        array $tokens,
        int $from,
        int $to,
        array $aliasMap,
        string $namespace,
        bool $inRequestFile
    ): bool {
        for ($i = $from; $i <= $to; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (self::nameTargets($t, $aliasMap, $namespace, $inRequestFile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does a class-name token (after alias/namespace resolution) denote Request?
     *
     * @param array{0: int, 1: string, 2: int} $token
     * @param array<string,string> $aliasMap
     */
    private static function nameTargets(array $token, array $aliasMap, string $namespace, bool $inRequestFile): bool
    {
        if ($token[0] === T_STATIC) {
            return $inRequestFile;
        }
        if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }
        $word = $token[1];
        if ($inRequestFile && $token[0] === T_STRING && in_array(strtolower($word), ['self', 'static'], true)) {
            return true;
        }
        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return strcasecmp(ltrim($word, '\\'), self::TARGET_CLASS) === 0;
        }
        if (str_contains($word, '\\')) {
            $first = substr($word, 0, strpos($word, '\\') ?: 0);
            $rest = substr($word, strlen($first) + 1);
            $resolved = $aliasMap[strtolower($first)] ?? ($namespace . '\\' . $first);

            return strcasecmp($resolved . '\\' . $rest, self::TARGET_CLASS) === 0;
        }
        $lower = strtolower($word);
        if (isset($aliasMap[$lower])) {
            return strcasecmp($aliasMap[$lower], self::TARGET_CLASS) === 0;
        }

        return $lower === 'request' && strcasecmp($namespace . '\\Request', self::TARGET_CLASS) === 0;
    }

    /**
     * `$x = $anything ?? <request-expr>` — the coalesce fallback still yields a Request.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param callable(list<array{0: int, 1: string, 2: int}|string>, int): bool $isRequestExpr
     */
    private static function afterCoalesced(array $tokens, int $coalesceIndex, callable $isRequestExpr): bool
    {
        $q = self::nextSignificant($tokens, $coalesceIndex + 1);

        return $q !== null && $isRequestExpr($tokens, $q);
    }

    /**
     * When the token at $from begins a `->method(`/`::method(` call shape, its
     * method name; else null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function callShape(array $tokens, int $from): ?string
    {
        $count = count($tokens);
        $w = self::nextSignificant($tokens, $from + 1);
        if (
            $w === null || !is_array($tokens[$w])
            || !in_array($tokens[$w][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
        ) {
            return null;
        }
        $w = self::nextSignificant($tokens, $w + 1);
        if ($w === null || !is_array($tokens[$w]) || $tokens[$w][0] !== T_STRING) {
            return null;
        }
        $name = $tokens[$w][1];
        $p = self::nextSignificant($tokens, $w + 1);

        return $p !== null && $tokens[$p] === '(' ? $name : null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], self::IGNORED_TOKENS, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], self::IGNORED_TOKENS, true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matching(array $tokens, int $open, string $openChar, string $closeChar): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === $openChar) {
                $depth++;
            } elseif ($tokens[$i] === $closeChar) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function matchingBrace(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if (
                $tokens[$i] === '{'
                || (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $depth++;
            } elseif ($tokens[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function shortName(string $name): string
    {
        $pos = strrpos($name, '\\');

        return $pos === false ? $name : substr($name, $pos + 1);
    }
}
