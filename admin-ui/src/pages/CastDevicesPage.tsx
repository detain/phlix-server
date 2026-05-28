/**
 * CastDevicesPage — admin Cast Devices feature page (2.1).
 *
 * Displays discovered Cast (Chromecast), AirPlay, Roku, and DLNA devices
 * in a tabbed interface. Each tab lists devices and allows the admin to
 * inspect playback state and issue transport controls (play/pause/seek/stop)
 * appropriate to the device's capabilities:
 *   - Chromecast & DLNA: play, pause, seek, stop
 *   - AirPlay: play, pause, stop  (no seek)
 *   - Roku: stop only
 *
 * @since 2.1
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import { CastApi, type CastDevice, type CastPlaybackState } from '../api/cast';
import { AirPlayApi, type AirPlayDevice, type AirPlayPlaybackState } from '../api/airplay';
import { RokuApi, type RokuDevice, type RokuPlaybackState } from '../api/roku';
import { DlnaApi, type DlnaDevice, type DlnaPlaybackState } from '../api/dlna';
import { useToast } from '../components/Toast';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type TabId = 'chromecast' | 'airplay' | 'roku' | 'dlna';

interface Tab {
  id: TabId;
  label: string;
}

interface TransportState {
  isPlaying: boolean;
  position: number | null;
  duration: number | null;
  mediaTitle: string;
  deviceId: string;
}

const TABS: readonly Tab[] = [
  { id: 'chromecast', label: 'Chromecast' },
  { id: 'airplay', label: 'AirPlay' },
  { id: 'roku', label: 'Roku' },
  { id: 'dlna', label: 'DLNA' },
] as const;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Format seconds as "H:MM:SS" or "M:SS". */
function formatTime(seconds: number | null): string {
  if (seconds === null) return '--:--';
  const s = Math.floor(seconds);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) {
    return `${h}:${String(m).padStart(2, '0')}:${String(sec).padStart(2, '0')}`;
  }
  return `${m}:${String(sec).padStart(2, '0')}`;
}

/** Device type icon. */
function deviceIcon(tabId: TabId): string {
  switch (tabId) {
    case 'chromecast': return '📺';
    case 'airplay': return '📱';
    case 'roku': return '🖥️';
    case 'dlna': return '📽️';
  }
}

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function DeviceCardSkeleton(): JSX.Element {
  return (
    <div className="device-card" aria-hidden="true">
      <div className="device-card__icon" />
      <div className="device-card__info">
        <div className="skeleton-line" style={{ width: '60%', marginBottom: 4 }} />
        <div className="skeleton-line" style={{ width: '40%' }} />
      </div>
    </div>
  );
}

interface DeviceCardProps {
  tabId: TabId;
  deviceId: string;
  name: string;
  model: string;
  host: string;
  onSelect: (id: string) => void;
  isSelected: boolean;
}

function DeviceCard({ tabId, deviceId, name, model, host, onSelect, isSelected }: DeviceCardProps): JSX.Element {
  return (
    <button
      type="button"
      className={`device-card${isSelected ? ' device-card--selected' : ''}`}
      onClick={() => onSelect(deviceId)}
      aria-pressed={isSelected}
      aria-label={`Select ${name}`}
    >
      <span className="device-card__icon" aria-hidden="true">{deviceIcon(tabId)}</span>
      <div className="device-card__info">
        <span className="device-card__name" title={name}>{name}</span>
        <span className="device-card__model" title={`${model} — ${host}`}>{model}</span>
      </div>
    </button>
  );
}

interface SeekBarProps {
  position: number | null;
  duration: number | null;
  onSeek: (position: number) => void;
  disabled: boolean;
}

function SeekBar({ position, duration, onSeek, disabled }: SeekBarProps): JSX.Element {
  return (
    <div className="seek-bar" role="group" aria-label="Seek">
      <span className="seek-bar__time">{formatTime(position)}</span>
      <input
        type="range"
        min={0}
        max={duration ?? 100}
        value={position ?? 0}
        onChange={(e) => onSeek(Number(e.target.value))}
        disabled={disabled || duration === null}
        aria-label="Seek position"
      />
      <span className="seek-bar__time">{formatTime(duration)}</span>
    </div>
  );
}

interface TransportControlsProps {
  tabId: TabId;
  deviceName: string;
  state: TransportState | null;
  loading: boolean;
  acting: boolean;
  onPlay: () => void;
  onPause: () => void;
  onStop: () => void;
  onSeek: (position: number) => void;
}

