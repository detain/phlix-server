<?php

declare(strict_types=1);

namespace Phlex\Server\WebSocket;

/**
 * Interface for WebSocket connections.
 *
 * This interface defines the contract that all WebSocket connection
 * implementations must follow, enabling testing and flexibility.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description Contract for WebSocket connection implementations.
 * @see Connection For the default implementation
 */
interface ConnectionInterface
{
    /**
     * Gets the unique connection identifier.
     *
     * @return string Unique connection ID
     */
    public function getId(): string;

    /**
     * Sends data to the connected client.
     *
     * @param string|array<string, mixed> $data Data to send
     * @return void
     */
    public function send(string|array $data): void;

    /**
     * Sends a typed message event to the client.
     *
     * @param string $type The message type/event name
     * @param array<string, mixed> $data The event payload
     * @return void
     */
    public function sendMessage(string $type, array $data = []): void;

    /**
     * Closes the connection.
     *
     * @return void
     */
    public function close(): void;

    /**
     * Updates the last activity timestamp.
     *
     * @return void
     */
    public function updateActivity(): void;

    /**
     * Gets the last activity timestamp.
     *
     * @return int Unix timestamp
     */
    public function getLastActivity(): int;

    /**
     * Checks if the connection is authenticated.
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool;

    /**
     * Sets the authentication state.
     *
     * @param bool $authenticated Authentication state
     * @param string|null $userId User ID if authenticated
     * @return void
     */
    public function setAuthenticated(bool $authenticated, ?string $userId = null): void;

    /**
     * Gets the authenticated user ID.
     *
     * @return string|null User ID or null
     */
    public function getUserId(): ?string;

    /**
     * Sets the current session ID.
     *
     * @param string|null $sessionId Session ID
     * @return void
     */
    public function setSessionId(?string $sessionId): void;

    /**
     * Gets the current session ID.
     *
     * @return string|null Session ID or null
     */
    public function getSessionId(): ?string;

    /**
     * Stores a value in session data.
     *
     * @param string $key Data key
     * @param mixed $value Value to store
     * @return void
     */
    public function set(string $key, mixed $value): void;

    /**
     * Retrieves a value from session data.
     *
     * @param string $key Data key
     * @param mixed $default Default value if not found
     * @return mixed Stored value or default
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Checks if a key exists in session data.
     *
     * @param string $key Data key
     * @return bool True if key exists
     */
    public function has(string $key): bool;

    /**
     * Removes a key from session data.
     *
     * @param string $key Data key
     * @return void
     */
    public function remove(string $key): void;

    /**
     * Gets all session data.
     *
     * @return array<string, mixed> All session data
     */
    public function getAll(): array;
}
