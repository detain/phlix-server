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
   * `GET /api/v1/dlna/renderers` → `{ renderers, count }`.
   *
   * The server mounts DLNA "play-to" devices under `/dlna/renderers` (see
   * RendererListController) and returns them under `renderers` — the SPA
   * previously hit `/dlna/devices` (404) and read `data` (undefined). Accept
   * both keys and default to [].
   */
  async listDevices(): Promise<DlnaDevice[]> {
    const res = await this.client.get<{ renderers?: DlnaDevice[]; data?: DlnaDevice[] }>(
      '/api/v1/dlna/renderers',
    );
    return res.renderers ?? res.data ?? [];
  }

  /**
   * `GET /api/v1/dlna/renderers/:id/status` → `{ success, data: DlnaPlaybackState }`
   */
  async getStatus(deviceId: string): Promise<DlnaPlaybackState> {
    const { data } = await this.client.get<{ success: boolean; data: DlnaPlaybackState }>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/status`,
    );
    return data;
  }

  /**
   * `POST /api/v1/dlna/renderers/:id/play` → DlnaActionResult
   */
  async play(deviceId: string): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/play`,
    );
  }

  /**
   * `POST /api/v1/dlna/renderers/:id/pause` → DlnaActionResult
   */
  async pause(deviceId: string): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/pause`,
    );
  }

  /**
   * `POST /api/v1/dlna/renderers/:id/stop` → DlnaActionResult
   */
  async stop(deviceId: string): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/stop`,
    );
  }

  /**
   * `POST /api/v1/dlna/renderers/:id/seek` → DlnaActionResult
   * @param positionSeconds - Target position in seconds
   */
  async seek(deviceId: string, positionSeconds: number): Promise<DlnaActionResult> {
    return this.client.post<DlnaActionResult>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/seek`,
      { position_seconds: positionSeconds },
    );
  }
}
