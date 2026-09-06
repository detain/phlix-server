<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Server\Core;

use DI\ContainerBuilder;
use Phlix\Common\Container\ContainerFactory;
use Phlix\Common\Container\ServiceProviderInterface;
use Phlix\Common\Database\ConnectionPool;
use Phlix\Common\Logger\LoggerFactory;
use Phlix\Server\Core\Application;
use Phlix\Server\Http\Request;
use Phlix\Server\Http\RequestContext;
use Phlix\Server\Http\Response;
use Phlix\Server\Http\Router;
use Phlix\Server\WebPortal\WebPortalRouter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Workerman\MySQL\Connection;

use function DI\factory;

/**
 * S239 — WIRE-PATH guard for the router {@see Application} composes.
 *
 * S236 closed this exposure for {@see WebPortalRouter}'s 47 routes. `Application`
 * carries **353** routes across ~30 loaders (345 when S239 measured it; S77 added the
 * eight `/api/v1/admin/maintenance/*` endpoints) and had the same hole: a route the
 * clients depend on could be renamed or deleted with every server gate green.
 *
 * ## The measured holes this file closes
 *
 * Three separate steps recorded the same defect and none of them closed it:
 *
 * - **S31 — the Most Watched rail.** Deleting the registration at
 *   `Application::loadApiRoutes()` (`$r->get('/api/v1/media/most-watched', …)`)
 *   left the Unit suite green, because
 *   `MostWatchedControllerTest::testAuthMiddlewareGuardsTheRail()` builds **its
 *   own** `Router` and registers the path onto it. A test that declares the route
 *   it is testing cannot observe that route being deleted from production.
 * - **S59 — the two DASH registrations.** `GET /dash/{job_id}/manifest` and
 *   `GET /dash/{job_id}/{file}` were deleted and nothing went red;
 *   `tests/Integration` and `tests/E2E` contain zero hits for `/dash/` too.
 * - **S30 — the playback finish signal.** `POST /api/v1/sessions/{id}/complete`
 *   deleted ⇒ zero extra red, and zero Integration/E2E coverage.
 *
 * ## Why the assertions are shaped the way they are
 *
 * - **No substring matching anywhere.** `'…/most-watched-MUTATED'` *contains*
 *   `'…/most-watched'`, so an `includes` / `str_contains` /
 *   `assertStringContainsString` assertion passes the exact mutation it exists to
 *   catch (S37 in phlix-ui; re-recorded by S236). Every path assertion here is an
 *   exact array-key lookup or a strict whole-list comparison.
 * - **The route table is the PRODUCTION one.** It is reflected off an
 *   `Application` built from `ContainerFactory::defaultProviders()` — the same
 *   provider stack `public/index.php` and the Workerman daemon use — with only the
 *   MySQL {@see Connection} doubled. It is never a table this file registers.
 *   ⚠ A hand-rolled container yields **53** routes where the real one yields
 *   **345** (measured by S164); {@see self::MIN_EXPECTED_ROUTES} is the floor that
 *   makes that mistake loud instead of silent.
 * - **Dispatch controls are readable.** Where this file dispatches, a refusal sits
 *   next to a DIFFERENT refusal and next to a success, so "it answered 401" cannot
 *   be confused with "everything answers 401": `/health` → **200**,
 *   `/api/v1/media/most-watched` → **401** (auth gate), `/dash/{job}/manifest` →
 *   **401** (signed-URL gate), `POST /api/v1/sessions/{id}/complete` → **404 with
 *   the HANDLER's own body**, and an unrouted sibling → **404 with the ROUTER's**.
 *
 * ## Coverage statement — read this before trusting the file's name
 *
 * This file covers `Application`'s router at two different strengths, and the
 * difference matters:
 *
 * - **All 353 rails, REGISTRATION-only.**
 *   {@see self::testTheRegisteredWirePathsMatchTheManifestExactly()} pins verb +
 *   exact path literal + handler class::method + middleware stack for every route,
 *   as a whole-list strict comparison. Renaming, deleting, re-verbing, re-handling
 *   or un-gating ANY of the 353 reds it.
 * - **Five rails are DISPATCH-covered:** `GET /health`,
 *   `GET /api/v1/media/most-watched`, `GET /dash/{job_id}/manifest`,
 *   `GET /dash/{job_id}/{file}` and `POST /api/v1/sessions/{id}/complete`. Of
 *   those, only `GET /health` has a SUCCESS envelope asserted; the other four are
 *   driven as far as their gate or their handler's own miss (401 / handler-owned
 *   404), which is the furthest a unit test can honestly take them without a real
 *   database.
 * - **NOT dispatch-covered — the other 340 rails.** Every `/api/v1/admin/*` route
 *   (150 of them), Live TV, DLNA/CDS, casting (Chromecast/AirPlay/Roku), SyncPlay,
 *   music/books/audiobooks/photos, collections, trickplay, HLS, backup, webhooks,
 *   ARR sync, Trakt, Last.fm, plugin admin and the auth-provider callbacks. Their
 *   response envelopes, status codes and handler behaviour are **NOT** asserted
 *   here. Do not read this file as an end-to-end guard for the whole router.
 * - ⚠ **The middleware column records ROUTE-level middleware ONLY — an empty `[]`
 *   is NOT evidence that a route is ungated.** A controller may apply its own gate
 *   as the first statement of the handler, and this file cannot see that by
 *   construction. S272 was filed as a security defect on exactly this misreading:
 *   the six destructive `POST /api/v1/libraries/{id}/{scan,rescan,delete-all,prune,
 *   clear-metadata,clear-artwork}` rails show `[]` here, yet all six call
 *   `LibraryController::requireAdmin()` before doing anything, and were confirmed
 *   at runtime against a booted server to answer anonymous → **401**, authenticated
 *   NON-ADMIN → **403** `auth.not_admin`, admin → **202**. The manifest entries
 *   below are correct and must stay `[]`; the gate is pinned instead by
 *   {@see \Phlix\Tests\Unit\Server\Http\Controllers\LibraryDestructiveRoutesAdminGateTest}.
 *   Before reading any `[]` as "ungated", grep the handler for its own guard.
 *   S284 added a SEVENTH rail of the same shape —
 *   `POST /api/v1/libraries/{id}/regenerate-assets` — which likewise shows `[]`
 *   here and gates in `LibraryController::regenerateAssets()`; its 401/403/202
 *   triple is pinned by
 *   {@see \Phlix\Tests\Unit\Server\Http\Controllers\LibraryRegenerateAssetsAdminGateTest}.
 * - **Not on this router at all:** the pre-router fast paths
 *   (`/media/{id}/stream`, `/api/v1/artwork/{id}`, `/api/v1/users/{id}/avatar`)
 *   appear in NO route table by construction — S164 and S238 own those. And the
 *   Next-Up rail (S36) lives on {@see WebPortalRouter}, not here; this file pins it
 *   only at the point S236 does not reach — the instance the **production
 *   container** composes ({@see self::testTheNextUpRailIsRegisteredOnTheWebPortalRouterTheContainerComposes()}).
 *
 * @see \Phlix\Tests\Unit\Server\WebPortal\WebPortalRouterWirePathGuardTest S236, the
 *      precedent: the same guard for WebPortalRouter's 47 routes.
 * @see \Phlix\Tests\Unit\Hub\RelayImageDispatchTest S238, which measured the 345/53
 *      split this file's floor is derived from.
 */
final class ApplicationRouterWirePathGuardTest extends TestCase
{
    /**
     * Lower bound on the size of a sane composed route table.
     *
     * Its only job is ANTI-VACUITY. If a loader is emptied, a `catch (\Throwable)`
     * in one of the ~30 route loaders starts swallowing a construction failure, or
     * this file stops reading the table `Application::dispatch()` serves, then
     * every assertion below would otherwise pass — or fail for a reason that reads
     * like an unrelated bug. 300 sits below the measured 345 and far above the 53
     * a HAND-ROLLED container yields (S164), so it separates "the registrar was
     * hollowed" from "the harness built the wrong container".
     */
    private const MIN_EXPECTED_ROUTES = 300;

    /** S31's rail. Spelled once; compared only by exact equality, never containment. */
    private const MOST_WATCHED_PATH = '/api/v1/media/most-watched';

    /**
     * The S31 mutation, kept as a literal so the "a renamed path is not served"
     * test cannot drift away from the mutation it models. It is a strict
     * SUPERSTRING of the real path — which is exactly why a `str_contains`
     * assertion cannot tell the two apart.
     */
    private const MOST_WATCHED_MUTATED_PATH = '/api/v1/media/most-watched-MUTATED';

    /** S59's two rails. */
    private const DASH_MANIFEST_PATH = '/dash/{job_id}/manifest';
    private const DASH_FILE_PATH = '/dash/{job_id}/{file}';

    /** S30's playback finish signal. */
    private const SESSION_COMPLETE_PATH = '/api/v1/sessions/{id}/complete';

    /** S36's rail — on WebPortalRouter, NOT on this router. See the coverage statement. */
    private const NEXT_UP_PATH = '/api/v1/users/me/next-up';

