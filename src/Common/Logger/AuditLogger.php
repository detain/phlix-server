<?php

namespace Phlex\Common\Logger;

/**
 * Specialized logger for security and audit events.
 * All authentication events, authorization failures, and sensitive operations.
 */
class AuditLogger
{
    private StructuredLogger $logger;

    public function __construct(StructuredLogger $logger)
    {
        $this->logger = $logger;
    }

    public function logLogin(string $userId, string $deviceId, bool $success, ?string $reason = null): void
    {
        $this->logger->info('User login attempt', [
            'event' => 'login',
            'user_id' => $userId,
            'device_id' => $deviceId,
            'success' => $success,
            'reason' => $reason,
        ]);
    }

    public function logLogout(string $userId, string $sessionId): void
    {
        $this->logger->info('User logout', [
            'event' => 'logout',
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);
    }

    public function logFailedAuth(string $reason, array $context = []): void
    {
        $this->logger->warning('Authentication failure', array_merge([
            'event' => 'auth_failure',
            'reason' => $reason,
        ], $context));
    }

    public function logPermissionDenied(string $userId, string $resource, string $action): void
    {
        $this->logger->warning('Permission denied', [
            'event' => 'permission_denied',
            'user_id' => $userId,
            'resource' => $resource,
            'action' => $action,
        ]);
    }

    public function logApiKeyCreated(string $userId, string $keyId, string $keyName): void
    {
        $this->logger->info('API key created', [
            'event' => 'api_key_created',
            'user_id' => $userId,
            'key_id' => $keyId,
            'key_name' => $keyName,
        ]);
    }

    public function logApiKeyRevoked(string $userId, string $keyId): void
    {
        $this->logger->info('API key revoked', [
            'event' => 'api_key_revoked',
            'user_id' => $userId,
            'key_id' => $keyId,
        ]);
    }

    public function logDataExport(string $userId, string $dataType, int $recordCount): void
    {
        $this->logger->info('Data export', [
            'event' => 'data_export',
            'user_id' => $userId,
            'data_type' => $dataType,
            'record_count' => $recordCount,
        ]);
    }
}
