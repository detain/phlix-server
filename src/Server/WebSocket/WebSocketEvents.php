<?php

declare(strict_types=1);

namespace Phlex\Server\WebSocket;

/**
 * WebSocket event type constants.
 *
 * This class defines all available WebSocket event types used for
 * real-time communication between server and clients.
 *
 * @author Phlex Media Server Team
 * @version 1.0.0
 * @description Defines all WebSocket event type constants for real-time communication.
 *
 * @see WebSocketServer For event handling
 * @see MessageHandler For message routing
 */
final class WebSocketEvents
{
    /*
    |--------------------------------------------------------------------------
    | Connection Events
    |--------------------------------------------------------------------------
    */

    /** @var string Fired when a client successfully connects */
    public const CONNECTED = 'connected';

    /** @var string Fired when a client disconnects from the server */
    public const DISCONNECTED = 'disconnected';

    /** @var string Broadcast when an authenticated client disconnects */
    public const CLIENT_DISCONNECTED = 'client_disconnected';

    /*
    |--------------------------------------------------------------------------
    | Authentication Events
    |--------------------------------------------------------------------------
    */

    /** @var string Client requests authentication */
    public const AUTH_REQUEST = 'auth_request';

    /** @var string Authentication was successful */
    public const AUTH_SUCCESS = 'auth_success';

    /** @var string Authentication failed */
    public const AUTH_FAILURE = 'auth_failure';

    /*
    |--------------------------------------------------------------------------
    | Session Events
    |--------------------------------------------------------------------------
    */

    /** @var string A new playback session has started */
    public const SESSION_START = 'session_start';

    /** @var string A playback session has ended */
    public const SESSION_END = 'session_end';

    /** @var string Client joined a session */
    public const SESSION_JOIN = 'session_join';

    /** @var string Client left a session */
    public const SESSION_LEAVE = 'session_leave';

    /*
    |--------------------------------------------------------------------------
    | Playback Events
    |--------------------------------------------------------------------------
    */

    /** @var string Playback started */
    public const PLAYBACK_START = 'playback_start';

    /** @var string Playback paused */
    public const PLAYBACK_PAUSE = 'playback_pause';

    /** @var string Playback stopped */
    public const PLAYBACK_STOP = 'playback_stop';

    /** @var string Playback progress update */
    public const PLAYBACK_PROGRESS = 'playback_progress';

    /** @var string Playback seek performed */
    public const PLAYBACK_SEEK = 'playback_seek';

    /*
    |--------------------------------------------------------------------------
    | SyncPlay Events
    |--------------------------------------------------------------------------
    */

    /** @var string Request to create a SyncPlay group */
    public const SYNCPLAY_CREATE_GROUP = 'syncplay_create_group';

    /** @var string Request to join a SyncPlay group */
    public const SYNCPLAY_JOIN_GROUP = 'syncplay_join_group';

    /** @var string Request to leave a SyncPlay group */
    public const SYNCPLAY_LEAVE_GROUP = 'syncplay_leave_group';

    /** @var string SyncPlay state synchronization */
    public const SYNCPLAY_SYNC_STATE = 'syncplay_sync_state';

    /** @var string SyncPlay sync request */
    public const SYNCPLAY_SYNC_REQUEST = 'syncplay_sync_request';

    /*
    |--------------------------------------------------------------------------
    | General Events
    |--------------------------------------------------------------------------
    */

    /** @var string General error event */
    public const ERROR = 'error';

    /** @var string Ping request */
    public const PING = 'ping';

    /** @var string Pong response */
    public const PONG = 'pong';

    /** @var string General notification */
    public const NOTIFICATION = 'notification';

    /** @var string Library content has been updated */
    public const LIBRARY_UPDATED = 'library_updated';

    /*
    |--------------------------------------------------------------------------
    | Dashboard Events
    |--------------------------------------------------------------------------
    */

    /** @var string Dashboard now playing update broadcast */
    public const DASHBOARD_NOW_PLAYING = 'dashboard_now_playing';

    /**
     * Private constructor to prevent instantiation.
     *
     * This class only contains constants and should not be instantiated.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
        // Prevent instantiation - constants only
    }
}
