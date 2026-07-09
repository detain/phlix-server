<?php

/**
 * Phlix media server component: Plugin.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Plugin;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Plugin-scoped event dispatcher with priority-based handler registration.
 *
 * This dispatcher extends the PSR-14 event system with plugin-context
 * aware handler registration. Unlike the global {@see \Phlix\Common\Events\ListenerRegistry},
 * this dispatcher:
 * - Tracks which plugin registered each handler
 * - Passes plugin context to each handler
 * - Supports priority-based ordering (higher priority runs first)
 *
 * ## Supported Events
 * - `item.scanned`         — Emitted when a media item is scanned into the library.
 * - `transcode.started`   — Emitted when a transcode job starts.
 * - `transcode.completed`  — Emitted when a transcode job completes.
 * - `user.watched`        — Emitted when a user watches media content.
 * - `syncplay.room_created` — Emitted when a SyncPlay room is created.
 * - `syncplay.user_joined`  — Emitted when a user joins a SyncPlay room.
 *
 * ## Handler Signature
 * Handlers receive two arguments:
 * ```
 * function(array $payload, string $pluginId): void
 * ```
 *
 * @package Phlix\Plugin
 * @since 0.15.0
 */
final class PluginEventDispatcher
{
    /**
     * Registered handlers indexed by event name.
     *
     * @var array<string, list<array{
     *     handler: callable,
     *     pluginId: string,
     *     priority: int
     * }>>
     */
    private array $handlers = [];

    /**
     * Sorted handler lists by event (regenerated when handlers change).
     *
     * @var array<string, list<array{
     *     handler: callable,
     *     pluginId: string,
     *     priority: int
     * }>>
     */
    private array $sortedHandlers = [];

    /**
     * Supported event names.
     *
     * @var array<string, true>
     */
    public const SUPPORTED_EVENTS = [
        'item.scanned'          => true,
        'transcode.started'     => true,
        'transcode.completed'   => true,
        'user.watched'          => true,
        'syncplay.room_created' => true,
        'syncplay.user_joined'  => true,
    ];

    /**
     * Optional logger for handler invocation errors.
     */
    private ?LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Optional PSR-3 logger.
     *
     * @since 0.15.0
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Register a handler for an event.
     *
     * @param string   $event    Event name (e.g. `item.scanned`).
     * @param callable $handler  Handler receiving (array $payload, string $pluginId): void.
     * @param int      $priority Higher priority runs first; default 0.
     *
     * @throws \InvalidArgumentException When the event name is not supported.
     *
     * @since 0.15.0
     */
    public function registerHandler(string $event, callable $handler, int $priority = 0): void
    {
        if (!$this->isSupportedEvent($event)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported event "%s". Supported events: %s',
                $event,
                implode(', ', array_keys(self::SUPPORTED_EVENTS))
            ));
        }

        $this->handlers[$event][] = [
            'handler'  => $handler,
            'pluginId' => '',
            'priority' => $priority,
        ];

        unset($this->sortedHandlers[$event]);
    }

    /**
     * Register a handler for an event with a specific plugin context.
     *
     * The pluginId is stored alongside the handler and passed to the
     * handler when the event is dispatched.
     *
     * @param string   $event    Event name.
     * @param string   $pluginId  Plugin identifier for context.
     * @param callable $handler  Handler receiving (array $payload, string $pluginId): void.
     * @param int      $priority Higher priority runs first; default 0.
     *
     * @throws \InvalidArgumentException When the event name is not supported.
     *
     * @since 0.15.0
     */
    public function registerHandlerForPlugin(
        string $event,
        string $pluginId,
        callable $handler,
        int $priority = 0
    ): void {
        if (!$this->isSupportedEvent($event)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported event "%s". Supported events: %s',
                $event,
                implode(', ', array_keys(self::SUPPORTED_EVENTS))
            ));
        }

        $this->handlers[$event][] = [
            'handler'  => $handler,
            'pluginId' => $pluginId,
            'priority' => $priority,
        ];

        unset($this->sortedHandlers[$event]);
    }

    /**
     * Dispatch an event to all registered handlers.
     *
     * Handlers are invoked in priority order (highest first). If a handler
     * throws, the error is logged and the next handler is still invoked.
     *
     * @param string         $event    Event name.
     * @param array<mixed>   $payload  Event payload data.
     * @param string         $pluginId Optional plugin context (empty string means system event).
     *
     * @throws \InvalidArgumentException When the event name is not supported.
     *
     * @since 0.15.0
     */
    public function dispatch(string $event, array $payload, string $pluginId = ''): void
    {
        if (!$this->isSupportedEvent($event)) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported event "%s". Supported events: %s',
                $event,
                implode(', ', array_keys(self::SUPPORTED_EVENTS))
            ));
        }

        $sorted = $this->getSortedHandlers($event);

        foreach ($sorted as $entry) {
            $handler = $entry['handler'];
            $contextPluginId = $entry['pluginId'] !== '' ? $entry['pluginId'] : $pluginId;

            try {
                $handler($payload, $contextPluginId);
            } catch (\Throwable $e) {
                $this->logger()->warning('plugin event handler threw', [
                    'event'    => $event,
                    'plugin'   => $contextPluginId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get all registered handlers for a specific event.
     *
     * @param string $event Event name.
     *
     * @return list<array{handler: callable, pluginId: string, priority: int}>
     *
     * @since 0.15.0
     */
    public function getHandlersForEvent(string $event): array
    {
        return $this->getSortedHandlers($event);
    }

    /**
     * Get the list of supported event names.
     *
     * @return list<string>
     *
     * @since 0.15.0
     */
    public function getSupportedEvents(): array
    {
        return array_keys(self::SUPPORTED_EVENTS);
    }

    /**
     * Check if an event name is supported.
     *
     * @param string $event Event name to check.
     *
     * @return bool True if the event is supported.
     *
     * @since 0.15.0
     */
    public function isSupportedEvent(string $event): bool
    {
        return isset(self::SUPPORTED_EVENTS[$event]);
    }

    /**
     * Clear all handlers for a specific event.
     *
     * @param string $event Event name.
     *
     * @since 0.15.0
     */
    public function clearHandlers(string $event): void
    {
        unset($this->handlers[$event], $this->sortedHandlers[$event]);
    }

    /**
     * Clear all handlers for a specific plugin.
     *
     * @param string $pluginId Plugin identifier.
     *
     * @since 0.15.0
     */
    public function clearHandlersForPlugin(string $pluginId): void
    {
        foreach ($this->handlers as $event => $handlers) {
            $filtered = array_filter(
                $handlers,
                fn(array $entry): bool => $entry['pluginId'] !== $pluginId
            );
            if ($filtered !== $handlers) {
                $this->handlers[$event] = array_values($filtered);
                unset($this->sortedHandlers[$event]);
            }
        }
    }

    /**
     * Get handlers sorted by priority (highest first).
     *
     * @param string $event Event name.
     *
     * @return list<array{handler: callable, pluginId: string, priority: int}>
     *
     * @since 0.15.0
     */
    private function getSortedHandlers(string $event): array
    {
        if (isset($this->sortedHandlers[$event])) {
            return $this->sortedHandlers[$event];
        }

        if (!isset($this->handlers[$event])) {
            return [];
        }

        $handlers = $this->handlers[$event];
        usort(
            $handlers,
            static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']
        );

        $this->sortedHandlers[$event] = $handlers;
        return $handlers;
    }

    /**
     * Get the logger instance.
     *
     * @return LoggerInterface
     *
     * @since 0.15.0
     */
    private function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = new NullLogger();
        }
        return $this->logger;
    }
}
