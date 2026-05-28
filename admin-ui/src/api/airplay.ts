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
    const { data } = await this.client.get<{ success: boolean; data: AirPlayDevice[] }>(
      '/api/v1/airplay/devices',
    );
    return data;
  }

  /**
   * `GET /api/v1/airplay/devices/:id/status` → `{ success, data: AirPlayPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<AirPlayPlaybackState> {
    const { data } = await this.client.get<{ success: boolean; data: AirPlayPlaybackState }>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/status`,
    );
    return data;
  }

  /**
   * `POST /api/v1/airplay/devices/:id/play` → AirPlayActionResult
   */
  async play(deviceId: string): Promise<AirPlayActionResult> {
    return this.client.post<AirPlayActionResult>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/play`,
    );
  }

  /**
   * `POST /api/v1/airplay/devices/:id/pause` → AirPlayActionResult
   */
  async pause(deviceId: string): Promise<AirPlayActionResult> {
    return this.client.post<AirPlayActionResult>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/pause`,
    );
  }

  /**
   * `POST /api/v1/airplay/devices/:id/stop` → AirPlayActionResult
   */
  async stop(deviceId: string): Promise<AirPlayActionResult> {
    return this.client.post<AirPlayActionResult>(
      `/api/v1/airplay/devices/${encodeURIComponent(deviceId)}/stop`,
    );
  }
}