    /**
     * The complete registered surface of the router {@see Application} composes,
     * as `"<VERB> <path literal> -> <Handler>::<method> [<Middleware>,…]"`.
     *
     * ## How to change this list
     *
     * Adding, renaming or removing a route in any of `Application`'s loaders MUST
     * be accompanied by the matching edit here, in the same commit. That is the
     * whole point: these paths are the server's half of the wire contract phlix-ui
     * and the six native clients are written against, and an unreviewed edit to
     * this list is exactly the silent rename S239 was filed for.
     *
     * ## How it is rendered
     *
     * - Class names are SHORT names. Verified unique: the 353 routes resolve to 68
     *   distinct handler classes and 6 distinct middleware classes with no
     *   short-name collision, so shortening loses no identity.
     * - Ten handlers are closures registered inline in `Application.php` and render
     *   as `Closure@Application.php`. The defining FILE is pinned; the line is
     *   deliberately NOT, because a manifest that churns on every unrelated edit
     *   above them gets deleted rather than maintained. Verb, path and middleware
     *   are pinned for those ten exactly as for the rest.
     * - Both handler-binding spellings live in `Application` and render alike:
     *   `[$controller, 'method']` (an already-constructed controller, what most
     *   loaders use) and `[Controller::class, 'method']` (a class-string the
     *   container resolves at dispatch, what `Router`'s typed helpers and
     *   `AdminRoutes` use).
     * - `{param}` placeholders are the literal registered spelling. `Router` keys
     *   parametric routes by their COMPILED REGEX, so this list is rendered from
     *   each entry's own `path` field — the literal, not the pattern.
     *
     * @var list<string>
     */
    private const ROUTE_MANIFEST = [
        'DELETE /api/v1/admin/backup/{id} -> BackupController::delete [AdminMiddleware]',
        'DELETE /api/v1/admin/livetv/recordings/{id} -> AdminLiveTvController::deleteRecording [AdminMiddleware]',
        'DELETE /api/v1/admin/livetv/series-rules/{id} -> AdminLiveTvController::deleteSeriesRule [AdminMiddleware]',
        'DELETE /api/v1/admin/livetv/tuners/{id} -> AdminLiveTvController::deleteTuner [AdminMiddleware]',
        'DELETE /api/v1/admin/plugins/catalog/sources -> PluginCatalogController::removeSource [AdminMiddleware]',
        'DELETE /api/v1/admin/plugins/{name} -> PluginAdminController::uninstall [AdminMiddleware]',
        'DELETE /api/v1/admin/profiles/{id} -> AdminProfileController::delete [AdminMiddleware]',
        'DELETE /api/v1/admin/profiles/{id}/pin -> AdminProfileController::deletePin [AdminMiddleware]',
        'DELETE /api/v1/admin/profiles/{profileId}/schedules/{scheduleId}'
            . ' -> AccessScheduleController::deleteSchedule [AdminMiddleware]',
        'DELETE /api/v1/admin/profiles/{profileId}/tags/{tagId} -> ProfileTagController::deleteTag [AdminMiddleware]',
        'DELETE /api/v1/admin/users/{id} -> AdminUserController::delete [AdminMiddleware]',
        'DELETE /api/v1/admin/webhooks/subscriptions/{id}'
            . ' -> AdminWebhooksController::deleteSubscription [AdminMiddleware]',
        'DELETE /api/v1/admin/webhooks/{id} -> WebhookAdminController::delete []',
        'DELETE /api/v1/collections/{id} -> CollectionController::delete [AuthMiddleware]',
        'DELETE /api/v1/collections/{id}/items/{mediaItemId} -> CollectionController::removeItem [AuthMiddleware]',
        'DELETE /api/v1/libraries/{id} -> LibraryController::delete []',
        'DELETE /api/v1/libraries/{id}/theme-media -> ThemeMediaController::deleteThemeMedia []',
        'DELETE /api/v1/me/webauthn/credentials/{id} -> WebAuthnController::deleteCredential []',
        'DELETE /api/v1/media/{id} -> MediaItemController::delete [AdminMiddleware]',
        'DELETE /api/v1/media/{id}/markers/{markerId} -> MediaMarkerController::deleteMarker [AuthMiddleware]',
        'DELETE /api/v1/profiles/{profileId} -> ProfilesController::delete [AuthMiddleware]',
        'DELETE /api/v1/profiles/{profileId}/pin -> ProfilesController::removePin [AuthMiddleware]',
        'DELETE /api/v1/profiles/{profileId}/schedules/{scheduleId}'
            . ' -> AccessScheduleController::deleteSchedule [AuthMiddleware]',
        'DELETE /api/v1/profiles/{profileId}/tags/{tagId} -> ProfileTagController::deleteTag [AuthMiddleware]',
        'DELETE /api/v1/sessions/{id} -> SessionController::endSession []',
        'DELETE /auth/identities/{id} -> AccountLinkController::unlink [AuthMiddleware]',
        'GET /.well-known/jwks.json -> Closure@Application.php []',
        'GET /admin/health/jobs -> Closure@Application.php []',
        'GET /api/v1 -> Closure@Application.php []',
        'GET /api/v1/admin/auth-providers -> AuthProviderController::listProviders [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/github/config -> GithubAdminController::getSettings [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/github/schema -> GithubAdminController::getSchema [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/ldap/config -> LdapAdminController::getSettings [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/ldap/schema -> LdapAdminController::getSchema [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/oidc/config -> OidcAdminController::getSettings [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/oidc/schema -> OidcAdminController::getSchema [AdminMiddleware]',
        'GET /api/v1/admin/auth-providers/{name}/config-schema'
            . ' -> AuthProviderController::getConfigSchema [AdminMiddleware]',
        'GET /api/v1/admin/backup/list -> BackupController::list [AdminMiddleware]',
        'GET /api/v1/admin/backup/schedule -> BackupController::getSchedule [AdminMiddleware]',
        'GET /api/v1/admin/dashboard/activity -> DashboardController::activity [AdminMiddleware]',
        'GET /api/v1/admin/dashboard/now-playing -> DashboardController::nowPlaying [AdminMiddleware]',
        'GET /api/v1/admin/dashboard/storage -> DashboardController::storage [AdminMiddleware]',
        'GET /api/v1/admin/dashboard/top-media -> DashboardController::topMedia [AdminMiddleware]',
        'GET /api/v1/admin/dashboard/top-users -> DashboardController::topUsers [AdminMiddleware]',
        'GET /api/v1/admin/dlna/status -> AdminDlnaServerController::status [AdminMiddleware]',
        'GET /api/v1/admin/fs/browse -> FsBrowseController::browse [AdminMiddleware]',
        'GET /api/v1/admin/libraries/{id}/duplicates -> AdminMergeController::duplicates [AdminMiddleware]',
        'GET /api/v1/admin/livetv/channels -> AdminLiveTvController::listChannels [AdminMiddleware]',
        'GET /api/v1/admin/livetv/channels/{id} -> AdminLiveTvController::getChannel [AdminMiddleware]',
        'GET /api/v1/admin/livetv/channels/{id}/stream -> AdminLiveTvController::streamChannel [AdminMiddleware]',
        'GET /api/v1/admin/livetv/guide -> AdminLiveTvController::listGuide [AdminMiddleware]',
        'GET /api/v1/admin/livetv/guide/programs/{id} -> AdminLiveTvController::getProgram [AdminMiddleware]',
        'GET /api/v1/admin/livetv/recordings -> AdminLiveTvController::listRecordings [AdminMiddleware]',
        'GET /api/v1/admin/livetv/recordings/series/{seriesId}'
            . ' -> AdminLiveTvController::listBySeries [AdminMiddleware]',
        'GET /api/v1/admin/livetv/recordings/upcoming'
            . ' -> AdminLiveTvController::listUpcomingRecordings [AdminMiddleware]',
        'GET /api/v1/admin/livetv/recordings/{id} -> AdminLiveTvController::getRecording [AdminMiddleware]',
        'GET /api/v1/admin/livetv/series-rules -> AdminLiveTvController::listSeriesRules [AdminMiddleware]',
        'GET /api/v1/admin/livetv/series-rules/{id} -> AdminLiveTvController::getSeriesRule [AdminMiddleware]',
        'GET /api/v1/admin/livetv/tuners -> AdminLiveTvController::listTuners [AdminMiddleware]',
        'GET /api/v1/admin/livetv/tuners/scan -> AdminLiveTvController::scanTuners [AdminMiddleware]',
        'GET /api/v1/admin/livetv/tuners/{id} -> AdminLiveTvController::getTuner [AdminMiddleware]',
        'GET /api/v1/admin/logs -> LogController::index [AdminMiddleware]',
        'GET /api/v1/admin/logs/tail -> LogController::tail [AdminMiddleware]',
        'GET /api/v1/admin/logs/tail-all -> LogController::tailAll [AdminMiddleware]',
        'GET /api/v1/admin/maintenance/jobs -> MaintenanceController::jobs [AdminMiddleware]',
        'GET /api/v1/admin/maintenance/jobs/{id} -> MaintenanceController::job [AdminMiddleware]',
        'GET /api/v1/admin/maintenance/tasks -> MaintenanceController::tasks [AdminMiddleware]',
        'GET /api/v1/admin/metadata/sources -> AdminMetadataSourceController::index [AdminMiddleware]',
        'GET /api/v1/admin/metrics/connections -> MetricsController::connections [AdminMiddleware]',
        'GET /api/v1/admin/metrics/history -> MetricsController::history [AdminMiddleware]',
        'GET /api/v1/admin/metrics/routes -> MetricsController::routes [AdminMiddleware]',
        'GET /api/v1/admin/metrics/snapshot -> MetricsController::snapshot [AdminMiddleware]',
        'GET /api/v1/admin/plugins -> PluginAdminController::index [AdminMiddleware]',
        'GET /api/v1/admin/plugins/auto-update -> PluginCatalogController::autoUpdate [AdminMiddleware]',
        'GET /api/v1/admin/plugins/catalog -> PluginCatalogController::index [AdminMiddleware]',
        'GET /api/v1/admin/plugins/catalog/channel -> PluginCatalogController::channel [AdminMiddleware]',
        'GET /api/v1/admin/plugins/updates -> PluginCatalogController::updates [AdminMiddleware]',
        'GET /api/v1/admin/plugins/{name} -> PluginAdminController::show [AdminMiddleware]',
        'GET /api/v1/admin/profiles/{id} -> AdminProfileController::get [AdminMiddleware]',
        'GET /api/v1/admin/profiles/{profileId}/active-streams'
            . ' -> StreamLimitController::getActiveStreams [AdminMiddleware]',
        'GET /api/v1/admin/profiles/{profileId}/schedules'
            . ' -> AccessScheduleController::listForProfile [AdminMiddleware]',
        'GET /api/v1/admin/profiles/{profileId}/schedules/{scheduleId}'
            . ' -> AccessScheduleController::getSchedule [AdminMiddleware]',
        'GET /api/v1/admin/profiles/{profileId}/stream-limits'
            . ' -> StreamLimitController::getStreamLimits [AdminMiddleware]',
        'GET /api/v1/admin/profiles/{profileId}/tags -> ProfileTagController::listForProfile [AdminMiddleware]',
        'GET /api/v1/admin/remote/hub/status -> AdminHubController::hubStatus [AdminMiddleware]',
        'GET /api/v1/admin/remote/portforward/candidates'
            . ' -> AdminHubController::portForwardCandidates [AdminMiddleware]',
        'GET /api/v1/admin/remote/portforward/status -> AdminHubController::portForwardStatus [AdminMiddleware]',
        'GET /api/v1/admin/remote/relay/status -> AdminHubController::relayStatus [AdminMiddleware]',
        'GET /api/v1/admin/remote/subdomain/status -> AdminHubController::subdomainStatus [AdminMiddleware]',
        'GET /api/v1/admin/services/lastfm/status -> LastfmController::status [AdminMiddleware]',
        'GET /api/v1/admin/services/trakt/status -> TraktOAuthController::status [AdminMiddleware]',
        'GET /api/v1/admin/settings -> AdminSettingsController::index [AdminMiddleware]',
        'GET /api/v1/admin/stats/playback -> StatsController::playback [AdminMiddleware]',
        'GET /api/v1/admin/stats/storage -> StatsController::storage [AdminMiddleware]',
        'GET /api/v1/admin/stats/top-media -> StatsController::topMedia [AdminMiddleware]',
        'GET /api/v1/admin/stats/top-users -> StatsController::topUsers [AdminMiddleware]',
        'GET /api/v1/admin/sync/status -> SyncController::getSyncStatus []',
        'GET /api/v1/admin/transcoding/accelerators -> AdminTranscodingController::accelerators [AdminMiddleware]',
        'GET /api/v1/admin/transcoding/tone-mapping -> AdminTranscodingController::toneMapping [AdminMiddleware]',
        'GET /api/v1/admin/updates/status -> AdminUpdatesController::status [AdminMiddleware]',
        'GET /api/v1/admin/users -> AdminUserController::list [AdminMiddleware]',
        'GET /api/v1/admin/users/{id} -> AdminUserController::get [AdminMiddleware]',
        'GET /api/v1/admin/users/{userId}/profiles -> AdminProfileController::listForUser [AdminMiddleware]',
        'GET /api/v1/admin/watch-history -> WatchHistoryController::index [AdminMiddleware]',
        'GET /api/v1/admin/webhooks -> WebhookAdminController::index []',
        'GET /api/v1/admin/webhooks/deliveries -> AdminWebhooksController::getDeliveries [AdminMiddleware]',
        'GET /api/v1/admin/webhooks/subscriptions -> AdminWebhooksController::listSubscriptions [AdminMiddleware]',
        'GET /api/v1/airplay/devices -> AirPlayController::listDevices [AuthMiddleware,CastingEnabledMiddleware]',
        'GET /api/v1/airplay/devices/{id}/status'
            . ' -> AirPlayController::getStatus [AuthMiddleware,CastingEnabledMiddleware]',
        'GET /api/v1/audiobooks -> AudiobookController::listAudiobooks [AuthMiddleware]',
        'GET /api/v1/audiobooks/{id} -> AudiobookController::getAudiobook [AuthMiddleware]',
        'GET /api/v1/audiobooks/{id}/chapters -> AudiobookController::getChapters [AuthMiddleware]',
        'GET /api/v1/audiobooks/{id}/progress -> AudiobookController::getProgress [AuthMiddleware]',
        'GET /api/v1/audiobooks/{id}/read -> AudiobookController::readAudiobook [SignedUrlMiddleware]',
        'GET /api/v1/audiobooks/{id}/stream -> AudiobookController::streamAudiobook [SignedUrlMiddleware]',
        'GET /api/v1/auth/me -> AuthController::me []',
        'GET /api/v1/books -> BookController::listBooks [AuthMiddleware]',
        'GET /api/v1/books/{id} -> BookController::getBook [AuthMiddleware]',
        'GET /api/v1/books/{id}/cover -> BookController::getCover [SignedUrlMiddleware]',
        'GET /api/v1/books/{id}/download -> BookController::downloadBook [SignedUrlMiddleware]',
        'GET /api/v1/books/{id}/read -> BookController::readBook [SignedUrlMiddleware]',
        'GET /api/v1/cast/devices -> ChromecastController::listDevices [AuthMiddleware,CastingEnabledMiddleware]',
        'GET /api/v1/cast/devices/{id}/status'
            . ' -> ChromecastController::getStatus [AuthMiddleware,CastingEnabledMiddleware]',
        'GET /api/v1/collections -> CollectionController::index [AuthMiddleware]',
        'GET /api/v1/collections/{id} -> CollectionController::show [AuthMiddleware]',
        'GET /api/v1/dlna/renderers -> RendererListController::listRenderers [AuthMiddleware]',
        'GET /api/v1/dlna/renderers/{id}/status -> RendererListController::getStatus [AuthMiddleware]',
        // S437: both health routes moved from ungated `[]` to `[AuthMiddleware]`.
        // METHOD + PATH tuples are byte-for-byte unchanged (nested `''`-prefix group,
        // full path); only the ROUTE-level middleware column tracks the new posture.
        'GET /api/v1/health/network -> Closure@Application.php [AuthMiddleware]',
        'GET /api/v1/health/relay -> Closure@Application.php [AuthMiddleware]',
        'GET /api/v1/libraries -> LibraryController::index []',
        'GET /api/v1/libraries/{id} -> LibraryController::show []',
        'GET /api/v1/libraries/{id}/scan-history -> LibraryController::scanHistory []',
        'GET /api/v1/libraries/{id}/scan-status -> LibraryController::scanStatus []',
        'GET /api/v1/libraries/{id}/theme-media -> ThemeMediaController::getThemeMedia []',
        'GET /api/v1/libraries/{libraryId}/collections -> CollectionController::forLibrary [AuthMiddleware]',
        'GET /api/v1/me/continue-watching -> SessionController::getContinueWatching []',
        'GET /api/v1/me/sessions -> SessionController::listSessions []',
        'GET /api/v1/me/webauthn/credentials -> WebAuthnController::listCredentials []',
        'GET /api/v1/media/index -> Closure@Application.php [AuthMiddleware]',
        'GET /api/v1/media/most-watched -> MostWatchedController::mostWatched [AuthMiddleware]',
        'GET /api/v1/media/{id}/chapters/{index}/thumbnail -> MediaItemController::getChapterThumbnail []',
        'GET /api/v1/media/{id}/download -> MediaItemController::getDownload [AuthMiddleware]',
        'GET /api/v1/media/{id}/extras -> ExtrasController::getExtras [AuthMiddleware]',
        'GET /api/v1/media/{id}/extras/other -> ExtrasController::getOtherExtras [AuthMiddleware]',
        'GET /api/v1/media/{id}/markers -> MarkerController::getMarkers [AuthMiddleware]',
        'GET /api/v1/media/{id}/markers/intro -> MarkerController::getIntroMarker [AuthMiddleware]',
        'GET /api/v1/media/{id}/markers/outro -> MarkerController::getOutroMarker [AuthMiddleware]',
        'GET /api/v1/media/{id}/match/search -> MediaMatchController::search []',
        'GET /api/v1/media/{id}/missing-episodes -> MediaItemController::getMissingEpisodes [AuthMiddleware]',
        'GET /api/v1/media/{id}/playback-info -> MediaItemController::getPlaybackInfo [AuthMiddleware]',
        'GET /api/v1/media/{id}/posters -> MediaPosterController::listPosters []',
        'GET /api/v1/media/{id}/subtitles -> SubtitleController::listTracks [AuthMiddleware]',
        'GET /api/v1/media/{id}/subtitles/external/{streamId}'
            . ' -> RemoteSubtitleController::serveExternal [SignedUrlMiddleware]',
        'GET /api/v1/media/{id}/subtitles/search -> RemoteSubtitleController::search [AuthMiddleware]',
        'GET /api/v1/media/{id}/subtitles/{index} -> SubtitleController::getTrack [SignedUrlMiddleware]',
        'GET /api/v1/media/{id}/trailers -> ExtrasController::getTrailers [AuthMiddleware]',
        'GET /api/v1/media/{id}/trickplay -> MediaItemController::getTrickplay []',
        'GET /api/v1/music/albums -> MusicController::listAlbums [AuthMiddleware]',
        'GET /api/v1/music/albums/{mbid} -> MusicController::getAlbum [AuthMiddleware]',
        'GET /api/v1/music/artists -> MusicController::listArtists [AuthMiddleware]',
        'GET /api/v1/music/artists/{mbid} -> MusicController::getArtist [AuthMiddleware]',
        'GET /api/v1/music/now-playing -> MusicController::nowPlaying [AuthMiddleware]',
        'GET /api/v1/music/tracks -> MusicController::listTracks [AuthMiddleware]',
        'GET /api/v1/music/tracks/{id} -> MusicController::getTrack [AuthMiddleware]',
        'GET /api/v1/oauth/lastfm -> LastfmController::apiAuthorize [AdminMiddleware]',
        'GET /api/v1/oauth/lastfm/callback -> LastfmController::apiCallback [AdminMiddleware]',
        'GET /api/v1/oauth/trakt -> TraktOAuthController::authorize []',
        'GET /api/v1/oauth/trakt/callback -> TraktOAuthController::callback []',
        'GET /api/v1/photo/albums -> PhotoController::listAlbums [AuthMiddleware]',
        'GET /api/v1/photo/albums/{id} -> PhotoController::getAlbum [AuthMiddleware]',
        'GET /api/v1/photo/photos -> PhotoController::listPhotos [AuthMiddleware]',
        'GET /api/v1/photo/photos/{id} -> PhotoController::getPhoto [AuthMiddleware]',
        'GET /api/v1/photo/photos/{id}/full -> PhotoController::getFull [SignedUrlMiddleware]',
        'GET /api/v1/photo/photos/{id}/thumbnail -> PhotoController::getThumbnail [SignedUrlMiddleware]',
        'GET /api/v1/photo/slideshow -> PhotoController::slideshow [AuthMiddleware]',
        'GET /api/v1/profiles -> ProfilesController::list [AuthMiddleware]',
        'GET /api/v1/profiles/{profileId} -> ProfilesController::get [AuthMiddleware]',
        'GET /api/v1/profiles/{profileId}/active-streams -> StreamLimitController::getActiveStreams [AuthMiddleware]',
        'GET /api/v1/profiles/{profileId}/schedules -> AccessScheduleController::listForProfile [AuthMiddleware]',
        'GET /api/v1/profiles/{profileId}/schedules/{scheduleId}'
            . ' -> AccessScheduleController::getSchedule [AuthMiddleware]',
        'GET /api/v1/profiles/{profileId}/stream-limits -> StreamLimitController::getStreamLimits [AuthMiddleware]',
        'GET /api/v1/profiles/{profileId}/tags -> ProfileTagController::listForProfile [AuthMiddleware]',
        'GET /api/v1/roku/devices -> RokuController::listDevices [AuthMiddleware,CastingEnabledMiddleware]',
        'GET /api/v1/roku/devices/{id}/status -> RokuController::getStatus [AuthMiddleware,CastingEnabledMiddleware]',
        'GET /api/v1/servers -> Closure@Application.php []',
        'GET /api/v1/sessions/{id}/progress -> SessionController::getProgress []',
        'GET /api/v1/shows/{id}/markers/bulk -> MarkerController::getShowMarkers [AuthMiddleware]',
        'GET /api/v1/syncplay/groups -> SyncPlayController::listGroups [AuthMiddleware]',
        'GET /api/v1/syncplay/groups/{id} -> SyncPlayController::getGroup [AuthMiddleware]',
        'GET /api/v1/transcode/{jobId}/status -> TranscodeController::status [AuthMiddleware]',
        'GET /auth/github/authorize -> GithubCallbackController::authorize []',
        'GET /auth/github/callback -> GithubCallbackController::callback []',
        'GET /auth/identities -> AccountLinkController::listIdentities [AuthMiddleware]',
        'GET /auth/identities/link/github -> GithubCallbackController::authorizeLink [AuthMiddleware]',
        'GET /auth/identities/link/oidc -> OidcCallbackController::authorizeLink [AuthMiddleware]',
        'GET /auth/logout -> AuthController::logout []',
        'GET /auth/oidc/authorize -> OidcCallbackController::authorize []',
        'GET /auth/oidc/callback -> OidcCallbackController::callback []',
        'GET /dash/{job_id}/manifest -> DashController::getManifest [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /dash/{job_id}/{file} -> DashController::serveFile [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /health -> Closure@Application.php []',
        'GET /hls/{job_id}/playlist -> HlsController::getPlaylist [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /hls/{job_id}/{file} -> HlsController::serveFile [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /livetv/recording/{id}/stream'
            . ' -> LiveTvStreamController::streamRecording [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /livetv/timeshift/{sessionId}/stream'
            . ' -> LiveTvStreamController::streamTimeShift [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /livetv/timeshift/{sessionId}/{segment}'
            . ' -> LiveTvStreamController::streamTimeShiftSegment [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /opds/v1.2 -> BookController::opdsRoot [SignedUrlMiddleware]',
        'GET /opds/v1.2/books/{id}/cover -> BookController::opdsBookCover [SignedUrlMiddleware]',
        'GET /opds/v1.2/books/{id}/download -> BookController::downloadBook [SignedUrlMiddleware]',
        'GET /opds/v1.2/libraries -> BookController::opdsLibraries [SignedUrlMiddleware]',
        'GET /opds/v1.2/libraries/{id} -> BookController::opdsLibraryBooks [SignedUrlMiddleware]',
        'GET /stream/theme-media/item/{mediaItemId} -> ThemeMusicStreamController::streamItemTheme []',
        'GET /stream/theme-media/{libraryId}/audio -> ThemeMediaStreamController::streamAudio []',
        'GET /stream/theme-media/{libraryId}/video -> ThemeMediaStreamController::streamVideo []',
        'GET /system/info -> Closure@Application.php []',
        'GET /trickplay/{jobId}/sprite.jpg -> TrickplayController::getSprite []',
        'GET /trickplay/{jobId}/thumbs.bif -> TrickplayController::getBif []',
        'GET /trickplay/{jobId}/timeline.json -> TrickplayController::getTimeline []',
        'PATCH /api/v1/media/{id}/metadata -> MediaItemController::updateMetadata [AuthMiddleware]',
        'POST /api/v1/admin/auth-providers/github/config -> GithubAdminController::saveSettings [AdminMiddleware]',
        'POST /api/v1/admin/auth-providers/ldap/config -> LdapAdminController::saveSettings [AdminMiddleware]',
        'POST /api/v1/admin/auth-providers/ldap/test -> LdapAdminController::testConnection [AdminMiddleware]',
        'POST /api/v1/admin/auth-providers/oidc/config -> OidcAdminController::saveSettings [AdminMiddleware]',
        'POST /api/v1/admin/auth-providers/{name}/disable -> AuthProviderController::disableProvider [AdminMiddleware]',
        'POST /api/v1/admin/auth-providers/{name}/enable -> AuthProviderController::enableProvider [AdminMiddleware]',
        'POST /api/v1/admin/backup/create -> BackupController::create [AdminMiddleware]',
        'POST /api/v1/admin/backup/{id}/restore -> BackupController::restore [AdminMiddleware]',
        'POST /api/v1/admin/backup/{id}/upload-s3 -> BackupController::uploadS3 [AdminMiddleware]',
        'POST /api/v1/admin/dlna/start -> AdminDlnaServerController::start [AdminMiddleware]',
        'POST /api/v1/admin/dlna/stop -> AdminDlnaServerController::stop [AdminMiddleware]',
        'POST /api/v1/admin/livetv/guide/refresh -> AdminLiveTvController::refreshGuide [AdminMiddleware]',
        'POST /api/v1/admin/livetv/recordings -> AdminLiveTvController::createRecording [AdminMiddleware]',
        'POST /api/v1/admin/livetv/series-rules -> AdminLiveTvController::createSeriesRule [AdminMiddleware]',
        'POST /api/v1/admin/maintenance/cleanup-orphaned-stats'
            . ' -> MaintenanceController::cleanupOrphanedStats [AdminMiddleware]',
        'POST /api/v1/admin/maintenance/dedupe-paths -> MaintenanceController::dedupePaths [AdminMiddleware]',
        'POST /api/v1/admin/maintenance/reap-scan-jobs -> MaintenanceController::reapScanJobs [AdminMiddleware]',
        'POST /api/v1/admin/maintenance/reap-transcode-jobs'
            . ' -> MaintenanceController::reapTranscodeJobs [AdminMiddleware]',
        'POST /api/v1/admin/maintenance/storage-snapshot'
            . ' -> MaintenanceController::storageSnapshot [AdminMiddleware]',
        'POST /api/v1/admin/media/merge -> AdminMergeController::merge [AdminMiddleware]',
        'POST /api/v1/admin/plugins/catalog/sources -> PluginCatalogController::addSource [AdminMiddleware]',
        'POST /api/v1/admin/plugins/install -> PluginAdminController::install [AdminMiddleware]',
        'POST /api/v1/admin/plugins/updates/apply -> PluginCatalogController::applyUpdates [AdminMiddleware]',
        'POST /api/v1/admin/plugins/{name}/disable -> PluginAdminController::disable [AdminMiddleware]',
        'POST /api/v1/admin/plugins/{name}/enable -> PluginAdminController::enable [AdminMiddleware]',
        'POST /api/v1/admin/plugins/{name}/test -> PluginAdminController::testCredentials [AdminMiddleware]',
        'POST /api/v1/admin/plugins/{name}/update -> PluginCatalogController::updatePlugin [AdminMiddleware]',
        'POST /api/v1/admin/profiles/{id}/pin -> AdminProfileController::setPin [AdminMiddleware]',
        'POST /api/v1/admin/profiles/{profileId}/schedules'
            . ' -> AccessScheduleController::createForProfile [AdminMiddleware]',
        'POST /api/v1/admin/profiles/{profileId}/tags -> ProfileTagController::createForProfile [AdminMiddleware]',
        'POST /api/v1/admin/remote/hub/complete -> AdminHubController::hubComplete [AdminMiddleware]',
        'POST /api/v1/admin/remote/hub/heartbeat -> AdminHubController::hubHeartbeat [AdminMiddleware]',
        'POST /api/v1/admin/remote/hub/pair -> AdminHubController::hubPair [AdminMiddleware]',
        'POST /api/v1/admin/remote/hub/poll -> AdminHubController::hubPoll [AdminMiddleware]',
        'POST /api/v1/admin/remote/hub/unenroll -> AdminHubController::hubUnenroll [AdminMiddleware]',
        'POST /api/v1/admin/remote/portforward/disable -> AdminHubController::portForwardDisable [AdminMiddleware]',
        'POST /api/v1/admin/remote/portforward/enable -> AdminHubController::portForwardEnable [AdminMiddleware]',
        'POST /api/v1/admin/remote/relay/disable -> AdminHubController::relayDisable [AdminMiddleware]',
        'POST /api/v1/admin/remote/relay/enable -> AdminHubController::relayEnable [AdminMiddleware]',
        'POST /api/v1/admin/remote/relay/ping -> AdminHubController::relayPing [AdminMiddleware]',
        'POST /api/v1/admin/remote/subdomain/claim -> AdminHubController::subdomainClaim [AdminMiddleware]',
        'POST /api/v1/admin/remote/subdomain/release -> AdminHubController::subdomainRelease [AdminMiddleware]',
        'POST /api/v1/admin/restart -> AdminRestartController::restart [AdminMiddleware]',
        'POST /api/v1/admin/services/lastfm/disconnect -> LastfmController::apiDisconnect [AdminMiddleware]',
        'POST /api/v1/admin/services/trakt/disconnect -> TraktOAuthController::disconnect [AdminMiddleware]',
        'POST /api/v1/admin/sync/trash-guides -> SyncController::triggerSync []',
        'POST /api/v1/admin/users -> AdminUserController::create [AdminMiddleware]',
        'POST /api/v1/admin/users/{id}/approve -> AdminUserController::approve [AdminMiddleware]',
        'POST /api/v1/admin/users/{id}/disable -> AdminUserController::disable [AdminMiddleware]',
        'POST /api/v1/admin/users/{id}/reject -> AdminUserController::reject [AdminMiddleware]',
        'POST /api/v1/admin/users/{id}/reset-password -> AdminUserController::resetPassword [AdminMiddleware]',
        'POST /api/v1/admin/users/{id}/set-admin -> AdminUserController::setAdmin [AdminMiddleware]',
        'POST /api/v1/admin/users/{userId}/profiles -> AdminProfileController::createForUser [AdminMiddleware]',
        'POST /api/v1/admin/webhooks -> WebhookAdminController::create []',
        'POST /api/v1/admin/webhooks/subscriptions -> AdminWebhooksController::createSubscription [AdminMiddleware]',
        'POST /api/v1/admin/webhooks/{id}/test -> WebhookAdminController::test []',
        'POST /api/v1/airplay/devices/{id}/pause -> AirPlayController::pause [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/airplay/devices/{id}/resume'
            . ' -> AirPlayController::resume [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/airplay/devices/{id}/stop -> AirPlayController::stop [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/airplay/devices/{id}/stream'
            . ' -> AirPlayController::stream [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/audiobooks/{id}/progress -> AudiobookController::saveProgress [AuthMiddleware]',
        'POST /api/v1/auth/hub-token -> Closure@Application.php []',
        'POST /api/v1/auth/login -> AuthController::login []',
        'POST /api/v1/auth/refresh -> AuthController::refresh []',
        'POST /api/v1/auth/register -> AuthController::register []',
        'POST /api/v1/auth/webauthn/login/options -> WebAuthnController::startAuthentication []',
        'POST /api/v1/auth/webauthn/login/verify -> WebAuthnController::finishAuthentication []',
        'POST /api/v1/auth/webauthn/register/options -> WebAuthnController::startRegistration []',
        'POST /api/v1/auth/webauthn/register/verify -> WebAuthnController::finishRegistration []',
        'POST /api/v1/cast/devices/{id}/cast -> ChromecastController::cast [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/cast/devices/{id}/pause -> ChromecastController::pause [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/cast/devices/{id}/play -> ChromecastController::play [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/cast/devices/{id}/seek -> ChromecastController::seek [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/cast/devices/{id}/stop -> ChromecastController::stop [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/collections -> CollectionController::create [AuthMiddleware]',
        'POST /api/v1/collections/{id}/bulk-add -> CollectionController::bulkAdd [AuthMiddleware]',
        'POST /api/v1/collections/{id}/items/{mediaItemId} -> CollectionController::addItem [AuthMiddleware]',
        'POST /api/v1/collections/{id}/refresh -> CollectionController::refresh [AuthMiddleware]',
        'POST /api/v1/dlna/renderers/{id}/pause -> RendererListController::pause [AuthMiddleware]',
        'POST /api/v1/dlna/renderers/{id}/play -> RendererListController::playTo [AuthMiddleware]',
        'POST /api/v1/dlna/renderers/{id}/seek -> RendererListController::seek [AuthMiddleware]',
        'POST /api/v1/dlna/renderers/{id}/stop -> RendererListController::stop [AuthMiddleware]',
        // ⚠ S272: the `[]` on the LibraryController rails below is CORRECT and must
        // stay. These routes carry no ROUTE-level middleware, but every handler
        // calls `LibraryController::requireAdmin()` as its first statement, so they
        // ARE admin-gated — verified at runtime against a booted server (anonymous
        // 401 / authenticated non-admin 403 `auth.not_admin` / admin 202). Do not
        // "fix" this to `[AdminMiddleware]`. See the coverage statement above and
        // LibraryDestructiveRoutesAdminGateTest, which pins the gate itself.
        'POST /api/v1/libraries -> LibraryController::create []',
        'POST /api/v1/libraries/{id}/clear-artwork -> LibraryController::clearArtwork []',
        'POST /api/v1/libraries/{id}/clear-metadata -> LibraryController::clearMetadata []',
        'POST /api/v1/libraries/{id}/delete-all -> LibraryController::deleteAll []',
        'POST /api/v1/libraries/{id}/match-metadata -> LibraryController::matchMetadata []',
        'POST /api/v1/libraries/{id}/prune -> LibraryController::prune []',
        'POST /api/v1/libraries/{id}/refresh-metadata -> LibraryController::refreshMetadata []',
        'POST /api/v1/libraries/{id}/regenerate-assets -> LibraryController::regenerateAssets []',
        'POST /api/v1/libraries/{id}/rescan -> LibraryController::rescan []',
        'POST /api/v1/libraries/{id}/scan -> LibraryController::scan []',
        'POST /api/v1/libraries/{id}/theme-media/scan -> ThemeMediaController::scanThemeMedia []',
        'POST /api/v1/media/{id}/markers -> MediaMarkerController::createMarker [AuthMiddleware]',
        'POST /api/v1/media/{id}/match/apply -> MediaMatchController::apply []',
        'POST /api/v1/media/{id}/subtitles/download -> RemoteSubtitleController::download [AuthMiddleware]',
        'POST /api/v1/media/{id}/transcode -> TranscodeController::start [AuthMiddleware]',
        'POST /api/v1/media/{id}/unwatched -> MediaUserDataController::markUnwatched [AuthMiddleware]',
        'POST /api/v1/media/{id}/watched -> MediaUserDataController::markWatched [AuthMiddleware]',
        'POST /api/v1/playlists -> CollectionController::create [AuthMiddleware]',
        'POST /api/v1/profiles -> ProfilesController::create [AuthMiddleware]',
        'POST /api/v1/profiles/{profileId}/avatar -> ProfilesController::uploadAvatar [AuthMiddleware]',
        'POST /api/v1/profiles/{profileId}/pin -> ProfilesController::setPin [AuthMiddleware]',
        'POST /api/v1/profiles/{profileId}/pin/verify -> ProfilesController::verifyPin [AuthMiddleware]',
        'POST /api/v1/profiles/{profileId}/switch -> ProfilesController::switchProfile [AuthMiddleware]',
        'POST /api/v1/profiles/{profileId}/schedules -> AccessScheduleController::createForProfile [AuthMiddleware]',
        'POST /api/v1/profiles/{profileId}/tags -> ProfileTagController::createForProfile [AuthMiddleware]',
        'POST /api/v1/roku/devices/{id}/key/{keyName}'
            . ' -> RokuController::sendKey [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/roku/devices/{id}/launch/{channelId}'
            . ' -> RokuController::launchChannel [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/roku/devices/{id}/send -> RokuController::sendMedia [AuthMiddleware,CastingEnabledMiddleware]',
        'POST /api/v1/sessions -> SessionController::createSession []',
        'POST /api/v1/sessions/{id}/complete -> SessionController::completePlayback []',
        'POST /api/v1/sessions/{id}/progress -> SessionController::reportProgress []',
        'POST /api/v1/shuffle -> MediaItemController::shufflePlay [AuthMiddleware]',
        'POST /api/v1/syncplay/groups -> SyncPlayController::createGroup [AuthMiddleware]',
        'POST /api/v1/syncplay/groups/{id}/join -> SyncPlayController::joinGroup [AuthMiddleware]',
        'POST /api/v1/syncplay/groups/{id}/leave -> SyncPlayController::leaveGroup [AuthMiddleware]',
        'POST /auth/identities/link/hub -> AccountLinkController::linkHub [AuthMiddleware]',
        'POST /auth/identities/link/ldap -> AccountLinkController::linkLdap [AuthMiddleware]',
        'POST /auth/login -> AuthController::login []',
        'POST /auth/logout -> AuthController::logout []',
        'POST /auth/refresh -> AuthController::refresh []',
        'POST /auth/register -> AuthController::register []',
        'PUT /api/v1/admin/backup/schedule -> BackupController::updateSchedule [AdminMiddleware]',
        'PUT /api/v1/admin/livetv/channels/{id} -> AdminLiveTvController::updateChannel [AdminMiddleware]',
        'PUT /api/v1/admin/livetv/series-rules/{id} -> AdminLiveTvController::updateSeriesRule [AdminMiddleware]',
        'PUT /api/v1/admin/livetv/tuners/{id} -> AdminLiveTvController::updateTuner [AdminMiddleware]',
        'PUT /api/v1/admin/plugins/auto-update -> PluginCatalogController::autoUpdate [AdminMiddleware]',
        'PUT /api/v1/admin/plugins/catalog/channel -> PluginCatalogController::channel [AdminMiddleware]',
        'PUT /api/v1/admin/plugins/{name}/settings -> PluginAdminController::updateSettings [AdminMiddleware]',
        'PUT /api/v1/admin/profiles/{id} -> AdminProfileController::update [AdminMiddleware]',
        'PUT /api/v1/admin/profiles/{profileId}/schedules/{scheduleId}'
            . ' -> AccessScheduleController::updateSchedule [AdminMiddleware]',
        'PUT /api/v1/admin/profiles/{profileId}/stream-limits'
            . ' -> StreamLimitController::updateStreamLimits [AdminMiddleware]',
        'PUT /api/v1/admin/settings -> AdminSettingsController::update [AdminMiddleware]',
        'PUT /api/v1/admin/sync/enable -> SyncController::setEnabled []',
        'PUT /api/v1/admin/transcoding/tone-mapping -> AdminTranscodingController::setToneMapping [AdminMiddleware]',
        'PUT /api/v1/admin/updates/settings -> AdminUpdatesController::updateSettings [AdminMiddleware]',
        'PUT /api/v1/admin/users/{id} -> AdminUserController::update [AdminMiddleware]',
        'PUT /api/v1/admin/webhooks/{id} -> WebhookAdminController::update []',
        'PUT /api/v1/collections/{id} -> CollectionController::update [AuthMiddleware]',
        'PUT /api/v1/libraries/{id} -> LibraryController::update []',
        'PUT /api/v1/media/{id}/poster -> MediaPosterController::setPoster []',
        'PUT /api/v1/profiles/{profileId} -> ProfilesController::update [AuthMiddleware]',
        'PUT /api/v1/profiles/{profileId}/schedules/{scheduleId}'
            . ' -> AccessScheduleController::updateSchedule [AuthMiddleware]',
        'PUT /api/v1/profiles/{profileId}/stream-limits -> StreamLimitController::updateStreamLimits [AuthMiddleware]',
    ];

