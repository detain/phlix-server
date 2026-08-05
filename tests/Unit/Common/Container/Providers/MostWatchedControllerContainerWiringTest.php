<?php

declare(strict_types=1);

namespace Phlix\Tests\Unit\Common\Container\Providers;

use DI\Container;
use DI\ContainerBuilder;
use Phlix\Auth\UserProfileManager;
use Phlix\Auth\UserRepository;
use Phlix\Common\Container\Providers\AdminServicesProvider;
use Phlix\Common\Container\Providers\MediaServicesProvider;
use Phlix\Media\Library\ItemRepository;
use Phlix\Media\Library\RatingGate;
use Phlix\Server\Http\Controllers\MostWatchedController;
use Phlix\Server\Http\Request;
use Phlix\Stats\StatsCollector;
use PHPUnit\Framework\TestCase;
use Workerman\MySQL\Connection;

/**
 * S213 — the anti-FAKE-FIX pin.
 *
 * ## Why this file exists at all
 *
 * {@see MostWatchedController} is built by PHP-DI `autowire()`
 * ({@see AdminServicesProvider}), and **`autowire()` silently SKIPS optional
 * constructor parameters**. So the "obvious" fix — copying
 * {@see \Phlix\Server\Http\Controllers\MediaItemController}'s
 * `?RatingGate $ratingGate = null` shape — would leave the gate NULL in
 * production forever, the rail ungated, and *every* unit test that hand-builds
 * the controller with a gate still green. This estate has already been bitten by
 * exactly that twice: `RatingGate::$users` and
 * `MediaUserDataController::$ratingGate`, both rescued only by an explicit
 * `constructorParameter()` binding after the fact.
 *
 * A test that constructs the controller itself therefore proves NOTHING about
 * wiring. These tests resolve the controller **from a container built by the
 * real production providers** and assert on its BEHAVIOUR, so reverting the
 * required ctor param back to an optional nullable one reddens them.
 *
 * The only doubles are the leaf I/O collaborators (repositories / stats), which
 * would otherwise need a live MySQL connection. `RatingGate` and
 * `MostWatchedController` themselves are built by the providers under test.
 */
final class MostWatchedControllerContainerWiringTest extends TestCase
{
    /**
     * A container registered with the SAME two providers that build this
     * controller in production, with only the leaf DB-backed collaborators
     * replaced.
     *
     * @param array{allowedRatings: list<string>, allowUnrated: bool}|null $filter
     *        What the active profile's cap resolves to.
     * @param array<int, array<string, mixed>>                             $rows
     *        What `findByIds()` hands back for the rail.
     * @param array<int, array<string, mixed>>                             $topMedia
     *        What the server-wide aggregate reports.
     */
    private function container(?array $filter, array $rows = [], array $topMedia = []): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        // The real production definitions: AdminServicesProvider declares
        // MostWatchedController, MediaServicesProvider declares RatingGate.
        (new MediaServicesProvider())->register($builder, []);
        (new AdminServicesProvider())->register($builder, []);

        $stats = $this->createMock(StatsCollector::class);
        $stats->method('getTopMedia')->willReturn($topMedia);

        $items = $this->createMock(ItemRepository::class);
        $items->method('findByIds')->willReturn($rows);
        $items->method('effectiveContentRatingsForIds')->willReturn([]);

        $profiles = $this->createMock(UserProfileManager::class);
        $profiles->method('getActiveRatingFilter')->willReturn($filter);

        // Non-admin account: the owner shortcut must not short-circuit the cap.
        $users = $this->createMock(UserRepository::class);
        $users->method('findById')->willReturn(null);

        $builder->addDefinitions([
            StatsCollector::class    => $stats,
            ItemRepository::class    => $items,
            UserProfileManager::class => $profiles,
            UserRepository::class    => $users,
        ]);

