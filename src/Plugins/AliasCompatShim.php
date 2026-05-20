<?php

/**
 * Deprecation aliases registered on Composer autoload.
 *
 * This file is loaded via `composer.json#autoload.files` so the
 * `class_alias` calls run before any user code touches the moved
 * classes. Every alias here is `@deprecated since 0.11.0; removed in
 * 0.12.0` — update your `use` statements to the new
 * `Phlix\Shared\…` FQCNs at your earliest convenience.
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
    \Phlix\Common\Events\AbstractEvent::class => \Phlix\Shared\Events\AbstractEvent::class,
    \Phlix\Common\Events\Playback\PlaybackStarted::class => \Phlix\Shared\Events\Playback\PlaybackStarted::class,
    \Phlix\Common\Events\Playback\PlaybackPaused::class => \Phlix\Shared\Events\Playback\PlaybackPaused::class,
    \Phlix\Common\Events\Playback\PlaybackResumed::class => \Phlix\Shared\Events\Playback\PlaybackResumed::class,
    \Phlix\Common\Events\Playback\PlaybackStopped::class => \Phlix\Shared\Events\Playback\PlaybackStopped::class,
    \Phlix\Common\Events\Library\LibraryScanStarted::class => \Phlix\Shared\Events\Library\LibraryScanStarted::class,
    \Phlix\Common\Events\Library\LibraryScanCompleted::class => \Phlix\Shared\Events\Library\LibraryScanCompleted::class,
    \Phlix\Common\Events\Library\MediaItemAdded::class => \Phlix\Shared\Events\Library\MediaItemAdded::class,
    \Phlix\Common\Events\Library\MediaItemUpdated::class => \Phlix\Shared\Events\Library\MediaItemUpdated::class,
    \Phlix\Common\Events\Library\MediaItemRemoved::class => \Phlix\Shared\Events\Library\MediaItemRemoved::class,
    \Phlix\Common\Events\Auth\UserCreated::class => \Phlix\Shared\Events\Auth\UserCreated::class,
    \Phlix\Common\Events\Auth\UserLoggedIn::class => \Phlix\Shared\Events\Auth\UserLoggedIn::class,
    \Phlix\Common\Events\Auth\UserLoggedOut::class => \Phlix\Shared\Events\Auth\UserLoggedOut::class,
];

// Plugin DTO aliases — manifest pieces and event-name lookup table.
$pluginAliases = [
    \Phlix\Plugins\EventNameMap::class => \Phlix\Shared\Plugin\EventNameMap::class,
    \Phlix\Plugins\ManifestType::class => \Phlix\Shared\Plugin\ManifestType::class,
    \Phlix\Plugins\ManifestValidationError::class => \Phlix\Shared\Plugin\ManifestValidationError::class,
];

foreach ([$eventAliases, $pluginAliases] as $aliasGroup) {
    foreach ($aliasGroup as $oldFqcn => $newFqcn) {
        if (!class_exists($oldFqcn, false) && !interface_exists($oldFqcn, false) && !enum_exists($oldFqcn, false)) {
            class_alias($newFqcn, $oldFqcn);
        }
    }
}
