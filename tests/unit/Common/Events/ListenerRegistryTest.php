<?php

declare(strict_types=1);

namespace Phlex\Tests\Unit\Common\Events;

use Crell\Tukio\Dispatcher;
use Phlex\Common\Events\ListenerRegistry;
use Phlex\Common\Logger\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Phlex\Tests\Fixtures\Events\SampleEvent;

/**
 * Unit tests for {@see ListenerRegistry}.
 *
 * @covers \Phlex\Common\Events\ListenerRegistry
 */
final class ListenerRegistryTest extends TestCase
{
    public function test_subscribe_then_dispatch_invokes_listener(): void
    {
        $registry = new ListenerRegistry();
        $dispatcher = new Dispatcher($registry->provider());

        $captured = [];
        $registry->subscribe(SampleEvent::class, function (SampleEvent $event) use (&$captured): void {
            $captured[] = $event->message;
        });

        $dispatcher->dispatch(new SampleEvent('hello'));
        $dispatcher->dispatch(new SampleEvent('world'));

        $this->assertSame(['hello', 'world'], $captured);
    }

    public function test_priority_orders_listeners(): void
    {
        $registry = new ListenerRegistry();
        $dispatcher = new Dispatcher($registry->provider());

        $order = [];
        $registry->subscribe(SampleEvent::class, function (SampleEvent $_e) use (&$order): void {
            $order[] = 'low';
        }, priority: 1);

        $registry->subscribe(SampleEvent::class, function (SampleEvent $_e) use (&$order): void {
            $order[] = 'high';
        }, priority: 100);

        $dispatcher->dispatch(new SampleEvent('go'));

        $this->assertSame(['high', 'low'], $order);
    }

    public function test_unsubscribe_removes_listener(): void
    {
        $registry = new ListenerRegistry(logger: $this->silentLogger());
        $dispatcher = new Dispatcher($registry->provider());

        $hits = 0;
        $listener = function (SampleEvent $_e) use (&$hits): void {
            $hits++;
        };

        $registry->subscribe(SampleEvent::class, $listener);
        $dispatcher->dispatch(new SampleEvent('one'));
        $this->assertSame(1, $hits);

        $this->assertTrue($registry->unsubscribe(SampleEvent::class, $listener));

        $dispatcher->dispatch(new SampleEvent('two'));
        $this->assertSame(1, $hits, 'listener should not have fired after unsubscribe');
    }

    public function test_unsubscribe_unknown_pair_is_idempotent(): void
    {
        $registry = new ListenerRegistry(logger: $this->silentLogger());

        $result = $registry->unsubscribe(SampleEvent::class, fn (SampleEvent $_e) => null);
        $this->assertFalse($result);
    }

    public function test_unsubscribe_twice_is_idempotent(): void
    {
        $registry = new ListenerRegistry(logger: $this->silentLogger());
        $listener = static fn (SampleEvent $_e) => null;

        $registry->subscribe(SampleEvent::class, $listener);
        $this->assertTrue($registry->unsubscribe(SampleEvent::class, $listener));
        $this->assertFalse($registry->unsubscribe(SampleEvent::class, $listener));
    }

    public function test_duplicate_subscribe_throws(): void
    {
        $registry = new ListenerRegistry();
        $listener = static fn (SampleEvent $_e) => null;

        $registry->subscribe(SampleEvent::class, $listener);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(SampleEvent::class);
        $registry->subscribe(SampleEvent::class, $listener);
    }

    public function test_subscribe_returns_listener_id(): void
    {
        $registry = new ListenerRegistry();
        $id = $registry->subscribe(SampleEvent::class, static fn (SampleEvent $_e) => null);
        $this->assertNotSame('', $id);
    }

    public function test_provider_returns_psr_listener_provider(): void
    {
        $registry = new ListenerRegistry();
        $this->assertInstanceOf(
            \Psr\EventDispatcher\ListenerProviderInterface::class,
            $registry->provider()
        );
    }

    public function test_subscribe_with_array_callable(): void
    {
        $registry = new ListenerRegistry();
        $dispatcher = new Dispatcher($registry->provider());

        $target = new class () {
            public int $hits = 0;
            public function handle(SampleEvent $_e): void
            {
                $this->hits++;
            }
        };

        $registry->subscribe(SampleEvent::class, [$target, 'handle']);
        $dispatcher->dispatch(new SampleEvent('a'));
        $this->assertSame(1, $target->hits);
    }

    public function test_subscribe_with_invokable_object(): void
    {
        $registry = new ListenerRegistry();
        $dispatcher = new Dispatcher($registry->provider());

        $invokable = new class () {
            public int $hits = 0;
            public function __invoke(SampleEvent $_e): void
            {
                $this->hits++;
            }
        };

        $registry->subscribe(SampleEvent::class, $invokable);
        $dispatcher->dispatch(new SampleEvent('b'));
        $this->assertSame(1, $invokable->hits);
    }

    public function test_subscribe_with_string_function_name(): void
    {
        // The hash for a string-callable is distinct from a closure
        // with the same body, so registering both for the same event
        // should succeed without colliding.
        $registry = new ListenerRegistry();
        $registry->subscribe(SampleEvent::class, 'trim');
        $registry->subscribe(SampleEvent::class, fn (SampleEvent $_e) => null);
        $this->addToAssertionCount(1);
    }

    public function test_subscribe_with_static_array_callable(): void
    {
        $registry = new ListenerRegistry();
        $registry->subscribe(
            SampleEvent::class,
            [\Phlex\Tests\Fixtures\Events\StaticListener::class, 'handle']
        );
        $this->addToAssertionCount(1);
    }

    /**
     * A StructuredLogger built against an in-memory stream so log
     * writes during the test don't touch the filesystem.
     */
    private function silentLogger(): StructuredLogger
    {
        return new StructuredLogger('events.test', [
            'handlers' => [
                'null' => [
                    'type' => 'stream',
                    'path' => 'php://memory',
                    'level' => 'debug',
                ],
            ],
        ]);
    }
}
