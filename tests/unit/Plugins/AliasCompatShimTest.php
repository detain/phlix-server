<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Verifies that every `class_alias` entry registered by
 * `src/Plugins/AliasCompatShim.php` resolves to its corresponding
 * `Phlex\Shared\…` FQCN. Guarantees plugins that imported the
 * pre-0.11 FQCNs (e.g. `phlex-plugin-example` v0.1.0) keep working.
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
                \Phlex\Common\Events\AbstractEvent::class,
                \Phlex\Shared\Events\AbstractEvent::class,
            ],
            'PlaybackStarted' => [
                \Phlex\Common\Events\Playback\PlaybackStarted::class,
                \Phlex\Shared\Events\Playback\PlaybackStarted::class,
            ],
            'PlaybackPaused' => [
                \Phlex\Common\Events\Playback\PlaybackPaused::class,
                \Phlex\Shared\Events\Playback\PlaybackPaused::class,
            ],
            'PlaybackResumed' => [
                \Phlex\Common\Events\Playback\PlaybackResumed::class,
                \Phlex\Shared\Events\Playback\PlaybackResumed::class,
            ],
            'PlaybackStopped' => [
                \Phlex\Common\Events\Playback\PlaybackStopped::class,
                \Phlex\Shared\Events\Playback\PlaybackStopped::class,
            ],
            'LibraryScanStarted' => [
                \Phlex\Common\Events\Library\LibraryScanStarted::class,
                \Phlex\Shared\Events\Library\LibraryScanStarted::class,
            ],
            'LibraryScanCompleted' => [
                \Phlex\Common\Events\Library\LibraryScanCompleted::class,
                \Phlex\Shared\Events\Library\LibraryScanCompleted::class,
            ],
            'MediaItemAdded' => [
                \Phlex\Common\Events\Library\MediaItemAdded::class,
                \Phlex\Shared\Events\Library\MediaItemAdded::class,
            ],
            'MediaItemUpdated' => [
                \Phlex\Common\Events\Library\MediaItemUpdated::class,
                \Phlex\Shared\Events\Library\MediaItemUpdated::class,
            ],
            'MediaItemRemoved' => [
                \Phlex\Common\Events\Library\MediaItemRemoved::class,
                \Phlex\Shared\Events\Library\MediaItemRemoved::class,
            ],
            'UserCreated' => [
                \Phlex\Common\Events\Auth\UserCreated::class,
                \Phlex\Shared\Events\Auth\UserCreated::class,
            ],
            'UserLoggedIn' => [
                \Phlex\Common\Events\Auth\UserLoggedIn::class,
                \Phlex\Shared\Events\Auth\UserLoggedIn::class,
            ],
            'UserLoggedOut' => [
                \Phlex\Common\Events\Auth\UserLoggedOut::class,
                \Phlex\Shared\Events\Auth\UserLoggedOut::class,
            ],
            'EventNameMap' => [
                \Phlex\Plugins\EventNameMap::class,
                \Phlex\Shared\Plugin\EventNameMap::class,
            ],
            'ManifestType' => [
                \Phlex\Plugins\ManifestType::class,
                \Phlex\Shared\Plugin\ManifestType::class,
            ],
            'ManifestValidationError' => [
                \Phlex\Plugins\ManifestValidationError::class,
                \Phlex\Shared\Plugin\ManifestValidationError::class,
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
                \Phlex\Common\Events\Playback\PlaybackStarted::class,
                \Phlex\Shared\Events\AbstractEvent::class,
                true
            ),
            'Aliased event classes must inherit Phlex\\Shared\\Events\\AbstractEvent.'
        );
    }
}