function TransportControls({
  tabId,
  deviceName,
  state,
  loading,
  acting,
  onPlay,
  onPause,
  onStop,
  onSeek,
}: TransportControlsProps): JSX.Element {
  if (loading) {
    return (
      <div className="cast-session__player" aria-live="polite">
        <p role="status">Loading playback state…</p>
      </div>
    );
  }

  if (!state) {
    return (
      <div className="cast-session__player">
        <p className="text-muted">Select a device to view playback controls.</p>
      </div>
    );
  }

  const hasSeek = tabId === 'chromecast' || tabId === 'dlna';

  return (
    <div className="cast-session__player">
      <div className="transport-controls">
        <div className="transport-controls__header">
          <p className="transport-controls__device">{state.mediaTitle || 'No media'}</p>
          <p className="transport-controls__note">
            {state.isPlaying ? 'Playing' : 'Paused'} on {deviceName}
          </p>
        </div>

        {hasSeek && state.duration !== null && (
          <SeekBar
            position={state.position}
            duration={state.duration}
            onSeek={onSeek}
            disabled={acting}
          />
        )}

        <div className="transport-buttons">
          {tabId !== 'roku' && (
            <>
              <button
                type="button"
                className="btn btn--primary btn--sm"
                onClick={onPlay}
                disabled={state.isPlaying || acting}
                aria-label="Play"
              >
                ▶ Play
              </button>
              <button
                type="button"
                className="btn btn--secondary btn--sm"
                onClick={onPause}
                disabled={!state.isPlaying || acting}
                aria-label="Pause"
              >
                ⏸ Pause
              </button>
            </>
          )}
          <button
            type="button"
            className="btn btn--secondary btn--sm"
            onClick={onStop}
            disabled={acting}
            aria-label="Stop"
          >
            ⏹ Stop
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export interface CastDevicesPageProps {
  client: ApiClient;
}

export function CastDevicesPage({ client }: CastDevicesPageProps): JSX.Element {
  const castApiRef = useRef(new CastApi(client));
  const airplayApiRef = useRef(new AirPlayApi(client));
  const rokuApiRef = useRef(new RokuApi(client));
  const dlnaApiRef = useRef(new DlnaApi(client));

  const { push: pushToast } = useToast();

  // Tab state
  const [activeTab, setActiveTab] = useState<TabId>('chromecast');

  // Device list state per tab
  const [castDevices, setCastDevices] = useState<CastDevice[]>([]);
  const [airplayDevices, setAirplayDevices] = useState<AirPlayDevice[]>([]);
  const [rokuDevices, setRokuDevices] = useState<RokuDevice[]>([]);
  const [dlnaDevices, setDlnaDevices] = useState<DlnaDevice[]>([]);

  // Loading states per tab
  const [loadingCast, setLoadingCast] = useState(true);
  const [loadingAirPlay, setLoadingAirPlay] = useState(true);
  const [loadingRoku, setLoadingRoku] = useState(true);
  const [loadingDlna, setLoadingDlna] = useState(true);

  // Selected device state
  const [selectedDeviceId, setSelectedDeviceId] = useState<string | null>(null);
  const [transportState, setTransportState] = useState<TransportState | null>(null);
  const [loadingTransport, setLoadingTransport] = useState(false);

  // Action-in-progress flags
  const [acting, setActing] = useState(false);

  // ---------------------------------------------------------------------------
  // Fetch device lists
  // ---------------------------------------------------------------------------

  const fetchCastDevices = useCallback(async () => {
    setLoadingCast(true);
    try {
      const devices = await castApiRef.current.listDevices();
      setCastDevices(devices);
    } catch (err) {
      pushToast('Failed to load Chromecast devices', 'error');
    } finally {
      setLoadingCast(false);
    }
  }, [pushToast]);

  const fetchAirPlayDevices = useCallback(async () => {
    setLoadingAirPlay(true);
    try {
      const devices = await airplayApiRef.current.listDevices();
      setAirplayDevices(devices);
    } catch (err) {
      pushToast('Failed to load AirPlay devices', 'error');
    } finally {
      setLoadingAirPlay(false);
    }
  }, [pushToast]);

  const fetchRokuDevices = useCallback(async () => {
    setLoadingRoku(true);
    try {
      const devices = await rokuApiRef.current.listDevices();
      setRokuDevices(devices);
    } catch (err) {
      pushToast('Failed to load Roku devices', 'error');
    } finally {
      setLoadingRoku(false);
    }
  }, [pushToast]);

  const fetchDlnaDevices = useCallback(async () => {
    setLoadingDlna(true);
    try {
      const devices = await dlnaApiRef.current.listDevices();
      setDlnaDevices(devices);
    } catch (err) {
      pushToast('Failed to load DLNA devices', 'error');
    } finally {
      setLoadingDlna(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void fetchCastDevices();
    void fetchAirPlayDevices();
    void fetchRokuDevices();
    void fetchDlnaDevices();
  }, [fetchCastDevices, fetchAirPlayDevices, fetchRokuDevices, fetchDlnaDevices]);

  // ---------------------------------------------------------------------------
  // Fetch transport state when device is selected
  // ---------------------------------------------------------------------------

  const fetchTransportState = useCallback(async (tabId: TabId, deviceId: string) => {
    setLoadingTransport(true);
    setTransportState(null);
    try {
      let state: CastPlaybackState | AirPlayPlaybackState | RokuPlaybackState | DlnaPlaybackState;
      switch (tabId) {
        case 'chromecast':
          state = await castApiRef.current.getStatus(deviceId);
          break;
        case 'airplay':
          state = await airplayApiRef.current.getStatus(deviceId);
          break;
        case 'roku':
          state = await rokuApiRef.current.getStatus(deviceId);
          break;
        case 'dlna':
          state = await dlnaApiRef.current.getStatus(deviceId);
          break;
      }
      // Cast to the type that has position_seconds/duration_seconds
      // (CastPlaybackState and DlnaPlaybackState; AirPlay and Roku do not).
      const ext = state as CastPlaybackState | DlnaPlaybackState;
      setTransportState({
        isPlaying: state.transport_state === 'PLAYING',
        position: ext.position_seconds,
        duration: ext.duration_seconds,
        mediaTitle: state.media_title,
        deviceId: state.device_id,
      });
    } catch {
      pushToast('Failed to load playback state', 'error');
    } finally {
      setLoadingTransport(false);
    }
  }, [pushToast]);

  // ---------------------------------------------------------------------------
  // Handle device selection
  // ---------------------------------------------------------------------------

  const handleSelectDevice = useCallback((deviceId: string) => {
    setSelectedDeviceId(deviceId);
    void fetchTransportState(activeTab, deviceId);
  }, [activeTab, fetchTransportState]);

  // Re-fetch transport state when tab changes
  const handleTabChange = useCallback((tabId: TabId) => {
    setActiveTab(tabId);
    setSelectedDeviceId(null);
    setTransportState(null);
  }, []);

  // Re-fetch transport state when selected device changes
  useEffect(() => {
    if (selectedDeviceId) {
      void fetchTransportState(activeTab, selectedDeviceId);
    }
  }, [activeTab, selectedDeviceId, fetchTransportState]);

  // ---------------------------------------------------------------------------
  // Transport actions
  // ---------------------------------------------------------------------------

  const handlePlay = useCallback(async () => {
    if (!selectedDeviceId) return;
    setActing(true);
    try {
      let result;
      switch (activeTab) {
        case 'chromecast':
          result = await castApiRef.current.play(selectedDeviceId);
          break;
        case 'airplay':
          result = await airplayApiRef.current.play(selectedDeviceId);
          break;
        case 'dlna':
          result = await dlnaApiRef.current.play(selectedDeviceId);
          break;
        default:
          return;
      }
      if (!result.success) {
        pushToast(result.message || 'Play failed', 'error');
        return;
      }
      setTransportState((prev) => prev ? { ...prev, isPlaying: true } : prev);
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Play failed';
      pushToast(message, 'error');
    } finally {
      setActing(false);
    }
  }, [activeTab, selectedDeviceId, pushToast]);

  const handlePause = useCallback(async () => {
    if (!selectedDeviceId) return;
    setActing(true);
    try {
      let result;
      switch (activeTab) {
        case 'chromecast':
          result = await castApiRef.current.pause(selectedDeviceId);
          break;
        case 'airplay':
          result = await airplayApiRef.current.pause(selectedDeviceId);
          break;
        case 'dlna':
          result = await dlnaApiRef.current.pause(selectedDeviceId);
          break;
        default:
          return;
      }
      if (!result.success) {
        pushToast(result.message || 'Pause failed', 'error');
        return;
      }
      setTransportState((prev) => prev ? { ...prev, isPlaying: false } : prev);
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Pause failed';
      pushToast(message, 'error');
    } finally {
      setActing(false);
    }
  }, [activeTab, selectedDeviceId, pushToast]);

  const handleStop = useCallback(async () => {
    if (!selectedDeviceId) return;
    setActing(true);
    try {
      let result;
      switch (activeTab) {
        case 'chromecast':
          result = await castApiRef.current.stop(selectedDeviceId);
          break;
        case 'airplay':
          result = await airplayApiRef.current.stop(selectedDeviceId);
          break;
        case 'roku':
          result = await rokuApiRef.current.stop(selectedDeviceId);
          break;
        case 'dlna':
          result = await dlnaApiRef.current.stop(selectedDeviceId);
          break;
      }
      if (!result.success) {
        pushToast(result.message || 'Stop failed', 'error');
        return;
      }
      setTransportState((prev) => prev ? { ...prev, isPlaying: false, position: null } : prev);
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Stop failed';
      pushToast(message, 'error');
    } finally {
      setActing(false);
    }
  }, [activeTab, selectedDeviceId, pushToast]);

  const handleSeek = useCallback(async (position: number) => {
    if (!selectedDeviceId) return;
    setActing(true);
    try {
      let result;
      switch (activeTab) {
        case 'chromecast':
          result = await castApiRef.current.seek(selectedDeviceId, position);
          break;
        case 'dlna':
          result = await dlnaApiRef.current.seek(selectedDeviceId, position);
          break;
        default:
          return;
      }
      if (!result.success) {
        pushToast(result.message || 'Seek failed', 'error');
        return;
      }
      setTransportState((prev) => prev ? { ...prev, position } : prev);
    } catch (err) {
      const message = err instanceof ApiError ? err.message : 'Seek failed';
      pushToast(message, 'error');
    } finally {
      setActing(false);
    }
  }, [activeTab, selectedDeviceId, pushToast]);

  // ---------------------------------------------------------------------------
  // Derived state for current tab
  // ---------------------------------------------------------------------------

  const currentDevices = (() => {
    switch (activeTab) {
      case 'chromecast': return castDevices;
      case 'airplay': return airplayDevices;
      case 'roku': return rokuDevices;
      case 'dlna': return dlnaDevices;
    }
  })();

  const currentLoading = (() => {
    switch (activeTab) {
      case 'chromecast': return loadingCast;
      case 'airplay': return loadingAirPlay;
      case 'roku': return loadingRoku;
      case 'dlna': return loadingDlna;
    }
  })();

  const selectedDeviceName = (() => {
    const allDevices: Record<string, Array<CastDevice | AirPlayDevice | RokuDevice | DlnaDevice>> = {
      chromecast: castDevices,
      airplay: airplayDevices,
      roku: rokuDevices,
      dlna: dlnaDevices,
    };
    const list = allDevices[activeTab] ?? [];
    const found = list.find((d) => d.device_id === selectedDeviceId);
    return found?.name ?? '';
  })();

  // ---------------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------------

  return (
    <div className="page page--cast-devices">
      <div className="page__header">
        <h1>Cast Devices</h1>
      </div>

      {/* Tab bar */}
      <div className="cast-devices-tabs" role="tablist" aria-label="Device type">
        {TABS.map((tab) => (
          <button
            key={tab.id}
            type="button"
            role="tab"
            aria-selected={activeTab === tab.id}
            aria-controls={`panel-${tab.id}`}
            className={`cast-devices-tab${activeTab === tab.id ? ' cast-devices-tab--active' : ''}`}
            onClick={() => handleTabChange(tab.id)}
          >
            <span aria-hidden="true">{deviceIcon(tab.id)}</span>{' '}
            {tab.label}
          </button>
        ))}
      </div>

      {/* Content area */}
      <div
        id={`panel-${activeTab}`}
        role="tabpanel"
        aria-label={`${activeTab} devices`}
        className="cast-devices-content"
      >
        <div className="cast-devices-content__header">
          <h2>
            {TABS.find((t) => t.id === activeTab)?.label} Devices
          </h2>
        </div>

        {/* Device grid */}
        {currentLoading ? (
          <div className="device-grid" aria-busy="true">
            <DeviceCardSkeleton />
            <DeviceCardSkeleton />
          </div>
        ) : currentDevices.length === 0 ? (
          <p className="page__hint">No {activeTab} devices discovered.</p>
        ) : (
          <div className="device-grid" role="list">
            {currentDevices.map((device) => (
              <div key={device.device_id} role="listitem">
                <DeviceCard
                  tabId={activeTab}
                  deviceId={device.device_id}
                  name={device.name}
                  model={device.model}
                  host={device.host}
                  onSelect={handleSelectDevice}
                  isSelected={selectedDeviceId === device.device_id}
                />
              </div>
            ))}
          </div>
        )}

        {/* Transport controls */}
        {selectedDeviceId && (
          <div className="cast-session" aria-labelledby="transport-heading">
            <div className="cast-session__header">
              <h2 id="transport-heading">Playback Controls</h2>
            </div>
            <TransportControls
              tabId={activeTab}
              deviceName={selectedDeviceName}
              state={transportState}
              loading={loadingTransport}
              acting={acting}
              onPlay={handlePlay}
              onPause={handlePause}
              onStop={handleStop}
              onSeek={handleSeek}
            />
          </div>
        )}
      </div>
    </div>
  );
}
