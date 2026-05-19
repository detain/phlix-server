<?php

namespace Phlex\Dlna;

use Phlex\Common\Logger\LogChannels;
use Phlex\Common\Logger\StructuredLogger;

/**
 * AV Transport Service for DLNA/UPnP Media Renderers.
 *
 * Implements the UPnP AVTransport:1 service specification for controlling
 * media playback on renderers (such as TVs, speakers, or DLNA-compatible devices).
 * Handles Play, Pause, Stop, Seek operations and maintains TransportState instances.
 *
 * The service supports multiple transport instances (0-15), each maintaining
 * independent playback state for different media sessions.
 *
 * @see TransportState For individual instance state management
 * @see UPnP AVTransport:1 Service Specification
 */
class AvTransport
{
    /** Transport state: Playback is stopped */
    public const TRANSPORT_STATE_STOPPED = 'STOPPED';

    /** Transport state: Media is playing */
    public const TRANSPORT_STATE_PLAYING = 'PLAYING';

    /** Transport state: Playback is paused */
    public const TRANSPORT_STATE_PAUSED = 'PAUSED_PLAYING';

    /** Transport state: Transitioning between media items */
    public const TRANSPORT_STATE_TRANSITIONING = 'TRANSITIONING';

    /** Transport state: No media present in renderer */
    public const TRANSPORT_STATE_NO_MEDIA = 'NO_MEDIA_PRESENT';

    /** Transport status: All is well */
    public const TRANSPORT_STATUS_OK = 'OK';

    /** Transport status: An error occurred */
    public const TRANSPORT_STATUS_ERROR = 'ERROR';

    /** Normal playback speed (1x) */
    public const PLAY_SPEED_1 = '1';

    /** Seek mode: Absolute time position (HH:MM:SS) */
    public const SEEK_MODE_ABS_TIME = 'ABS_TIME';

    /** Seek mode: Relative time position (HH:MM:SS) */
    public const SEEK_MODE_REL_TIME = 'REL_TIME';

    /** Seek mode: Absolute track count */
    public const SEEK_MODE_ABS_COUNT = 'ABS_COUNT';

    /** Seek mode: Relative track count */
    public const SEEK_MODE_REL_COUNT = 'REL_COUNT';

    /** Seek mode: Total length of media */
    public const SEEK_MODE_TOTAL_LENGTH = 'TOTAL_LENGTH';

    /** Seek mode: Absolute time seek time */
    public const SEEK_MODE_ABS_TIME_SEEK_TIME = 'ABS_TIME_SEEK_TIME';

    /** Seek mode: Relative time seek time */
    public const SEEK_MODE_REL_TIME_SEEK_TIME = 'REL_TIME_SEEK_TIME';

    /** @var array<int, TransportState> Map of instance ID to TransportState */
    private array $instances = [];

    /** @var StructuredLogger Logger instance for debugging and diagnostics */
    private StructuredLogger $logger;

    /** @var array<int, array<callable>> State change callbacks keyed by instance ID */
    private array $stateChangeCallbacks = [];

    public function __construct(?StructuredLogger $logger = null)
    {
        $this->logger = $logger ?? $this->createDefaultLogger();
    }

    /**
     * Register a state change callback for an instance.
     *
     * The callback receives (instanceId, newState, oldState) when the
     * transport state changes.
     *
     * @param int $instanceId Transport instance ID
     * @param callable $callback Callback function(int $instanceId, string $newState, string $oldState): void
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function onStateChange(int $instanceId, callable $callback): void
    {
        if (!isset($this->stateChangeCallbacks[$instanceId])) {
            $this->stateChangeCallbacks[$instanceId] = [];
        }
        $this->stateChangeCallbacks[$instanceId][] = $callback;
    }

    /**
     * Remove all state change callbacks for an instance.
     *
     * @param int $instanceId Transport instance ID
     *
     * @return void
     *
     * @since 0.12.0
     */
    public function clearStateChangeCallbacks(int $instanceId): void
    {
        unset($this->stateChangeCallbacks[$instanceId]);
    }

