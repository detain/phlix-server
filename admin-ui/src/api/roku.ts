/**
 * RokuApi — typed wrapper over {@link ApiClient} for Roku
 * device management endpoints (`/api/v1/roku/*`).
 *
 * Note: Roku only supports stop — no play/pause/seek.
 *
 * @since 2.1
 */
import type { ApiClient } from './client';

/** A discovered Roku device. */
export interface RokuDevice {
  device_id: string;
  name: string;
  host: string;
  port: number;
  model: string;
  address: string;
}

/** Current playback state on a Roku device. */
export interface RokuPlaybackState {
  device_id: string;
  media_title: string;
  media_item_id: string | null;
  transport_state: string;
  volume_level: number;
  muted: boolean;
}

/** Result of a transport action (stop). */
export interface RokuActionResult {
  success: boolean;
  message?: string;
}

/**
 * Typed client for Roku device endpoints.
 *
 * @since 2.1
 */
export class RokuApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/roku/devices` → `{ success, data: RokuDevice[] }`
   */
  async listDevices(): Promise<RokuDevice[]> {
    const { data } = await this.client.get<{ success: boolean; data: RokuDevice[] }>(
      '/api/v1/roku/devices',
    );
    return data;
  }

  /**
   * `GET /api/v1/roku/devices/:id/status` → `{ success, data: RokuPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<RokuPlaybackState> {
    const { data } = await this.client.get<{ success: boolean; data: RokuPlaybackState }>(
      `/api/v1/roku/devices/${encodeURIComponent(deviceId)}/status`,
    );
    return data;
  }

  /**
   * `POST /api/v1/roku/devices/:id/stop` → RokuActionResult
   */
  async stop(deviceId: string): Promise<RokuActionResult> {
    return this.client.post<RokuActionResult>(
      `/api/v1/roku/devices/${encodeURIComponent(deviceId)}/stop`,
    );
  }
}