    /**
     * The rails this file is named for, in their manifest spelling. Guards
     * against "fixing" a manifest failure by deleting the line instead of
     * reverting the mutation.
     *
     * @var list<string>
     */
    private const GUARDED_RAILS = [
        'GET /api/v1/media/most-watched -> MostWatchedController::mostWatched [AuthMiddleware]',
        'GET /dash/{job_id}/manifest -> DashController::getManifest [SignedUrlMiddleware,StreamLimitMiddleware]',
        'GET /dash/{job_id}/{file} -> DashController::serveFile [SignedUrlMiddleware,StreamLimitMiddleware]',
        'POST /api/v1/sessions/{id}/complete -> SessionController::completePlayback []',
    ];

    private string $tempDir = '';
    private string $loggerConfigPath = '';
    private ?ContainerInterface $sharedContainer = null;
    private ?Application $sharedApplication = null;

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        LoggerFactory::reset();

        // Application registers AccessScheduleMiddleware GLOBALLY and it answers
        // 403 whenever RequestContext carries a user id. That context is process
        // static, so a sibling test that left one set would turn every dispatch
        // below into a 403 and the 401/404 controls would stop meaning anything.
        // Cleared on both ends, as ApplicationPlaybackAuthGateTest does.
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);

        $this->tempDir = sys_get_temp_dir() . '/phlix_s239_' . uniqid('', true);
        mkdir($this->tempDir, 0775, true);

        $this->loggerConfigPath = $this->tempDir . '/logger.php';
        file_put_contents(
            $this->loggerConfigPath,
            "<?php\nreturn [\n"
            . "    'default' => 'file',\n"
            . "    'handlers' => [\n"
            . "        'file' => [\n"
            . "            'type' => 'stream',\n"
            . "            'path' => " . var_export($this->tempDir . '/app.log', true) . ",\n"
            . "            'level' => 'debug',\n"
            . "        ],\n"
            . "    ],\n"
            . "];\n"
        );
    }

    protected function tearDown(): void
    {
        // S439: the container graph this test resolves constructs MediaAssetJobStore
        // and SimilarityJobStore through MediaServicesProvider's factories at the
        // production default queue paths, and their constructors mint the shared
        // /tmp directories. Sweep them so the suite leaves zero residue.
        foreach (['phlix_media_asset_jobs', 'phlix_similarity_jobs'] as $sharedQueue) {
            $sharedDir = sys_get_temp_dir() . '/' . $sharedQueue;
            if (is_dir($sharedDir)) {
                foreach (glob($sharedDir . '/*') ?: [] as $queued) {
                    @unlink($queued);
                }
                @rmdir($sharedDir);
            }
        }
        RequestContext::setUserId(null);
        RequestContext::setProfileId(null);
        LoggerFactory::reset();

        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * The PRODUCTION container: `ContainerFactory::defaultProviders()`, the same
     * stack `public/index.php` and the Workerman daemon build, with ONLY the MySQL
     * {@see Connection} doubled. Nothing about routing is substituted.
     */
    private function container(): ContainerInterface
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        $connection = $this->createMock(Connection::class);

        $providers = ContainerFactory::defaultProviders();
        $providers[] = new class ($connection) implements ServiceProviderInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function register(ContainerBuilder $builder, array $appConfig): void
            {
                $connection = $this->connection;

                $builder->addDefinitions([
                    Connection::class => factory(static fn (): Connection => $connection),
                ]);
            }
        };

        return $this->sharedContainer = ContainerFactory::create([
            'logger_config_path' => $this->loggerConfigPath,
            'db_config_path' => null,
        ], $providers);
    }

    private function application(): Application
    {
        if ($this->sharedApplication !== null) {
            return $this->sharedApplication;
        }

        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getPooledConnection')->willReturn($this->createMock(Connection::class));

        return $this->sharedApplication = new Application($this->container(), [], $pool);
    }

    private function request(string $path, string $method = 'GET'): Request
    {
        $request = new Request();
        $request->method = $method;
        $request->path = $path;
        $request->remoteIp = '127.0.0.1';

        return $request;
    }

    // -----------------------------------------------------------------
    // The extractor, with its anti-vacuity guard
    // -----------------------------------------------------------------

    /**
     * The route table of the `Router` instance `Application::dispatch()` delegates
     * to — the production one, not a fresh one built by this file.
     *
     * Fails LOUDLY, naming the reason, if it reads nothing or reads too little.
     * Without this an emptied loader, a renamed `$router` property or a
     * `getRoutes()` that stopped merging the static map would turn every assertion
     * in this file into a vacuous pass.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function routeTable(): array
    {
        $property = (new ReflectionClass(Application::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($this->application());

        if (!$router instanceof Router) {
            $this->fail(
                'ANTI-VACUITY: Application::$router did not hold a Router instance, so this file '
                . 'could not read the production route table at all. Every path assertion below '
                . 'would be meaningless. Fix the extractor before trusting a green run.'
            );
        }

        /** @var array<string, array<string, array<string, mixed>>> $routes */
        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }

        if ($count < self::MIN_EXPECTED_ROUTES) {
            $this->fail(sprintf(
                'ANTI-VACUITY: the composed Application route table holds %d route(s), fewer than '
                . 'the %d floor. Either a route loader was emptied/hollowed (or is swallowing a '
                . 'construction failure in one of its catch(\Throwable) blocks), or this harness is '
                . 'no longer building the PRODUCTION container — a hand-rolled one yields 53 where '
                . 'the real one yields 345 (S164). Either way the wire-path guard is NOT guarding '
                . 'anything.',
                $count,
                self::MIN_EXPECTED_ROUTES
            ));
        }

        return $routes;
    }

    /**
     * The production table re-keyed by the LITERAL registered path rather than by
     * the compiled regex `Router` stores parametric routes under, so every lookup
     * in this file can be an exact array-key hit.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function pathIndex(): array
    {
        $index = [];

        foreach ($this->routeTable() as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'] ?? null;
                $this->assertIsString($path, 'every route entry must carry its literal path');
                $this->assertArrayNotHasKey(
                    $path,
                    $index[$method] ?? [],
                    "{$method} {$path} is registered TWICE. The second registration silently wins at "
                    . 'dispatch, so the manifest below could pin a handler that never runs.'
                );
                $index[$method][$path] = $entry;
            }
        }

        return $index;
    }

    /**
     * Render the production route table in the manifest's line format.
     *
     * @return list<string>
     */
    private function renderedManifest(): array
    {
        $lines = [];

        foreach ($this->routeTable() as $method => $entries) {
            foreach ($entries as $entry) {
                $path = $entry['path'] ?? null;
                $this->assertIsString($path);

                $middleware = $entry['middleware'] ?? [];
                $this->assertIsArray($middleware);
                $names = [];
                foreach ($middleware as $item) {
                    $this->assertIsObject($item, "route {$method} {$path} has a non-object middleware");
                    $names[] = $item instanceof \Closure
                        ? 'Closure@' . $this->closureFile($item)
                        : $this->shortName($item::class);
                }

                $lines[] = sprintf(
                    '%s %s -> %s [%s]',
                    $method,
                    $path,
                    $this->renderHandler($method, $path, $entry['handler'] ?? null),
                    implode(',', $names)
                );
            }
        }

        sort($lines);

        return $lines;
    }

    private function renderHandler(string $method, string $path, mixed $handler): string
    {
        if (is_array($handler)) {
            // Both spellings are live in Application: most loaders bind an already
            // constructed controller (`[$controller, 'method']`), while Router's
            // typed helpers and AdminRoutes bind the class NAME and let the
            // container resolve it at dispatch (`[Controller::class, 'method']`).
            // Both render to the same short name, so the manifest pins the same
            // contract either way.
            $target = $handler[0];
            $this->assertTrue(
                is_object($target) || is_string($target),
                "route {$method} {$path} must bind an object or a class-string handler target"
            );
            $this->assertIsString($handler[1]);

            return $this->shortName(is_object($target) ? $target::class : $target) . '::' . $handler[1];
        }

        if ($handler instanceof \Closure) {
            return 'Closure@' . $this->closureFile($handler);
        }

        $this->fail(
            "route {$method} {$path} carries a handler of type " . get_debug_type($handler)
            . ' — neither a [target, method] pair nor a Closure, so this manifest cannot pin it'
        );
    }

    private function closureFile(\Closure $closure): string
    {
        return basename((string) (new \ReflectionFunction($closure))->getFileName());
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        $this->assertIsArray($decoded, 'the response body must be a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    // -----------------------------------------------------------------
    // 1. Anti-vacuity, stated as its own named test
    // -----------------------------------------------------------------

    /**
     * The floor {@see self::routeTable()} enforces on every other test in this
     * file, asserted once under its own name so a hollowed registrar produces a
     * failure that SAYS what happened rather than 300 confusing diffs.
     */
    public function testTheReflectedRouteTableIsTheProductionOneAndNotHollowedOut(): void
    {
        $count = 0;
        foreach ($this->routeTable() as $entries) {
            $count += count($entries);
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_EXPECTED_ROUTES,
            $count,
            'ANTI-VACUITY: the composed Application route table must hold at least '
            . self::MIN_EXPECTED_ROUTES . ' routes. Fewer means a loader was hollowed or the '
            . 'harness built the wrong container (53 vs 345, S164), and every other assertion in '
            . 'this file would be vacuous.'
        );
    }

    // -----------------------------------------------------------------
    // 2. The whole registered surface
    // -----------------------------------------------------------------

    /**
     * Whole-list strict comparison of the production route table against
     * {@see self::ROUTE_MANIFEST}. This is the part of the file that covers the
     * other 340 rails: renaming, deleting, re-verbing, re-pointing or un-gating
     * ANY of the 353 registrations reds it with a readable diff.
     *
     * It does NOT assert those rails' response envelopes — see the class
     * docblock's coverage statement for exactly which five are driven through
     * `dispatch()` and which 340 are not.
     */
    public function testTheRegisteredWirePathsMatchTheManifestExactly(): void
    {
        $expected = self::ROUTE_MANIFEST;
        sort($expected);

        $this->assertSame(
            $expected,
            $this->renderedManifest(),
            "Application's registered wire surface no longer matches the manifest in this file. If "
            . 'the change was intended, edit ROUTE_MANIFEST in the SAME commit and say so in the '
            . 'PR — these paths are the contract phlix-ui and the six native clients are written '
            . 'against, and a silent rename here 404s every client (S239).'
        );
    }

    /**
     * The manifest must actually still contain the rails this file is named for.
     * Guards against "fixing" a manifest failure by deleting the line instead of
     * reverting the mutation.
     */
    public function testTheManifestItselfStillCarriesTheRailsThisFileIsNamedFor(): void
    {
        foreach (self::GUARDED_RAILS as $rail) {
            $this->assertContains(
                $rail,
                self::ROUTE_MANIFEST,
                "'{$rail}' was removed from ROUTE_MANIFEST — restore it, or S30/S31/S59 are undone"
            );
        }
    }

    // -----------------------------------------------------------------
    // 3. S31 — the Most Watched rail
    // -----------------------------------------------------------------

    /**
     * Exact array-key lookup. This is the assertion the S31 mutation must break:
     * `'/api/v1/media/most-watched-MUTATED'` is a DIFFERENT key, so no containment
     * relationship between the two strings can rescue it.
     *
     * `MostWatchedControllerTest::testAuthMiddlewareGuardsTheRail()` registers this
     * path onto a router it builds itself, which is why deleting the PRODUCTION
     * registration left it green.
     */
    public function testTheMostWatchedRailIsRegisteredUnderItsExactPathLiteral(): void
    {
        $index = $this->pathIndex();

        $this->assertArrayHasKey('GET', $index);
        $this->assertArrayHasKey(
            self::MOST_WATCHED_PATH,
            $index['GET'],
            'Application must register GET ' . self::MOST_WATCHED_PATH . ' under exactly that '
            . 'literal. phlix-ui and the native clients send this path verbatim for the global '
            . 'trending rail; any other spelling 404s for every client (S31).'
        );

        // Stated separately so a green run cannot be explained by "both are present".
        $this->assertArrayNotHasKey(self::MOST_WATCHED_MUTATED_PATH, $index['GET']);
    }

    public function testTheMostWatchedRailResolvesToItsControllerAndStaysAuthGated(): void
    {
        $entry = $this->pathIndex()['GET'][self::MOST_WATCHED_PATH] ?? null;
        $this->assertIsArray($entry);

        $handler = $entry['handler'] ?? null;
        $this->assertIsArray($handler, 'the route must be a [target, method] pair, not a closure');
        $this->assertIsObject($handler[0]);
        $this->assertSame('MostWatchedController', $this->shortName($handler[0]::class));
        $this->assertSame('mostWatched', $handler[1]);

        $middleware = $entry['middleware'] ?? null;
        $this->assertIsArray($middleware);
        $names = [];
        foreach ($middleware as $item) {
            $this->assertIsObject($item);
            $names[] = $this->shortName($item::class);
        }

        // The rail is a GLOBAL aggregate over everyone's watch history. Moving it
        // out of the auth group would publish the server's most-watched titles —
        // including the R-rated ones a PG-capped profile must not see — to
        // anonymous callers.
        $this->assertSame(['AuthMiddleware'], $names, self::MOST_WATCHED_PATH . ' must stay auth-gated');
    }

    /**
     * Dispatch-level, with the control that makes the 401 readable: the real path
     * reaches the auth gate (401) while its strict SUPERSTRING reaches nothing
     * (404). A `str_contains` assertion cannot distinguish those two paths at all;
     * these two status codes do.
     */
    public function testDispatchingTheMostWatchedRailReachesTheAuthGateWhileTheMutatedSpellingIs404(): void
    {
        $application = $this->application();

        $served = $application->dispatch($this->request(self::MOST_WATCHED_PATH));
        $this->assertSame(
            401,
            $served->statusCode,
            'GET ' . self::MOST_WATCHED_PATH . ' must be ROUTED (and then refused by AuthMiddleware). '
            . 'A 404 here means the route literal changed or the registration was dropped.'
        );
        $this->assertSame('auth.required', $this->body($served)['code'] ?? null);

        $mutated = $application->dispatch($this->request(self::MOST_WATCHED_MUTATED_PATH));
        $this->assertSame(
            404,
            $mutated->statusCode,
            self::MOST_WATCHED_MUTATED_PATH . ' must not resolve. It is a strict superstring of the '
            . 'real path, which is exactly why a str_contains/includes assertion cannot tell the '
            . 'two apart.'
        );
    }

    // -----------------------------------------------------------------
    // 4. S59 — the two DASH rails
    // -----------------------------------------------------------------

    /**
     * Both DASH registrations, by exact literal. S59 deleted them and measured
     * ZERO extra red across Unit, Integration and E2E.
     */
    public function testTheDashWirePathsAreRegisteredUnderTheirExactPathLiterals(): void
    {
        $index = $this->pathIndex();

        foreach ([self::DASH_MANIFEST_PATH => 'getManifest', self::DASH_FILE_PATH => 'serveFile'] as $path => $method) {
            $this->assertArrayHasKey(
                $path,
                $index['GET'] ?? [],
                "Application must register GET {$path} under exactly that literal — it is the DASH "
                . 'playback surface, and S59 measured that deleting it reds nothing (S239).'
            );

            $handler = $index['GET'][$path]['handler'] ?? null;
            $this->assertIsArray($handler);
            $this->assertIsObject($handler[0]);
            $this->assertSame('DashController', $this->shortName($handler[0]::class));
            $this->assertSame($method, $handler[1]);

            $names = [];
            foreach ($index['GET'][$path]['middleware'] ?? [] as $item) {
                $this->assertIsObject($item);
                $names[] = $this->shortName($item::class);
            }
            $this->assertSame(
                ['SignedUrlMiddleware', 'StreamLimitMiddleware'],
                $names,
                "GET {$path} must keep BOTH gates: the signed-URL check and the stream-limit check"
            );
        }
    }

    /**
     * Dispatch-level, with a control that is a DIFFERENT refusal from a different
     * layer: `/dash/{job}` is not a registered shape at all and 404s from the
     * router, while both real rails 401 from `SignedUrlMiddleware`. So "401" here
     * means the route matched, not that everything refuses.
     */
    public function testDispatchingTheDashWirePathsReachesTheSignedUrlGateWhileAnUnroutedSiblingIs404(): void
    {
        $application = $this->application();

        foreach (['/dash/job-1/manifest', '/dash/job-1/init.mp4'] as $path) {
            $response = $application->dispatch($this->request($path));
            $this->assertSame(
                401,
                $response->statusCode,
                "GET {$path} must be ROUTED (and then refused by SignedUrlMiddleware). A 404 here "
                . 'means the DASH registration was renamed or deleted (S59).'
            );
        }

        $control = $application->dispatch($this->request('/dash/job-1'));
        $this->assertSame(
            404,
            $control->statusCode,
            'a 404 must still be producible on this prefix, otherwise the 401s above prove nothing'
        );
    }

    // -----------------------------------------------------------------
    // 5. S30 — the playback finish signal
    // -----------------------------------------------------------------

    public function testTheSessionCompleteWirePathIsRegisteredUnderItsExactPathLiteral(): void
    {
        $index = $this->pathIndex();

        $this->assertArrayHasKey(
            self::SESSION_COMPLETE_PATH,
            $index['POST'] ?? [],
            'Application must register POST ' . self::SESSION_COMPLETE_PATH . ' under exactly that '
            . 'literal. It is the signal every client sends when playback finishes; without it '
            . 'nothing is ever marked watched (S30).'
        );

        $handler = $index['POST'][self::SESSION_COMPLETE_PATH]['handler'] ?? null;
        $this->assertIsArray($handler);
        $this->assertIsObject($handler[0]);
        $this->assertSame('SessionController', $this->shortName($handler[0]::class));
        $this->assertSame('completePlayback', $handler[1]);
    }

    /**
     * The strongest dispatch control in this file: both replies are 404, and the
     * BODIES tell them apart. The registered route reaches
     * `SessionController::completePlayback()`, which answers its own
     * `{"error":"Session not found"}` for an unknown session; an unregistered
     * sibling gets the ROUTER's `{"error":"Not Found", "message":…}`. Asserting the
     * status alone would have been the "a second 404 is not a control" mistake.
     */
    public function testDispatchingTheSessionCompleteWirePathReachesItsHandlerNotTheRouterMiss(): void
    {
        $application = $this->application();

        $served = $application->dispatch($this->request('/api/v1/sessions/no-such-session/complete', 'POST'));
        $this->assertSame(404, $served->statusCode);
        $this->assertSame(
            ['error' => 'Session not found'],
            $this->body($served),
            'POST ' . self::SESSION_COMPLETE_PATH . ' must reach SessionController::completePlayback '
            . "and get the HANDLER's own miss. The router's own miss has a different body, so this "
            . 'assertion goes red the moment the registration is deleted (S30).'
        );

        $control = $application->dispatch($this->request('/api/v1/sessions/no-such-session/complete-MUTATED', 'POST'));
        $this->assertSame(404, $control->statusCode);
        $this->assertSame(
            'Not Found',
            $this->body($control)['error'] ?? null,
            "the ROUTER's miss must be distinguishable from the handler's miss, otherwise the "
            . 'assertion above cannot tell a served route from a deleted one'
        );
    }

    // -----------------------------------------------------------------
    // 6. S36 — Next Up, on the router the CONTAINER composes
    // -----------------------------------------------------------------

    /**
     * S236 pins this rail on a `WebPortalRouter` it constructs by hand. That leaves
     * one link unpinned: the instance the PRODUCTION container resolves — which is
     * what `HttpHandler` and `RelayRequestDispatcher` both call
     * (`$container->get(WebPortalRouter::class)`). If the container stopped wiring
     * it, or wired a differently-configured one, S236 would stay green and every
     * client's Next-Up rail would 404.
     *
     * Exact array-key lookup, for the S37/S236 reason: `'…/next-up-MUTATED'`
     * CONTAINS `'…/next-up'`.
     */
    public function testTheNextUpRailIsRegisteredOnTheWebPortalRouterTheContainerComposes(): void
    {
        $webPortalRouter = $this->container()->get(WebPortalRouter::class);
        $this->assertInstanceOf(
            WebPortalRouter::class,
            $webPortalRouter,
            'the production container must resolve a WebPortalRouter — HttpHandler and '
            . 'RelayRequestDispatcher both reach the SPA rails through exactly this lookup'
        );

        $property = (new ReflectionClass(WebPortalRouter::class))->getProperty('router');
        $property->setAccessible(true);
        $router = $property->getValue($webPortalRouter);
        $this->assertInstanceOf(Router::class, $router);

        $routes = $router->getRoutes();

        $count = 0;
        foreach ($routes as $entries) {
            $count += count($entries);
        }
        $this->assertGreaterThanOrEqual(
            40,
            $count,
            'ANTI-VACUITY: the container-composed WebPortalRouter holds only ' . $count
            . ' route(s). Its registrar was hollowed, or this lookup no longer reads the table '
            . 'WebPortalRouter::dispatch() serves — either way the assertion below is vacuous.'
        );

        $this->assertArrayHasKey('GET', $routes);
        $this->assertArrayHasKey(
            self::NEXT_UP_PATH,
            $routes['GET'],
            'the CONTAINER-COMPOSED WebPortalRouter must register GET ' . self::NEXT_UP_PATH
            . ' under exactly that literal (S36/S236)'
        );
        $this->assertArrayNotHasKey(self::NEXT_UP_PATH . '-MUTATED', $routes['GET']);
    }

    // -----------------------------------------------------------------
    // 6b. S275 — the trickplay BIF wire path
    // -----------------------------------------------------------------

    /** The Roku BIF archive route added by S275. */
    private const BIF_PATH = '/trickplay/{jobId}/thumbs.bif';

    /**
     * The manifest above already compares the whole list exactly, so a deletion
     * reds. This adds the half a manifest cannot cover: that the new literal is
     * REACHED at dispatch rather than absorbed by a sibling. `/trickplay/` carries
     * three registrations that differ only in their final segment, and a pattern
     * that swallowed `thumbs.bif` would leave the manifest perfectly green while
     * every Roku scrub was answered by the wrong handler
     * ([[feedback_a_sibling_wildcard_absorbs_a_deleted_route]]).
     */
    public function testTheTrickplayBifWirePathIsRegisteredUnderItsExactPathLiteral(): void
    {
        $index = $this->pathIndex();

        $this->assertArrayHasKey('GET', $index);
        $this->assertArrayHasKey(
            self::BIF_PATH,
            $index['GET'],
            'Application must register GET ' . self::BIF_PATH . ' under exactly that literal; '
            . 'without it the trickplay_bif_url the media-item endpoint advertises has nothing '
            . 'serving it.'
        );

        // The two paths S275 DELETED, stated separately: only the (also deleted)
        // TrickplayGenerator ever wrote index.xml / bif_NN.jpg, so re-adding
        // either would restore a route over a file nothing produces.
        $this->assertArrayNotHasKey('/trickplay/{jobId}/index.xml', $index['GET']);
        $this->assertArrayNotHasKey('/trickplay/{jobId}/thumb-{index}.jpg', $index['GET']);

        $entry = $index['GET'][self::BIF_PATH];
        $handler = $entry['handler'] ?? null;
        $this->assertIsArray($handler, 'the route must be a [target, method] pair, not a closure');
        $this->assertIsObject($handler[0]);
        $this->assertSame('TrickplayController', $this->shortName($handler[0]::class));
        $this->assertSame('getBif', $handler[1]);
    }

    /**
     * Dispatch control, shaped like the session-complete one above: both replies
     * are 404 and the BODIES tell them apart. A job with no archive reaches
     * `TrickplayController::getBif()`, which answers its own
     * `"Trickplay BIF not found"`; an unregistered sibling gets the ROUTER's
     * `"The requested resource was not found"`. Comparing statuses alone would be
     * the "a second 404 is not a control" mistake.
     */
    public function testDispatchingTheTrickplayBifPathReachesItsHandlerNotTheRouterMiss(): void
    {
        $application = $this->application();

        $served = $application->dispatch($this->request('/trickplay/no-such-job/thumbs.bif'));
        $this->assertSame(404, $served->statusCode);
        $this->assertSame(
            'Trickplay BIF not found',
            $this->body($served)['message'] ?? null,
            'GET /trickplay/{jobId}/thumbs.bif must reach TrickplayController::getBif and get the '
            . "HANDLER's own miss. If a sibling /trickplay/ pattern absorbed it, or the "
            . 'registration were deleted, the body would be the router\'s instead.'
        );

        $control = $application->dispatch($this->request('/trickplay/no-such-job/thumbs.bif-MUTATED'));
        $this->assertSame(404, $control->statusCode);
        $this->assertSame(
            'The requested resource was not found',
            $this->body($control)['message'] ?? null,
            "the ROUTER's miss must be distinguishable from the handler's miss, otherwise the "
            . 'assertion above cannot tell a served route from a deleted one'
        );
    }

    // -----------------------------------------------------------------
    // 7. The dispatch probe is not uniformly refusing
    // -----------------------------------------------------------------

    /**
     * A SUCCEEDING control beside the refusals. Every other dispatch assertion in
     * this file reads a 401 or a 404; if the composed application refused
     * everything — a global middleware short-circuiting, a broken container — those
     * would all still pass. This one must be a 200 with a real body.
     */
    public function testALiveRouteStillAnswers200SoTheRefusalsAboveAreReadable(): void
    {
        $response = $this->application()->dispatch($this->request('/health'));

        $this->assertSame(
            200,
            $response->statusCode,
            'GET /health must answer 200 through the SAME composed application the 401/404 '
            . 'assertions above use. If it does not, those refusals prove nothing.'
        );
        $this->assertSame('ok', $this->body($response)['status'] ?? null);
    }
}
