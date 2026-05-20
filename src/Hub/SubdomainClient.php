<?php

declare(strict_types=1);

namespace Phlix\Hub;

use Phlix\Common\Logger\StructuredLogger;

/**
 * Client for managing server subdomain allocation with the hub.
 *
 * Handles claiming a subdomain from the hub after enrollment and
 * storing the subdomain/TLS configuration locally.
 *
 * @package Phlix\Hub
 * @since 0.12.0
 */
class SubdomainClient
{
    private const SUBDOMAIN_CONFIG_FILE = 'hub-subdomain.json';

    /** @var HubClient */
    private HubClient $hubClient;

    /** @var string */
    private string $serverId;

    /** @var StructuredLogger */
    private StructuredLogger $logger;

    /** @var string */
    private string $configDir;

    /**
     * @param HubClient      $hubClient Hub client instance.
     * @param string         $serverId Server UUID.
     * @param StructuredLogger $logger  Application logger.
     * @param string         $configDir Directory for configuration storage.
     */
    public function __construct(
        HubClient $hubClient,
        string $serverId,
        StructuredLogger $logger,
        string $configDir,
    ) {
        $this->hubClient = $hubClient;
        $this->serverId = $serverId;
        $this->logger = $logger;
        $this->configDir = $configDir;
    }

    /**
     * Claim a subdomain from the hub.
     *
     * POSTs to the hub's subdomain allocation endpoint and stores
     * the result locally.
     *
     * @return SubdomainResult|null The allocated subdomain result, or null on failure.
     *
     * @since 0.12.0
     */
    public function claimSubdomain(): ?SubdomainResult
    {
        $enrollment = $this->hubClient->loadEnrollment();
        if ($enrollment === null) {
            $this->logger->warning('Cannot claim subdomain: not enrolled');
            return null;
        }

        $httpClient = $this->hubClient->getHttpClient();

        try {
            $response = $httpClient->post(
                "/api/v1/servers/{$this->serverId}/subdomain",
                [],
            );

            if ($response->statusCode !== 200) {
                $this->logger->error('Subdomain claim failed', [
                    'status' => $response->statusCode,
                    'server_id' => $this->serverId,
                ]);
                return null;
            }

            $result = SubdomainResult::fromArray($response->body);
            $this->storeSubdomain($result);

            $this->logger->info('Subdomain claimed', [
                'subdomain' => $result->subdomain,
                'fqdn' => $result->fqdn,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Subdomain claim exception', [
                'exception' => $e->getMessage(),
                'server_id' => $this->serverId,
            ]);
            return null;
        }
    }

    /**
     * Release the server's subdomain.
     *
     * @return bool True if release succeeded.
     *
     * @since 0.12.0
     */
    public function releaseSubdomain(): bool
    {
        $enrollment = $this->hubClient->loadEnrollment();
        if ($enrollment === null) {
            $this->logger->warning('Cannot release subdomain: not enrolled');
            return false;
        }

        $httpClient = $this->hubClient->getHttpClient();

        try {
            $response = $httpClient->delete(
                "/api/v1/servers/{$this->serverId}/subdomain",
            );

            if ($response->statusCode !== 204) {
                $this->logger->error('Subdomain release failed', [
                    'status' => $response->statusCode,
                    'server_id' => $this->serverId,
                ]);
                return false;
            }

            $this->clearSubdomain();

            $this->logger->info('Subdomain released');

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Subdomain release exception', [
                'exception' => $e->getMessage(),
                'server_id' => $this->serverId,
            ]);
            return false;
        }
    }

    /**
     * Get the current subdomain from local config.
     *
     * @return string|null The subdomain or null if not claimed.
     *
     * @since 0.12.0
     */
    public function getCurrentSubdomain(): ?string
    {
        $path = $this->getSubdomainPath();
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        /** @var string|null $subdomain */
        $subdomain = $data['subdomain'] ?? null;

        return is_string($subdomain) ? $subdomain : null;
    }

    /**
     * Store the subdomain result locally.
     *
     * @param SubdomainResult $result The subdomain allocation result.
     *
     * @return void
     */
    private function storeSubdomain(SubdomainResult $result): void
    {
        $path = $this->getSubdomainPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write subdomain config file: ' . $path);
        }

        @chmod($path, 0600);
    }

    /**
     * Clear the subdomain configuration.
     *
     * @return void
     */
    private function clearSubdomain(): void
    {
        $path = $this->getSubdomainPath();
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Get the subdomain config file path.
     *
     * @return string Absolute path to hub-subdomain.json.
     */
    private function getSubdomainPath(): string
    {
        return rtrim($this->configDir, '/') . '/' . self::SUBDOMAIN_CONFIG_FILE;
    }
}
