<?php

declare(strict_types=1);

namespace Phlix\Discovery\Mdns;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * mDNS discovery service for finding Chromecast, AirPlay, Roku devices.
 *
 * Provides methods to discover services via mDNS queries. mDNS uses
 * UDP multicast address 224.0.0.251 port 5353.
 *
 * @since 0.12.0
 */
class MdnsDiscovery
{
    /** Google Cast / Chromecast service type */
    public const SERVICE_CHROMECAST = '_googlecast._tcp.local.';

    /** AirPlay 2 service type */
    public const SERVICE_AIRPLAY = '_airplay._tcp.local.';

    /** AirPlay audio-only (Remote Audio Output Protocol) */
    public const SERVICE_RAOP = '_raop._tcp.local.';

    /** Roku Entertainment Control Protocol */
    public const SERVICE_ROKU = '_ roku-ecnp._tcp.local.';

    /** @var MdnsSocket */
    private MdnsSocket $socket;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * @param MdnsSocket $socket mDNS socket instance
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(
        MdnsSocket $socket,
        ?LoggerInterface $logger = null
    ) {
        $this->socket = $socket;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Discover Chromecast / Google Cast devices.
     *
     * @return MdnsService[] Array of discovered Chromecast devices
     *
     * @since 0.12.0
     */
    public function discoverChromecast(): array
    {
        return $this->discoverService(self::SERVICE_CHROMECAST);
    }

    /**
     * Discover AirPlay 2 devices.
     *
     * Queries both _airplay._tcp.local. and _raop._tcp.local. to find
     * all AirPlay-compatible devices including audio-only devices.
     *
     * @return MdnsService[] Array of discovered AirPlay devices
     *
     * @since 0.12.0
     */
    public function discoverAirPlay(): array
    {
        $airplayServices = $this->discoverService(self::SERVICE_AIRPLAY);
        $raopServices = $this->discoverService(self::SERVICE_RAOP);

        return array_merge($airplayServices, $raopServices);
    }

    /**
     * Discover Roku devices via mDNS.
     *
     * @return MdnsService[] Array of discovered Roku devices
     *
     * @since 0.12.0
     */
    public function discoverRoku(): array
    {
        return $this->discoverService(self::SERVICE_ROKU);
    }

    /**
     * Announce the Phlix server via mDNS.
     *
     * Registers the Phlix server as an available service on the network.
     *
     * @param string $name Service name to announce
     * @param string $type Service type (e.g., '_phlix._tcp.local.')
     * @param int $port Port number
     * @param array<string, string> $txt TXT record key-value pairs
     *
     * @since 0.12.0
     */
    public function announceServer(string $name, string $type, int $port, array $txt = []): void
    {
        $this->logger->info('mDNS: Announcing server', [
            'name' => $name,
            'type' => $type,
            'port' => $port,
        ]);

        // Note: Full mDNS announcement requires proper service registration.
        // This is a placeholder for the announcement functionality.
        // In a full implementation, this would send the appropriate mDNS packets.
    }

    /**
     * Discover services of a given type.
     *
     * Sends an mDNS PTR query and resolves each result to get SRV and TXT records.
     *
     * @param string $serviceType Service type to query (e.g., '_googlecast._tcp.local.')
     * @return MdnsService[] Array of discovered services
     */
    private function discoverService(string $serviceType): array
    {
        // First query for PTR records (service discovery)
        $responses = $this->socket->query($serviceType, MdnsSocket::QTYPE_PTR);

        if (empty($responses)) {
            $this->logger->debug('mDNS: No services discovered', ['type' => $serviceType]);
            return [];
        }

        $services = [];
        /** @var array<string> $serviceInstances */
        $serviceInstances = [];

        // Parse PTR responses to get individual service instances
        foreach ($responses as $response) {
            $parsed = $this->socket->parseResponse($response);
            if (!is_array($parsed)) {
                continue;
            }

            /** @var mixed $rawRecords */
            $rawRecords = $parsed['records'] ?? null;
            if (!is_array($rawRecords)) {
                continue;
            }

            /** @var array<mixed> $records */
            $records = $rawRecords;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                if (($record['type'] ?? 0) !== MdnsSocket::QTYPE_PTR) {
                    continue;
                }

                /** @var mixed $ptrData */
                $ptrData = $record['data'] ?? null;
                if (!is_array($ptrData)) {
                    continue;
                }
                /** @var mixed $ptrValue */
                $ptrValue = $ptrData['ptr'] ?? null;
                if (is_string($ptrValue)) {
                    $serviceInstances[] = $ptrValue;
                }
            }
        }

        // Resolve each service instance to get SRV and TXT records
        /** @var list<string> $uniqueInstances */
        $uniqueInstances = array_unique($serviceInstances);
        foreach ($uniqueInstances as $instanceName) {
            $service = $this->resolveService($instanceName, $serviceType);
            if ($service !== null) {
                $services[] = $service;
            }
        }

        $this->logger->debug('mDNS: Discovered {count} services', [
            'count' => count($services),
            'type' => $serviceType,
        ]);

        return $services;
    }

