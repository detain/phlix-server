# Event reference

The Phlix server publishes a small, typed set of PSR-14 events. Plugins
subscribe to these by class FQCN (or by their manifest alias once the
Phase A.4 plugin loader lands) and react to playback, library scans, and
auth lifecycle.

This is the **canonical catalog**. The doc-generator tool added in a
later phase reads it; reviewers cross-check it against every class under
`src/Common/Events/`. Add a row here whenever a new event class is added
to the codebase.

## How dispatch works

Phlix uses [Crell\Tukio](https://github.com/Crell/Tukio) as its PSR-14
implementation. The container exposes three relevant bindings:

| Container ID                                       | Purpose                                                       |
| -------------------------------------------------- | ------------------------------------------------------------- |
| `Psr\EventDispatcher\EventDispatcherInterface`     | The dispatcher. Inject this to **publish** events.            |
| `Phlix\Common\Events\ListenerRegistry`             | Facade for **subscribing** listeners.                         |
| `Phlix\Common\Events\EventDispatcherFactory`       | Builds the dispatcher; rarely needed by application code.     |

When the environment variable `PHLIX_DEBUG_EVENTS` is truthy
(`1` / `true` / `yes` / `on`) every dispatched event is logged at debug
level on the `events` channel (`.logs/events.log` by default).

## How to subscribe

```php
use Phlix\Common\Events\ListenerRegistry;
use Phlix\Shared\Events\Playback\PlaybackStarted;

/** @var ListenerRegistry $registry */
$registry = $container->get(ListenerRegistry::class);

$registry->subscribe(
    PlaybackStarted::class,
    function (PlaybackStarted $event): void {
        // do something with $event->userId, $event->mediaItemId, …
    },
    priority: 10,   // optional — higher runs first
);
```

To unsubscribe later (e.g. when a plugin is disabled), call
`$registry->unsubscribe(PlaybackStarted::class, $sameCallable)`. Calling
`unsubscribe()` on a callable that was never subscribed (or is already
inactive) emits a warning on the `events` log channel but does **not**
throw — clean plugin disable cycles matter more than strict bookkeeping.

## Event catalog

| Event FQCN                                                 | Manifest alias                  | Payload fields                                                                              | Fired by                                                                          | Typical listener                                                                       |
| ---------------------------------------------------------- | ------------------------------- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| `Phlix\Shared\Events\Playback\PlaybackStarted`             | `phlix.playback.started`        | `sessionId`, `userId`, `mediaItemId`, `deviceId`, `positionTicks`                           | `Phlix\Session\PlaybackController::reportProgress()` on first progress for a pair | Scrobble plugins (Trakt, Last.fm), analytics, Discord rich presence, watch-history     |
| `Phlix\Shared\Events\Playback\PlaybackPaused`              | `phlix.playback.paused`         | `sessionId`, `userId`, `mediaItemId`, `deviceId`, `positionTicks`                           | `Phlix\Session\PlaybackController::reportProgress()` when status flips to paused  | Scrobble plugins (mark stopped), now-playing dashboards, AFK presence                  |
| `Phlix\Shared\Events\Playback\PlaybackResumed`             | `phlix.playback.resumed`        | `sessionId`, `userId`, `mediaItemId`, `deviceId`, `positionTicks`                           | `Phlix\Session\PlaybackController::reportProgress()` when status flips to playing | Scrobble plugins (restart), dashboards, "movie mode" integrations                      |
| `Phlix\Shared\Events\Playback\PlaybackStopped`             | `phlix.playback.stopped`        | `sessionId`, `userId`, `mediaItemId`, `deviceId`, `finalPositionTicks`, `reachedEnd`        | `Phlix\Session\PlaybackController::markAsWatched()` / `clearProgress()`           | Scrobble plugins (final), watch-history complete markers, recommendation refreshers    |
| `Phlix\Shared\Events\Library\LibraryScanStarted`           | `phlix.library.scan.started`    | `libraryId`, `libraryName`, `path`                                                          | `Phlix\Media\Library\MediaScanner::scan()` at the top of the walk                 | Progress dashboard, scan-started notifier, maintenance-window coordinator              |
| `Phlix\Shared\Events\Library\LibraryScanCompleted`         | `phlix.library.scan.completed`  | `libraryId`, `itemsAdded`, `itemsUpdated`, `itemsRemoved`, `durationMs`                     | `Phlix\Media\Library\MediaScanner::scan()` after the walk completes               | Webhook notifier, dashboard refresher, recommendation cache invalidator                |
| `Phlix\Shared\Events\Library\MediaItemAdded`               | `phlix.library.item.added`      | `mediaItemId`, `libraryId`, `path`, `type`                                                  | `Phlix\Media\Library\MediaScanner::processFile()` after a new item is persisted   | Metadata-refresh queue worker, "what's new" notifier, intro-detection job queuer       |
| `Phlix\Shared\Events\Library\MediaItemUpdated`             | `phlix.library.item.updated`    | `mediaItemId`, `changedFields[]`                                                            | Metadata-refresh writes in `Phlix\Media\Library\ItemRepository` (wired later)     | Search-index re-indexer, recommendation cache invalidator, third-party mirrors         |
| `Phlix\Shared\Events\Library\MediaItemRemoved`             | `phlix.library.item.removed`    | `mediaItemId`, `libraryId`                                                                  | Cleanup passes in `ItemRepository` / `MediaScanner` (wired later)                 | Search-index cleaner, "file is gone" notifier, watch-history archiver                  |
| `Phlix\Shared\Events\Auth\UserCreated`                     | `phlix.user.created`            | `userId`, `username`, `email`                                                               | `Phlix\Auth\AuthManager::register()` after the user row is persisted              | Welcome-email sender, audit-log writer, default-permissions bootstrap                  |
| `Phlix\Shared\Events\Auth\UserLoggedIn`                    | `phlix.user.logged_in`          | `userId`, `sessionId`, `ipAddress`, `userAgent`                                             | `Phlix\Auth\AuthManager::login()` after credential verification succeeds          | Presence integrations, security-anomaly detector, device-registry updater              |
| `Phlix\Shared\Events\Auth\UserLoggedOut`                   | `phlix.user.logged_out`         | `userId`, `sessionId`, `reason` (`explicit` / `expired` / `revoked`)                        | `Phlix\Auth\AuthManager::logout()` plus token-revocation paths (wired later)      | Presence integrations, audit-log writer, "session revoked" notifier, hub mirror        |

Every event also exposes the `int $timestamp` field (UNIX seconds at
construction) inherited from `Phlix\Shared\Events\AbstractEvent`.

> **Note on manifest aliases.** PSR-14 dispatch in Phlix is keyed by
> event class FQCN — string topics are not used internally. The
> "manifest alias" column documents the string identifier that Phase
> A.4's plugin loader will map to the FQCN when a plugin manifest
> declares `"events": ["phlix.playback.started"]`. Application code
> outside plugin manifests should always use the FQCN form.
