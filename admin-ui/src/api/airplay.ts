/**
 * AirPlayApi — typed wrapper over {@link ApiClient} for AirPlay
 * device management endpoints (`/api/v1/airplay/*`).
 *
 * Note: AirPlay supports pause/resume but does NOT support seek.
 *
 * @since 2.1
 */
import type { ApiClient } from './client';

/** A discovered AirPlay device. */
export interface AirPlayDevice {
  device_id: string;
  name: string;
  host: string;
  port: number;
  model: string;
  address: string;
}

/** Current playback state on an AirPlay device. */
export interface AirPlayPlaybackState {
  device_id: string;
  media_title: string;
  media_item_id: string | null;
  transport_state: string;
  volume_level: number;
  muted: boolean;
}

/** Result of a transport action (play/pause/stop). */
export interface AirPlayActionResult {
  success: boolean;
  message?: string;
}

/**
 * Typed client for AirPlay device endpoints.
 *
 * @since 2.1
 */
export class AirPlayApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/airplay/devices` → `{ success, data: AirPlayDevice[] }`
   */
  async listDevices(): Promise<AirPlayDevice[]> {
    // Server returns `{ devices, count }` (AirPlayController::listDevices), not
    // `{ success, data }`. Accept both and default to [].
    const res = await this.client.get<{ devices?: AirPlayDevice[]; data?: AirPlayDevice[] }>(
      '/api/v1/airplay/devices',
    );
    return res.devices ?? res.data ?? [];
  }

  /**
   * `GET /api/v1/airplay/devices/:id/status` → `{ success, data: AirPlayPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<AirPlayPlaybackState> {
    // Server returns a flat object, not `{ success, data }`. Normalise to the
    // fields the UI reads, with safe defaults.
    const res = await this.client.get<Record<string, unknown>>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/status`,
    );
    const str = (v: unknown): string => (typeof v === 'string' ? v : '');
    return {
      device_id: str(res['device_id']) || deviceId,
      media_title: str(res['media_title']),
      media_item_id: typeof res['media_item_id'] === 'string' ? res['media_item_id'] : null,
      transport_state:
        str(res['transport_state'] ?? res['state']) || (res['active'] === true ? 'PLAYING' : 'STOPPED'),
      volume_level: typeof res['volume_level'] === 'number' ? res['volume_level'] : 0,
      muted: res['muted'] === true,
    };
  }

  /**
   * Resume playback. The server has no `/play` route — AirPlay uses `/resume`
   * for an active stream (a fresh cast is `/stream` with a media URL). Hitting
   * `/play` 404'd. The client throws on non-2xx, so normalise to success.
   */
  async play(deviceId: string): Promise<AirPlayActionResult> {
    const res = await this.client.post<Partial<AirPlayActionResult>>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/resume`,
    );
    return { success: true, ...res };
  }

  /** `POST /api/v1/airplay/devices/:id/pause` → normalised `{ success: true, … }`. */
  async pause(deviceId: string): Promise<AirPlayActionResult> {
    const res = await this.client.post<Partial<AirPlayActionResult>>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/pause`,
    );
    return { success: true, ...res };
  }

  /** `POST /api/v1/airplay/devices/:id/stop` → normalised `{ success: true, … }`. */
  async stop(deviceId: string): Promise<AirPlayActionResult> {
    const res = await this.client.post<Partial<AirPlayActionResult>>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/stop`,
    );
    return { success: true, ...res };
  }
}