        return $builder->build();
    }

    /**
     * 🔴 THE WIRING PIN. The controller the CONTAINER produces must actually
     * hold a {@see RatingGate}. With an optional nullable ctor param PHP-DI
     * skips it and this property is null — the exact production-only failure a
     * hand-constructed controller can never see.
     */
    public function testTheContainerBuiltControllerHoldsARatingGate(): void
    {
        $controller = $this->container(null)->get(MostWatchedController::class);
        self::assertInstanceOf(MostWatchedController::class, $controller);

        $gate = (fn () => $this->ratingGate)->call($controller);

        self::assertInstanceOf(
            RatingGate::class,
            $gate,
            'PHP-DI did not inject a RatingGate into MostWatchedController. If the ctor '
            . 'param is optional, autowire() SKIPS it and the Most Watched rail is '
            . 'ungated in production while every hand-built unit test stays green.'
        );
    }

    /**
     * The behavioural half of the same pin: drive the CONTAINER-BUILT controller
     * with a PG-capped profile and assert on the RETURNED ROWS. A null gate
     * would return the R-rated title here — the wiring defect made visible as
     * the product defect it actually is.
     */
    public function testTheContainerBuiltRailRefusesOverCapRowsForACappedProfile(): void
    {
        $container = $this->container(
            ['allowedRatings' => ['G', 'PG'], 'allowUnrated' => false],
            [
                ['id' => 'r-1',  'name' => 'Adult Blockbuster', 'type' => 'movie',
                    'content_rating' => 'R', 'metadata' => []],
                ['id' => 'pg-1', 'name' => 'Family Film', 'type' => 'movie',
                    'content_rating' => 'PG', 'metadata' => []],
            ],
            [
                ['media_item_id' => 'r-1',  'play_count' => 99],
                ['media_item_id' => 'pg-1', 'play_count' => 50],
            ]
        );

        /** @var MostWatchedController $controller */
        $controller = $container->get(MostWatchedController::class);

        $request = new Request();
        $request->userId = 'kid-account';

        $response = $controller->mostWatched($request, []);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(
            ['pg-1'],
            array_column($body['items'], 'id'),
            'The container-built rail served an over-cap title to a PG-capped profile.'
        );
    }

    /**
     * THE COST OF MAKING THE DEPENDENCY REQUIRED, pinned deliberately.
     *
     * A required param cannot be skipped — but it CAN make the whole entry
     * unbuildable, and `Application::loadApiRoutes()` wraps this controller's
     * resolution in `try { … } catch (\Throwable) { /* route not registered *\/ }`.
     * So a `RatingGate` that the real definitions cannot build would not leave an
     * ungated rail (fail-closed, the right direction) but WOULD silently delete
     * the rail. This resolves the controller from the two REAL providers with
     * only the leaf MySQL {@see Connection} doubled — no repository or profile
     * overrides — so the whole RatingGate sub-graph is built from its shipped
     * definitions.
     */
    public function testTheRealProviderDefinitionsAloneCanBuildTheGatedController(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        (new MediaServicesProvider())->register($builder, []);
        (new AdminServicesProvider())->register($builder, []);
        $builder->addDefinitions([Connection::class => $this->createMock(Connection::class)]);

        $controller = $builder->build()->get(MostWatchedController::class);

        self::assertInstanceOf(MostWatchedController::class, $controller);
        self::assertInstanceOf(
            RatingGate::class,
            (fn () => $this->ratingGate)->call($controller),
            'The shipped RatingGate definition could not be built here, so in production '
            . 'Application::loadApiRoutes() would swallow the failure and the Most Watched '
            . 'route would silently not be registered at all.'
        );
    }

    /**
     * THE NOISE CONTROL. The gate must be a strict no-op for the owner /
     * un-capped profile, so this rule cannot be blamed for a shrunken rail and
     * quietly removed.
     */
    public function testTheContainerBuiltRailIsUnchangedForAnUnCappedProfile(): void
    {
        $container = $this->container(
            null,
            [
                ['id' => 'r-1',  'name' => 'Adult Blockbuster', 'type' => 'movie',
                    'content_rating' => 'R', 'metadata' => []],
                ['id' => 'pg-1', 'name' => 'Family Film', 'type' => 'movie',
                    'content_rating' => 'PG', 'metadata' => []],
            ],
            [
                ['media_item_id' => 'r-1',  'play_count' => 99],
                ['media_item_id' => 'pg-1', 'play_count' => 50],
            ]
        );

        /** @var MostWatchedController $controller */
        $controller = $container->get(MostWatchedController::class);

        $request = new Request();
        $request->userId = 'owner-account';

        $response = $controller->mostWatched($request, []);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->body, true);

        self::assertSame(['r-1', 'pg-1'], array_column($body['items'], 'id'));
    }
}
