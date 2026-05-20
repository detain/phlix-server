<?php

declare(strict_types=1);

namespace Phlix\AirPlay;

use Phlix\Common\Logger\StructuredLogger;

/**
 * RAOP (Real-Time Audio Protocol) client for AirPlay audio streaming.
 *
 * RAOP uses RTSP over HTTP (port 7000) for control commands:
 * - ANNOUNCE: Set up stream with audio format and encryption info
 * - RECORD: Start recording/streaming audio
 * - FLUSH: Pause or reset playback
 * - TEARDOWN: Close the session
 *
 * @since 0.12.0
 */
class RaopClient
{
    /** @var string Device hostname or IP */
    private string $deviceHost;

    /** @var int Device RAOP port */
    private int $deviceRaopPort;

    /** @var StructuredLogger|null Optional logger */
    private ?StructuredLogger $logger;

    /**
     * @param string            $deviceHost   Device hostname or IP
     * @param int               $deviceRaopPort RAOP port (from _raop._tcp.local)
     * @param StructuredLogger|null $logger   Optional logger
     */
    public function __construct(
        string $deviceHost,
        int $deviceRaopPort,
        ?StructuredLogger $logger = null,
    ) {
        $this->deviceHost = $deviceHost;
        $this->deviceRaopPort = $deviceRaopPort;
        $this->logger = $logger;
    }

    /**
     * Build the ANNOUNCE payload for stream setup.
     *
     * Includes the Apple-Challenge and audio format parameters.
     * Uses plain RTP for unencrypted streaming (no FairPlay DRM).
     *
     * @param string $audioUrl    HLS or direct audio URL
     * @param string $contentType MIME type (e.g., 'audio/mp4', 'audio/aac')
     * @param int    $duration   Content duration in seconds (0 if unknown)
     *
     * @return string ANNOUNCE request body
     *
     * @since 0.12.0
     */
    public function buildAnnouncePayload(string $audioUrl, string $contentType, int $duration): string
    {
        $appInfo = $this->buildAppleChallenge();

        $body = sprintf(
            "ANNOUNCE * RTSP/1.0\r\n" .
            "Content-Type: application/sdp\r\n" .
            "Apple-Challenge: %s\r\n" .
            "Client-Info: <php>\r\n\r\n" .
            "v=0\r\n" .
            "o=- %d 0 IN IP4 %s\r\n" .
            "s=Phlix AirPlay\r\n" .
            "c=IN IP4 %s\r\n" .
            "t=0 0\r\n" .
            "m=audio 0 RTP/AVP 96\r\n" .
            "a=rtpmap:96 mpeg4-generic/%d\r\n" .
            "a=fmtp:96 streamtype=5; profile-level-id=64; config=%s\r\n",
            base64_encode($appInfo),
            time(),
            $this->deviceHost,
            $this->deviceHost,
            44100,
            $this->buildAudioConfig($contentType)
        );

        return $body;
    }

    /**
     * Send FLUSH command to reset playback position.
     *
     * @param int $rtpTime RTP timestamp to flush to (0 = beginning)
     *
     * @return array<string, mixed> Response data including CSeq and session
     *
     * @since 0.12.0
     */
    public function flush(int $rtpTime): array
    {
        $cseq = $this->sendRtspCommand('FLUSH', [], $rtpTime);

        return [
            'cseq' => $cseq,
            'rtp_time' => $rtpTime,
            'status' => 'flushed',
        ];
    }

    /**
     * Get RTP sync information from the device.
     *
     * @return array<string, mixed> RTP info including port and latency
     *
     * @since 0.12.0
     */
    public function getRtpInfo(): array
    {
        $cseq = $this->sendRtspCommand('GET_PARAMETER', ['RTP-Info' => 'rtsp://localhost/stream']);

        return [
            'cseq' => $cseq,
            'latency_ms' => $this->getLatency(),
            'device_host' => $this->deviceHost,
        ];
    }

