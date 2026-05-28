/**
 * DlnaApi — typed wrapper over {@link ApiClient} for DLNA
 * device management endpoints (`/api/v1/dlna/*`).
 *
 * @since 2.1
 */
import type { ApiClient } from './client';

/** A discovered DLNA device. */
export interface DlnaDevice {
  device_id: string;
  name: string;
  host: string;
  port: number;
  model: string;
  address: string;
}

/** Current playback state on a DLNA device. */
export interface DlnaPlaybackState {
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
export interface DlnaActionResult {
  success: boolean;
  message?: string;
}

/**
 * Typed client for DLNA device endpoints.
 *
 * @since 2.1
 */
export class DlnaApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/dlna/devices` → `{ success, data: DlnaDevice[] }`
   */
  async listDevices(): Promise<DlnaDevice[]> {
    const { data } = await this.client.get<{ success: boolean; data: DlnaDevice[] }>(
      '/api/v1/dlna/devices',
    );
    return data;
  }

  /**
   * `GET /api/v1/dlna/devices/:id/status` → `{ success, data: DlnaPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<DlnaPlaybackState> {
    const { data } = await this.client.get<{ success: boolean; data: DlnaPlaybackState }>(
      `/api/v1/dlna/devices/${encodeURIComponent(deviceId)}/status`,
    );
    return data;
  }

  /**
   * `POST /api/v1/dlna/devices/:id/play` → DlnaActionResult
   */
  async play(deviceId: string): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/devices/${encodeURIComponent(deviceId)}/play`,
    );
  }

  /**
   * `POST /api/v1/dlna/devices/:id/pause` → DlnaActionResult
   */
  async pause(deviceId: string): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/devices/${encodeURIComponent(deviceId)}/pause`,
    );
  }

  /**
   * `POST /api/v1/dlna/devices/:id/stop` → DlnaActionResult
   */
  async stop(deviceId: string): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/devices/${encodeURIComponent(deviceId)}/stop`,
    );
  }

  /**
   * `POST /api/v1/dlna/devices/:id/seek` → DlnaActionResult
   * @param positionSeconds - Target position in seconds
   */
  async seek(deviceId: string, positionSeconds: number): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/devices/${encodeURIComponent(deviceId)}/seek`,
      { position_seconds: positionSeconds },
    );
  }
}
