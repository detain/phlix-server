/**
 * CastApi — typed wrapper over {@link ApiClient} for Chromecast
 * device management endpoints (`/api/v1/cast/*`).
 *
 * @since 2.1
 */
import type { ApiClient } from './client';

/** A discovered Chromecast device. */
export interface CastDevice {
  device_id: string;
  name: string;
  host: string;
  port: number;
  model: string;
  address: string;
}

/** Current playback state on a Cast device. */
export interface CastPlaybackState {
  device_id: string;
  media_title: string;
  media_item_id: string | null;
  transport_state: string;
  volume_level: number;
  muted: boolean;
  duration_seconds: number | null;
  position_seconds: number | null;
}

/** Result of a transport action (play/pause/seek/stop). */
export interface CastActionResult {
  success: boolean;
  message?: string;
}

/**
 * Typed client for Chromecast device endpoints.
 *
 * @since 2.1
 */
export class CastApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/cast/devices` → `{ success, data: CastDevice[] }`
   */
  async listDevices(): Promise<CastDevice[]> {
    // Server returns `{ devices, count }` (ChromecastController::listDevices),
    // not `{ success, data }`. Accept both and default to [] so the page never
    // maps over undefined.
    const res = await this.client.get<{ devices?: CastDevice[]; data?: CastDevice[] }>(
      '/api/v1/cast/devices',
    );
    return res.devices ?? res.data ?? [];
  }

  /**
   * `GET /api/v1/cast/devices/:id/status` → `{ success, data: CastPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<CastPlaybackState> {
    // The server returns a FLAT object `{ device_id, active, state,
    // session_id, media_status }` (ChromecastController::getStatus), not
    // `{ success, data }`. Normalise to the fields the UI reads, with safe
    // defaults so a missing/idle session never errors the status panel.
    const res = await this.client.get<Record<string, unknown>>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/status`,
    );
    return normalizeCastStatus(res, deviceId);
  }

  /**
   * `POST /api/v1/cast/devices/:id/play`. The client throws on non-2xx, so a
   * resolved call is success — normalise to `{ success: true, … }` (the server
   * doesn't always send a `success` field).
   */
  async play(deviceId: string): Promise<CastActionResult> {
    const res = await this.client.post<Partial<CastActionResult>>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/play`,
    );
    return { success: true, ...res };
  }

  /** `POST /api/v1/cast/devices/:id/pause` → normalised `{ success: true, … }`. */
  async pause(deviceId: string): Promise<CastActionResult> {
    const res = await this.client.post<Partial<CastActionResult>>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/pause`,
    );
    return { success: true, ...res };
  }

  /** `POST /api/v1/cast/devices/:id/stop` → normalised `{ success: true, … }`. */
  async stop(deviceId: string): Promise<CastActionResult> {
    const res = await this.client.post<Partial<CastActionResult>>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/stop`,
    );
    return { success: true, ...res };
  }

  /**
   * `POST /api/v1/cast/devices/:id/seek`. The server expects `position_ms`
   * (ChromecastController::seek), so convert from seconds.
   * @param positionSeconds - Target position in seconds
   */
  async seek(deviceId: string, positionSeconds: number): Promise<CastActionResult> {
    const res = await this.client.post<Partial<CastActionResult>>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/seek`,
      { position_ms: Math.round(positionSeconds * 1000) },
    );
    return { success: true, ...res };
  }
}

/**
 * Map the server's flat status object to {@link CastPlaybackState}. `state`
 * (or the `active` flag) drives `transport_state`; media metadata is read from
 * the nested `media_status` when present. All fields default safely.
 */
function normalizeCastStatus(res: Record<string, unknown>, deviceId: string): CastPlaybackState {
  const ms = (typeof res['media_status'] === 'object' && res['media_status'] !== null
    ? (res['media_status'] as Record<string, unknown>)
    : {});
  const num = (v: unknown): number => (typeof v === 'number' && Number.isFinite(v) ? v : 0);
  const str = (v: unknown): string => (typeof v === 'string' ? v : '');
  const transport = str(res['transport_state'] ?? res['state'])
    || (res['active'] === true ? 'PLAYING' : 'STOPPED');
  return {
    device_id: str(res['device_id']) || deviceId,
    media_title: str(res['media_title'] ?? ms['media_title'] ?? ms['title']),
    media_item_id: (typeof res['media_item_id'] === 'string' ? res['media_item_id'] : null),
    transport_state: transport,
    volume_level: num(res['volume_level'] ?? ms['volume_level']),
    muted: res['muted'] === true,
    position_seconds: num(res['position_seconds'] ?? ms['position_seconds'] ?? ms['current_time']),
    duration_seconds: num(res['duration_seconds'] ?? ms['duration_seconds'] ?? ms['duration']),
  };
}
