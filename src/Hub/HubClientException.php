<?php

declare(strict_types=1);

namespace Phlex\Hub;

/**
 * Exception thrown by HubClient on hub API errors.
 *
 * @package Phlex\Hub
 * @since 0.11.0
 */
final class HubClientException extends \RuntimeException
{
    /** @var string The hub error code. */
    private string $errorCode;

    /**
     * Creates a new HubClientException.
     *
     * @param string $message   Human-readable error message.
     * @param int    $httpCode  HTTP status code from the hub.
     * @param string $errorCode Hub-specific error code string.
     */
    public function __construct(string $message, int $httpCode, string $errorCode)
    {
        parent::__construct($message, $httpCode);
        $this->errorCode = $errorCode;
    }

    /**
     * Returns the hub-specific error code.
     *
     * @return string The error code (e.g. `SERVER_KEY_INVALID`).
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
