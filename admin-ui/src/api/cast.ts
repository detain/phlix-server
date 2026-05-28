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
    const { data } = await this.client.get<{ success: boolean; data: CastDevice[] }>(
      '/api/v1/cast/devices',
    );
    return data;
  }

  /**
   * `GET /api/v1/cast/devices/:id/status` → `{ success, data: CastPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<CastPlaybackState> {
    const { data } = await this.client.get<{ success: boolean; data: CastPlaybackState }>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/status`,
    );
    return data;
  }

  /**
   * `POST /api/v1/cast/devices/:id/play` → CastActionResult
   */
  async play(deviceId: string): Promise<CastActionResult> {
    return this.client.post<CastActionResult>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/play`,
    );
  }

  /**
   * `POST /api/v1/cast/devices/:id/pause` → CastActionResult
   */
  async pause(deviceId: string): Promise<CastActionResult> {
    return this.client.post<CastActionResult>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/pause`,
    );
  }

  /**
   * `POST /api/v1/cast/devices/:id/stop` → CastActionResult
   */
  async stop(deviceId: string): Promise<CastActionResult> {
    return this.client.post<CastActionResult>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/stop`,
    );
  }

  /**
   * `POST /api/v1/cast/devices/:id/seek` → CastActionResult
   * @param positionSeconds - Target position in seconds
   */
  async seek(deviceId: string, positionSeconds: number): Promise<CastActionResult> {
    return this.client.post<CastActionResult>(
      `/api/v1/cast/devices/${encodeURIComponent(deviceId)}/seek`,
      { position_seconds: positionSeconds },
    );
  }
}