    /**
     * Get the current playback latency.
     *
     * @return int Latency in milliseconds
     *
     * @since 0.12.0
     */
    public function getLatency(): int
    {
        // Default latency for AirPlay devices is typically around 220ms
        // This can be negotiated during ANNOUNCE
        return 220;
    }

    /**
     * Send an RTSP command to the device.
     *
     * @param string $method   RTSP method (ANNOUNCE, RECORD, FLUSH, TEARDOWN)
     * @param array<string, string> $headers Additional headers
     * @param int    $rtpTime  RTP timestamp for timing-sensitive commands
     *
     * @return int CSeq number from response
     */
    private function sendRtspCommand(string $method, array $headers = [], int $rtpTime = 0): int
    {
        $cseq = $this->getNextCSeq();
        $sessionId = $this->getSessionId();

        $request = "{$method} * RTSP/1.0\r\n" .
            "CSeq: {$cseq}\r\n" .
            "Apple-Challenge: " . base64_encode($this->buildAppleChallenge()) . "\r\n" .
            "Client-Info: <php>\r\n";

        if ($sessionId !== '') {
            $request .= "Session: {$sessionId}\r\n";
        }

        foreach ($headers as $key => $value) {
            $request .= "{$key}: {$value}\r\n";
        }

        if ($rtpTime > 0) {
            $request .= "RTP-Info: seq={$rtpTime};rtptime={$rtpTime}\r\n";
        }

        $request .= "\r\n";

        $response = $this->sendRaw($request);

        return $this->parseCSeq($response);
    }

    /**
     * Send raw data to the device.
     *
     * @param string $data Raw request data
     *
     * @return string Response data
     */
    private function sendRaw(string $data): string
    {
        $host = $this->deviceHost;
        $port = $this->deviceRaopPort;

        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($socket === false) {
            $this->logger?->warning('RAOP: Failed to connect', [
                'host' => $host,
                'port' => $port,
                'error' => $errstr,
            ]);
            return '';
        }

        fwrite($socket, $data);
        $response = fread($socket, 4096);
        fclose($socket);

        return $response ?: '';
    }

    /**
     * Build Apple-Challenge payload for authentication.
     *
     * @return string 32-byte challenge data
     *
     * @since 0.12.0
     */
    private function buildAppleChallenge(): string
    {
        // Generate random challenge bytes (simplified - real implementation
        // uses ED25519 signature based on device certificate)
        $challenge = random_bytes(32);
        return $challenge;
    }

    /**
     * Build audio configuration string for SDP.
     *
     * @param string $contentType MIME content type
     *
     * @return string Audio configuration
     *
     * @since 0.12.0
     */
    private function buildAudioConfig(string $contentType): string
    {
        // For AAC-LC audio: profile-level-id=64, streamtype=5
        // This is a simplified config - real implementation varies by codec
        if (str_contains($contentType, 'mp4') || str_contains($contentType, 'aac')) {
            return 'mp4a.40.2'; // AAC-LC
        }

        return 'mp4a.40.5'; // HE-AAC
    }

    /** @var int CSeq counter */
    private int $cseq = 0;

    /** @var string|null Active session ID */
    private ?string $sessionId = null;

    /**
     * Get next CSeq number.
     *
     * @return int
     */
    private function getNextCSeq(): int
    {
        return ++$this->cseq;
    }

    /**
     * Get the current session ID.
     *
     * @return string Session ID or empty string if not set
     */
    private function getSessionId(): string
    {
        return $this->sessionId ?? '';
    }

    /**
     * Set the session ID after ANNOUNCE response.
     *
     * @param string $sessionId Session ID to set
     *
     * @return void
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Parse CSeq from RTSP response.
     *
     * @param string $response RTSP response
     *
     * @return int CSeq number, 0 if not found
     */
    private function parseCSeq(string $response): int
    {
        if (preg_match('/CSeq:\s*(\d+)/i', $response, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }
}