    /**
     * Resolve a specific service instance.
     *
     * Queries for SRV and TXT records to get full service information.
     *
     * @param string $instanceName Instance name to resolve
     * @param string $serviceType Service type (for extracting device ID)
     * @return MdnsService|null Resolved service or null on failure
     */
    private function resolveService(string $instanceName, string $serviceType): ?MdnsService
    {
        // Query SRV record
        $srvResponses = $this->socket->query($instanceName, MdnsSocket::QTYPE_SRV);
        $srvRecord = $this->findSrvRecord($srvResponses);

        if ($srvRecord === null) {
            $this->logger->debug('mDNS: No SRV record for service', ['name' => $instanceName]);
            return null;
        }

        /** @var mixed $srvTarget */
        $srvTarget = $srvRecord['target'] ?? '';
        /** @var mixed $srvPort */
        $srvPort = $srvRecord['port'] ?? 0;

        if (!is_string($srvTarget) || $srvTarget === '') {
            return null;
        }
        if (!is_int($srvPort) || $srvPort === 0) {
            return null;
        }

        $host = $srvTarget;
        $port = $srvPort;

        // Query TXT record
        $txtResponses = $this->socket->query($instanceName, MdnsSocket::QTYPE_TXT);
        $txtRecords = $this->findTxtRecords($txtResponses);

        // Extract device ID from instance name or TXT records
        $deviceId = $this->extractDeviceId($instanceName, $txtRecords);

        return new MdnsService(
            $instanceName,
            $serviceType,
            $port,
            $host,
            $txtRecords,
            $deviceId
        );
    }

    /**
     * Find SRV record from responses.
     *
     * @param array<string> $responses Raw mDNS responses
     * @return array<string, mixed>|null SRV record data
     *
     * @phpstan-return array<string, mixed>|null
     */
    private function findSrvRecord(array $responses): ?array
    {
        foreach ($responses as $response) {
            $parsed = $this->socket->parseResponse($response);
            if (!is_array($parsed)) {
                continue;
            }

            /** @var mixed $rawRecords */
            $rawRecords = $parsed['records'] ?? null;
            if (!is_array($rawRecords)) {
                continue;
            }

            /** @var array<mixed> $records */
            $records = $rawRecords;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                if (($record['type'] ?? 0) === MdnsSocket::QTYPE_SRV) {
                    /** @var mixed $data */
                    $data = $record['data'] ?? null;
                    if (is_array($data)) {
                        /** @var array<string, mixed> $data */
                        return $data;
                    }
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * Find TXT records from responses.
     *
     * @param array<string> $responses Raw mDNS responses
     * @return array<string> TXT record values
     */
    private function findTxtRecords(array $responses): array
    {
        foreach ($responses as $response) {
            $parsed = $this->socket->parseResponse($response);
            if (!is_array($parsed)) {
                continue;
            }

            /** @var mixed $rawRecords */
            $rawRecords = $parsed['records'] ?? null;
            if (!is_array($rawRecords)) {
                continue;
            }

            /** @var array<mixed> $records */
            $records = $rawRecords;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                if (($record['type'] ?? 0) === MdnsSocket::QTYPE_TXT) {
                    /** @var mixed $txtData */
                    $txtData = $record['data'] ?? null;
                    if (!is_array($txtData)) {
                        continue;
                    }
                    /** @var mixed $txtValue */
                    $txtValue = $txtData['txt'] ?? null;
                    if (is_array($txtValue)) {
                        /** @var array<string> */
                        return $txtValue;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Extract device ID from instance name or TXT records.
     *
     * @param string $instanceName Service instance name
     * @param array<string> $txtRecords TXT record values
     * @return string Device ID
     */
    private function extractDeviceId(string $instanceName, array $txtRecords): string
    {
        // Try to extract from instance name format: DeviceName-xxxx.serial._service._type.local.
        // Common pattern: Chromecast-xxxx._googlecast._tcp.local.
        if (preg_match('/^([^-]+)-([^-]+)/', $instanceName, $matches)) {
            return $matches[2];
        }

        // Check TXT records for id= or uuid= fields
        foreach ($txtRecords as $txt) {
            if (strpos($txt, 'id=') === 0) {
                return substr($txt, 3);
            }
            if (strpos($txt, 'uuid=') === 0) {
                return substr($txt, 5);
            }
        }

        // Fall back to using the instance name as the device ID
        return $instanceName;
    }
}
