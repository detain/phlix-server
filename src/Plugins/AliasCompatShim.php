<?php

/**
 * Deprecation aliases registered on Composer autoload.
 *
 * This file is loaded via `composer.json#autoload.files` so the
 * `class_alias` calls run before any user code touches the moved
 * classes. Every alias here is `@deprecated since 0.11.0; removed in
 * 0.12.0` — update your `use` statements to the new
 * `Phlex\Shared\…` FQCNs at your earliest convenience.
 *
 * Note: `LifecycleInterface` cannot be aliased (PHP's `class_alias`
 * does not work on interfaces). It is bridged via an
 * `interface … extends …` declaration in
 * `src/Plugins/Contract/LifecycleInterface.php` instead.
 *
 * @see plans/expansion/b.1-shared-design.md §4.1 — the canonical move table.
 * @see plans/expansion/b.3-shared-consume.md — the step that introduced this file.
 */

declare(strict_types=1);

// 12 event DTOs + AbstractEvent base = 13 event aliases.
$eventAliases = [
    \Phlex\Common\Events\AbstractEvent::class => \Phlex\Shared\Events\AbstractEvent::class,
    \Phlex\Common\Events\Playback\PlaybackStarted::class => \Phlex\Shared\Events\Playback\PlaybackStarted::class,
    \Phlex\Common\Events\Playback\PlaybackPaused::class => \Phlex\Shared\Events\Playback\PlaybackPaused::class,
    \Phlex\Common\Events\Playback\PlaybackResumed::class => \Phlex\Shared\Events\Playback\PlaybackResumed::class,
    \Phlex\Common\Events\Playback\PlaybackStopped::class => \Phlex\Shared\Events\Playback\PlaybackStopped::class,
    \Phlex\Common\Events\Library\LibraryScanStarted::class => \Phlex\Shared\Events\Library\LibraryScanStarted::class,
    \Phlex\Common\Events\Library\LibraryScanCompleted::class => \Phlex\Shared\Events\Library\LibraryScanCompleted::class,
    \Phlex\Common\Events\Library\MediaItemAdded::class => \Phlex\Shared\Events\Library\MediaItemAdded::class,
    \Phlex\Common\Events\Library\MediaItemUpdated::class => \Phlex\Shared\Events\Library\MediaItemUpdated::class,
    \Phlex\Common\Events\Library\MediaItemRemoved::class => \Phlex\Shared\Events\Library\MediaItemRemoved::class,
    \Phlex\Common\Events\Auth\UserCreated::class => \Phlex\Shared\Events\Auth\UserCreated::class,
    \Phlex\Common\Events\Auth\UserLoggedIn::class => \Phlex\Shared\Events\Auth\UserLoggedIn::class,
    \Phlex\Common\Events\Auth\UserLoggedOut::class => \Phlex\Shared\Events\Auth\UserLoggedOut::class,
];

// Plugin DTO aliases — manifest pieces and event-name lookup table.
$pluginAliases = [
    \Phlex\Plugins\EventNameMap::class => \Phlex\Shared\Plugin\EventNameMap::class,
    \Phlex\Plugins\ManifestType::class => \Phlex\Shared\Plugin\ManifestType::class,
    \Phlex\Plugins\ManifestValidationError::class => \Phlex\Shared\Plugin\ManifestValidationError::class,
];

foreach ([$eventAliases, $pluginAliases] as $aliasGroup) {
    foreach ($aliasGroup as $oldFqcn => $newFqcn) {
        if (!class_exists($oldFqcn, false) && !interface_exists($oldFqcn, false) && !enum_exists($oldFqcn, false)) {
            class_alias($newFqcn, $oldFqcn);
        }
    }
}
