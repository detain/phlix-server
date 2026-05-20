<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies that every `class_alias` entry registered by
 * `src/Plugins/AliasCompatShim.php` resolves to its corresponding
 * `Phlix\Shared\…` FQCN. Guarantees plugins that imported the
 * pre-0.11 FQCNs (e.g. `phlix-plugin-example` v0.1.0) keep working.
 *
 * @coversNothing
 */
final class AliasCompatShimTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function aliasProvider(): array
    {
        return [
            'AbstractEvent' => [
                \Phlix\Common\Events\AbstractEvent::class,
                \Phlix\Shared\Events\AbstractEvent::class,
            ],
            'PlaybackStarted' => [
                \Phlix\Common\Events\Playback\PlaybackStarted::class,
                \Phlix\Shared\Events\Playback\PlaybackStarted::class,
            ],
            'PlaybackPaused' => [
                \Phlix\Common\Events\Playback\PlaybackPaused::class,
                \Phlix\Shared\Events\Playback\PlaybackPaused::class,
            ],
            'PlaybackResumed' => [
                \Phlix\Common\Events\Playback\PlaybackResumed::class,
                \Phlix\Shared\Events\Playback\PlaybackResumed::class,
            ],
            'PlaybackStopped' => [
                \Phlix\Common\Events\Playback\PlaybackStopped::class,
                \Phlix\Shared\Events\Playback\PlaybackStopped::class,
            ],
            'LibraryScanStarted' => [
                \Phlix\Common\Events\Library\LibraryScanStarted::class,
                \Phlix\Shared\Events\Library\LibraryScanStarted::class,
            ],
            'LibraryScanCompleted' => [
                \Phlix\Common\Events\Library\LibraryScanCompleted::class,
                \Phlix\Shared\Events\Library\LibraryScanCompleted::class,
            ],
            'MediaItemAdded' => [
                \Phlix\Common\Events\Library\MediaItemAdded::class,
                \Phlix\Shared\Events\Library\MediaItemAdded::class,
            ],
            'MediaItemUpdated' => [
                \Phlix\Common\Events\Library\MediaItemUpdated::class,
                \Phlix\Shared\Events\Library\MediaItemUpdated::class,
            ],
            'MediaItemRemoved' => [
                \Phlix\Common\Events\Library\MediaItemRemoved::class,
                \Phlix\Shared\Events\Library\MediaItemRemoved::class,
            ],
            'UserCreated' => [
                \Phlix\Common\Events\Auth\UserCreated::class,
                \Phlix\Shared\Events\Auth\UserCreated::class,
            ],
            'UserLoggedIn' => [
                \Phlix\Common\Events\Auth\UserLoggedIn::class,
                \Phlix\Shared\Events\Auth\UserLoggedIn::class,
            ],
            'UserLoggedOut' => [
                \Phlix\Common\Events\Auth\UserLoggedOut::class,
                \Phlix\Shared\Events\Auth\UserLoggedOut::class,
            ],
            'EventNameMap' => [
                \Phlix\Plugins\EventNameMap::class,
                \Phlix\Shared\Plugin\EventNameMap::class,
            ],
            'ManifestType' => [
                \Phlix\Plugins\ManifestType::class,
                \Phlix\Shared\Plugin\ManifestType::class,
            ],
            'ManifestValidationError' => [
                \Phlix\Plugins\ManifestValidationError::class,
                \Phlix\Shared\Plugin\ManifestValidationError::class,
            ],
        ];
    }

    /**
     * @dataProvider aliasProvider
     */
    public function test_alias_resolves_to_shared_fqcn(string $oldFqcn, string $newFqcn): void
    {
        // class_exists with autoload covers classes and enums; for the
        // enum aliases (ManifestType) class_exists returns false, so
        // also check via enum_exists.
        $this->assertTrue(
            class_exists($oldFqcn) || enum_exists($oldFqcn),
            sprintf('Alias "%s" must be resolvable.', $oldFqcn)
        );

        $reflection = new ReflectionClass($oldFqcn);
        $this->assertSame(
            $newFqcn,
            $reflection->getName(),
            sprintf(
                'Alias "%s" must resolve to "%s", got "%s".',
                $oldFqcn,
                $newFqcn,
                $reflection->getName()
            )
        );
    }

    public function test_event_aliases_share_abstract_event_ancestor(): void
    {
        $this->assertTrue(
            is_a(
                \Phlix\Common\Events\Playback\PlaybackStarted::class,
                \Phlix\Shared\Events\AbstractEvent::class,
                true
            ),
            'Aliased event classes must inherit Phlix\\Shared\\Events\\AbstractEvent.'
        );
    }
}