    /**
     * Notify state change callbacks.
     *
     * @param int $instanceId Transport instance ID
     * @param string $newState New transport state
     * @param string $oldState Previous transport state
     *
     * @return void
     */
    private function notifyStateChange(int $instanceId, string $newState, string $oldState): void
    {
        if (!isset($this->stateChangeCallbacks[$instanceId])) {
            return;
        }

        foreach ($this->stateChangeCallbacks[$instanceId] as $callback) {
            try {
                $callback($instanceId, $newState, $oldState);
            } catch (\Throwable $e) {
                $this->logger->warning('State change callback error', [
                    'instance_id' => $instanceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create a default logger for standalone/test operation.
     */
    private function createDefaultLogger(): StructuredLogger
    {
        $tempDir = sys_get_temp_dir() . '/phlex_dlna_avt_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $config = [
            'handlers' => [
                'stream' => [
                    'type' => 'stream',
                    'path' => $tempDir . '/avtransport.log',
                    'level' => 'debug',
                ],
            ],
            'processors' => [
                'context' => true,
                'request_id' => false,
                'user_id' => false,
            ],
        ];

        return new StructuredLogger(LogChannels::DLNA, $config);
    }

    /**
     * Get or create a transport instance.
     */
    public function getInstance(int $instanceId = 0): TransportState
    {
        if (!isset($this->instances[$instanceId])) {
            $this->instances[$instanceId] = new TransportState($instanceId);
        }

        return $this->instances[$instanceId];
    }

    /**
     * Set the AV Transport URI (what to play).
     *
     * @param int $instanceId The transport instance ID
     * @param string $currentUri The URI to set
     * @param string $currentUriMetadata DIDL-Lite metadata for the URI
     * @return array<string, mixed> Result with CurrentState
     */
    public function setAvTransportUri(int $instanceId, string $currentUri, string $currentUriMetadata = ''): array
    {
        $this->logger->info('SetAVTransportURI', [
            'instance_id' => $instanceId,
            'uri' => $currentUri,
        ]);

        $instance = $this->getInstance($instanceId);

        // Parse URI metadata if provided
        $metadata = $this->parseUriMetadata($currentUriMetadata);

        $instance->setMediaUri($currentUri);
        $instance->setMediaMetadata($metadata);
        $instance->setTransportState(self::TRANSPORT_STATE_STOPPED);
        $instance->setNrTracks(1);
        $instance->setCurrentTrack(1);

        return [
            'CurrentState' => self::TRANSPORT_STATE_STOPPED,
        ];
    }

    /**
     * Start playback (Play).
     *
     * @param int $instanceId The transport instance ID
     * @param string $speed Playback speed (e.g., "1")
     * @return array<string, mixed> Result with CurrentState
     */
    public function play(int $instanceId, string $speed = self::PLAY_SPEED_1): array
    {
        $this->logger->info('Play', [
            'instance_id' => $instanceId,
            'speed' => $speed,
        ]);

        $instance = $this->getInstance($instanceId);

        if ($instance->getMediaUri() === '') {
            return $this->createErrorResult(702, 'Transport is not set up');
        }

        $oldState = $instance->getTransportState();
        $instance->setTransportState(self::TRANSPORT_STATE_PLAYING);
        $instance->setPlaybackSpeed($speed);
        $instance->setLastChange(time());

        $this->notifyStateChange($instanceId, self::TRANSPORT_STATE_PLAYING, $oldState);

        return [
            'CurrentState' => self::TRANSPORT_STATE_PLAYING,
        ];
    }

    /**
     * Pause playback.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Result with CurrentState
     */
    public function pause(int $instanceId): array
    {
        $this->logger->info('Pause', [
            'instance_id' => $instanceId,
        ]);

        $instance = $this->getInstance($instanceId);

        if ($instance->getMediaUri() === '') {
            return $this->createErrorResult(702, 'Transport is not set up');
        }

        $oldState = $instance->getTransportState();
        $instance->setTransportState(self::TRANSPORT_STATE_PAUSED);
        $instance->setLastChange(time());

        $this->notifyStateChange($instanceId, self::TRANSPORT_STATE_PAUSED, $oldState);

        return [
            'CurrentState' => self::TRANSPORT_STATE_PAUSED,
        ];
    }

    /**
     * Stop playback.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Result with CurrentState
     */
    public function stop(int $instanceId): array
    {
        $this->logger->info('Stop', [
            'instance_id' => $instanceId,
        ]);

        $instance = $this->getInstance($instanceId);

        $oldState = $instance->getTransportState();
        $instance->setTransportState(self::TRANSPORT_STATE_STOPPED);
        $instance->setPosition(0);
        $instance->setLastChange(time());

        $this->notifyStateChange($instanceId, self::TRANSPORT_STATE_STOPPED, $oldState);

        return [
            'CurrentState' => self::TRANSPORT_STATE_STOPPED,
        ];
    }

    /**
     * Seek to a position.
     *
     * @param int $instanceId The transport instance ID
     * @param string $seekMode The seek mode (e.g., REL_TIME, ABS_TIME)
     * @param string $seekTarget The seek target (e.g., "00:05:30" for time)
     * @return array<string, mixed> Result with CurrentState
     */
    public function seek(int $instanceId, string $seekMode, string $seekTarget): array
    {
        $this->logger->info('Seek', [
            'instance_id' => $instanceId,
            'mode' => $seekMode,
            'target' => $seekTarget,
        ]);

        $instance = $this->getInstance($instanceId);

        if ($instance->getMediaUri() === '') {
            return $this->createErrorResult(702, 'Transport is not set up');
        }

        $position = $this->parseTimeToTicks($seekTarget);

        if ($position < 0) {
            return $this->createErrorResult(701, 'Invalid seek target');
        }

        $maxDuration = $instance->getMediaDuration();
        if ($maxDuration > 0 && $position > $maxDuration) {
            $position = $maxDuration;
        }

        $instance->setPosition($position);
        $instance->setLastChange(time());

        return [
            'CurrentState' => $instance->getTransportState(),
        ];
    }

    /**
     * Get current transport info.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Transport information
     */
    public function getTransportInfo(int $instanceId): array
    {
        $instance = $this->getInstance($instanceId);

        return [
            'CurrentTransportState' => $instance->getTransportState(),
            'CurrentTransportStatus' => self::TRANSPORT_STATUS_OK,
            'CurrentSpeed' => $instance->getPlaybackSpeed(),
        ];
    }

    /**
     * Get current position info.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Position information
     */
    public function getPositionInfo(int $instanceId): array
    {
        $instance = $this->getInstance($instanceId);

        $trackDuration = $instance->getMediaDuration();
        $trackUri = $instance->getMediaUri();
        $position = $instance->getPosition();
        $currentTrack = $instance->getCurrentTrack();

        return [
            'Track' => $currentTrack,
            'TrackDuration' => $this->formatTicksToTime($trackDuration),
            'TrackMetaData' => '', // Would contain DIDL for current track
            'TrackURI' => $trackUri,
            'RelTime' => $this->formatTicksToTime($position),
            'AbsTime' => $this->formatTicksToTime($position),
            'RelCount' => $position,
            'AbsCount' => $position,
        ];
    }

    /**
     * Get media info.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Media information
     */
    public function getMediaInfo(int $instanceId): array
    {
        $instance = $this->getInstance($instanceId);

        return [
            'NrTracks' => $instance->getNrTracks(),
            'CurrentURI' => $instance->getMediaUri(),
            'CurrentURIMetaData' => '', // DIDL-Lite metadata
            'NextURI' => '',
            'NextURIMetaData' => '',
            'PlayMedium' => 'NETWORK',
            'RecordMedium' => 'NOT_IMPLEMENTED',
            'WriteStatus' => 'NOT_IMPLEMENTED',
        ];
    }

    /**
     * Get device capabilities.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Capabilities
     */
    public function getDeviceCapabilities(int $instanceId): array
    {
        return [
            'PlayMedia' => 'NETWORK,HDD,USB',
            'RecMedia' => '',
            'RecQualityModes' => '',
        ];
    }

    /**
     * Get transport settings.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Transport settings
     */
    public function getTransportSettings(int $instanceId): array
    {
        $instance = $this->getInstance($instanceId);

        return [
            'PlayMode' => 'NORMAL',
            'RecQualityMode' => 'NOT_IMPLEMENTED',
        ];
    }

    /**
     * Set play mode.
     *
     * @param int $instanceId The transport instance ID
     * @param string $newPlayMode The new play mode
     * @return array<string, mixed> Result
     */
    public function setPlayMode(int $instanceId, string $newPlayMode): array
    {
        $validModes = ['NORMAL', 'REPEAT_ONE', 'REPEAT_ALL', 'SHUFFLE', 'RANDOM'];

        if (!in_array($newPlayMode, $validModes)) {
            return $this->createErrorResult(701, 'Invalid argument');
        }

        $instance = $this->getInstance($instanceId);
        $instance->setPlayMode($newPlayMode);
        $instance->setLastChange(time());

        return [];
    }

    /**
     * Get current transport actions.
     *
     * @param int $instanceId The transport instance ID
     * @return array<string, mixed> Available actions
     */
    public function getCurrentTransportActions(int $instanceId): array
    {
        $instance = $this->getInstance($instanceId);
        $state = $instance->getTransportState();

        $actions = [];

        if ($state === self::TRANSPORT_STATE_STOPPED) {
            $actions = ['Play'];
        } elseif ($state === self::TRANSPORT_STATE_PLAYING) {
            $actions = ['Pause', 'Stop', 'Seek'];
        } elseif ($state === self::TRANSPORT_STATE_PAUSED) {
            $actions = ['Play', 'Stop', 'Seek'];
        }

        return [
            'Actions' => implode(',', $actions),
        ];
    }

    /**
     * Parse DIDL-Lite metadata to extract media info.
     *
     * @return array<string, mixed>
     */
    private function parseUriMetadata(string $metadata): array
    {
        if (empty($metadata)) {
            return [];
        }

        $info = [];

        // Extract title
        if (preg_match('/<dc:title>([^<]+)<\/dc:title>/i', $metadata, $matches)) {
            $info['title'] = htmlspecialchars_decode($matches[1]);
        }

        // Extract duration
        if (preg_match('/<upnp:duration>([^<]+)<\/upnp:duration>/i', $metadata, $matches)) {
            $info['duration'] = $this->parseTimeToTicks($matches[1]);
        }

        // Extract artist
        if (preg_match('/<upnp:artist>([^<]+)<\/upnp:artist>/i', $metadata, $matches)) {
            $info['artist'] = htmlspecialchars_decode($matches[1]);
        }

        // Extract album
        if (preg_match('/<upnp:album>([^<]+)<\/upnp:album>/i', $metadata, $matches)) {
            $info['album'] = htmlspecialchars_decode($matches[1]);
        }

        return $info;
    }

    /**
     * Parse time string (HH:MM:SS) to ticks (100-nanosecond units).
     */
    private function parseTimeToTicks(string $time): int
    {
        // Handle format: HH:MM:SS or H:MM:SS
        $parts = explode(':', $time);

        if (count($parts) !== 3) {
            return -1;
        }

        $hours = (int)$parts[0];
        $minutes = (int)$parts[1];
        $seconds = (int)$parts[2];

        // Convert to seconds, then to ticks (1 second = 10000000 ticks)
        $totalSeconds = $hours * 3600 + $minutes * 60 + $seconds;

        return $totalSeconds * 10000000;
    }

    /**
     * Format ticks to time string (HH:MM:SS).
     */
    private function formatTicksToTime(int $ticks): string
    {
        if ($ticks <= 0) {
            return '00:00:00';
        }

        // Convert ticks to seconds (1 second = 10000000 ticks)
        $totalSeconds = (int)($ticks / 10000000);

        $hours = floor($totalSeconds / 3600);
        $minutes = floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Create an error result.
     *
     * @return array<string, mixed>
     */
    private function createErrorResult(int $code, string $description): array
    {
        $this->logger->warning('AvTransport error', [
            'code' => $code,
            'description' => $description,
        ]);

        return [
            'Error' => ['code' => $code, 'description' => $description],
        ];
    }

    /**
     * Get the SCPD (Service Description) for AVTransport service.
     */
    public function getScpdXml(): string
    {
        return '<?xml version="1.0"?>
<scpd xmlns="urn:schemas-upnp-org:service-1-0">
    <specVersion>
        <major>1</major>
        <minor>0</minor>
    </specVersion>
    <actionList>
        <action>
            <name>SetAVTransportURI</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentURI</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_URI</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentURIMetaData</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_URIMetaData</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>SetNextAVTransportURI</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>NextURI</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_URI</relatedStateVariable>
                </argument>
                <argument>
                    <name>NextURIMetaData</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_URIMetaData</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetMediaInfo</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentURI</name>
                    <direction>out</direction>
                    <relatedStateVariable>AVTransportURI</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentURIMetaData</name>
                    <direction>out</direction>
                    <relatedStateVariable>AVTransportURIMetaData</relatedStateVariable>
                </argument>
                <argument>
                    <name>NextURI</name>
                    <direction>out</direction>
                    <relatedStateVariable>NextAVTransportURI</relatedStateVariable>
                </argument>
                <argument>
                    <name>NextURIMetaData</name>
                    <direction>out</direction>
                    <relatedStateVariable>NextAVTransportURIMetaData</relatedStateVariable>
                </argument>
                <argument>
                    <name>NrTracks</name>
                    <direction>out</direction>
                    <relatedStateVariable>NumberOfTracks</relatedStateVariable>
                </argument>
                <argument>
                    <name>MediaDuration</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentTrackDuration</relatedStateVariable>
                </argument>
                <argument>
                    <name>PlayMedium</name>
                    <direction>out</direction>
                    <relatedStateVariable>PlaybackStorageMedium</relatedStateVariable>
                </argument>
                <argument>
                    <name>RecordMedium</name>
                    <direction>out</direction>
                    <relatedStateVariable>RecordStorageMedium</relatedStateVariable>
                </argument>
                <argument>
                    <name>WriteStatus</name>
                    <direction>out</direction>
                    <relatedStateVariable>RecordMediumWriteStatus</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetTransportInfo</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentTransportState</name>
                    <direction>out</direction>
                    <relatedStateVariable>TransportState</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentTransportStatus</name>
                    <direction>out</direction>
                    <relatedStateVariable>TransportStatus</relatedStateVariable>
                </argument>
                <argument>
                    <name>CurrentSpeed</name>
                    <direction>out</direction>
                    <relatedStateVariable>TransportPlaySpeed</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetPositionInfo</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>Track</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentTrack</relatedStateVariable>
                </argument>
                <argument>
                    <name>TrackDuration</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentTrackDuration</relatedStateVariable>
                </argument>
                <argument>
                    <name>TrackMetaData</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentTrackMetaData</relatedStateVariable>
                </argument>
                <argument>
                    <name>TrackURI</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentTrackURI</relatedStateVariable>
                </argument>
                <argument>
                    <name>RelTime</name>
                    <direction>out</direction>
                    <relatedStateVariable>RelativeTimePosition</relatedStateVariable>
                </argument>
                <argument>
                    <name>AbsTime</name>
                    <direction>out</direction>
                    <relatedStateVariable>AbsoluteTimePosition</relatedStateVariable>
                </argument>
                <argument>
                    <name>RelCount</name>
                    <direction>out</direction>
                    <relatedStateVariable>RelativeCounterPosition</relatedStateVariable>
                </argument>
                <argument>
                    <name>AbsCount</name>
                    <direction>out</direction>
                    <relatedStateVariable>AbsoluteCounterPosition</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetDeviceCapabilities</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>PlayMedia</name>
                    <direction>out</direction>
                    <relatedStateVariable>PossiblePlaybackStorageMedia</relatedStateVariable>
                </argument>
                <argument>
                    <name>RecMedia</name>
                    <direction>out</direction>
                    <relatedStateVariable>PossibleRecordStorageMedia</relatedStateVariable>
                </argument>
                <argument>
                    <name>RecQualityModes</name>
                    <direction>out</direction>
                    <relatedStateVariable>PossibleRecordQualityModes</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetTransportSettings</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>PlayMode</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentPlayMode</relatedStateVariable>
                </argument>
                <argument>
                    <name>RecQualityMode</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentRecordQualityMode</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Stop</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Play</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>Speed</name>
                    <direction>in</direction>
                    <relatedStateVariable>TransportPlaySpeed</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Pause</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Seek</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>Unit</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_SeekMode</relatedStateVariable>
                </argument>
                <argument>
                    <name>Target</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_SeekTarget</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Next</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>Previous</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>SetPlayMode</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>NewPlayMode</name>
                    <direction>in</direction>
                    <relatedStateVariable>CurrentPlayMode</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
        <action>
            <name>GetCurrentTransportActions</name>
            <argumentList>
                <argument>
                    <name>InstanceID</name>
                    <direction>in</direction>
                    <relatedStateVariable>A_ARG_TYPE_InstanceID</relatedStateVariable>
                </argument>
                <argument>
                    <name>Actions</name>
                    <direction>out</direction>
                    <relatedStateVariable>CurrentTransportActions</relatedStateVariable>
                </argument>
            </argumentList>
        </action>
    </actionList>
    <serviceStateTable>
        <stateVariable sendEvents="yes">
            <name>TransportState</name>
            <dataType>string</dataType>
            <allowedValueList>
                <allowedValue>STOPPED</allowedValue>
                <allowedValue>PLAYING</allowedValue>
                <allowedValue>TRANSITIONING</allowedValue>
                <allowedValue>PAUSED_PLAYING</allowedValue>
                <allowedValue>PAUSED_RECORDING</allowedValue>
                <allowedValue>RECORDING</allowedValue>
                <allowedValue>NO_MEDIA_PRESENT</allowedValue>
            </allowedValueList>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>TransportStatus</name>
            <dataType>string</dataType>
            <allowedValueList>
                <allowedValue>OK</allowedValue>
                <allowedValue>ERROR</allowedValue>
            </allowedValueList>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>PlaybackStorageMedium</name>
            <dataType>string</dataType>
            <allowedValueList>
                <allowedValue>NETWORK</allowedValue>
                <allowedValue>HDD</allowedValue>
                <allowedValue>USB</allowedValue>
            </allowedValueList>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>CurrentPlayMode</name>
            <dataType>string</dataType>
            <allowedValueList>
                <allowedValue>NORMAL</allowedValue>
                <allowedValue>SHUFFLE</allowedValue>
                <allowedValue>REPEAT_ONE</allowedValue>
                <allowedValue>REPEAT_ALL</allowedValue>
                <allowedValue>RANDOM</allowedValue>
                <allowedValue>DIRECT_1</allowedValue>
            </allowedValueList>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>CurrentSpeed</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>CurrentTrack</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>CurrentTrackDuration</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>CurrentTrackURI</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>CurrentTrackMetaData</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>AVTransportURI</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>AVTransportURIMetaData</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>NextAVTransportURI</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>NextAVTransportURIMetaData</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>NumberOfTracks</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>RelativeTimePosition</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>AbsoluteTimePosition</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>RelativeCounterPosition</name>
            <dataType>i4</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>AbsoluteCounterPosition</name>
            <dataType>i4</dataType>
        </stateVariable>
        <stateVariable sendEvents="yes">
            <name>CurrentTransportActions</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_InstanceID</name>
            <dataType>ui4</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_URI</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_URIMetaData</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_SeekMode</name>
            <dataType>string</dataType>
            <allowedValueList>
                <allowedValue>ABS_TIME</allowedValue>
                <allowedValue>REL_TIME</allowedValue>
                <allowedValue>ABS_COUNT</allowedValue>
                <allowedValue>REL_COUNT</allowedValue>
                <allowedValue>TRACK_NR</allowedValue>
                <allowedValue>CHANNEL_FREQ</allowedValue>
                <allowedValue>TAPE_INDEX</allowedValue>
                <allowedValue>FRAME_NR</allowedValue>
            </allowedValueList>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>A_ARG_TYPE_SeekTarget</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>RecordMediumWriteStatus</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>RecordStorageMedium</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>CurrentRecordQualityMode</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>PossiblePlaybackStorageMedia</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>PossibleRecordStorageMedia</name>
            <dataType>string</dataType>
        </stateVariable>
        <stateVariable sendEvents="no">
            <name>PossibleRecordQualityModes</name>
            <dataType>string</dataType>
        </stateVariable>
    </serviceStateTable>
</scpd>';
    }
}
