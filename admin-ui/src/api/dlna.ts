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
    // Server returns a flat object `{ renderer_id, state, position, ... }`
    // (RendererListController::getStatus), with position/duration in
    // 100-nanosecond ticks. Normalise to the UI shape (seconds), safe defaults.
    const res = await this.client.get<Record<string, unknown>>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/status`,
    );
    const str = (v: unknown): string => (typeof v === 'string' ? v : '');
    const ticksToSec = (v: unknown): number =>
      typeof v === 'number' && Number.isFinite(v) ? Math.round(v / 10_000_000) : 0;
    const sec = (direct: unknown, ticks: unknown): number =>
      typeof direct === 'number' && Number.isFinite(direct) ? direct : ticksToSec(ticks);
    return {
      device_id: str(res['device_id'] ?? res['renderer_id']) || deviceId,
      media_title: str(res['media_title']),
      media_item_id: typeof res['media_item_id'] === 'string' ? res['media_item_id'] : null,
      transport_state:
        str(res['transport_state'] ?? res['state']) ||
        (res['has_active_session'] === false ? 'STOPPED' : 'STOPPED'),
      volume_level: typeof res['volume_level'] === 'number' ? res['volume_level'] : 0,
      muted: res['muted'] === true,
      position_seconds: sec(res['position_seconds'], res['position']),
      duration_seconds: sec(res['duration_seconds'], res['duration']),
    };
  }

  // The server's play/pause/stop return `{ state, position, ... }` with NO
  // `success` field; the client throws on non-2xx, so a resolved call IS
  // success. Normalise to `{ success: true, ... }` so the UI's `result.success`
  // check doesn't show a false "failed" toast.

  /** `POST /api/v1/dlna/renderers/:id/play` → normalised `{ success: true, … }`. */
  async play(deviceId: string): Promise<DlnaActionResult> {
    const res = await this.client.post<Partial<DlnaActionResult>>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/play`,
    );
    return { success: true, ...res };
  }

  /** `POST /api/v1/dlna/renderers/:id/pause` → normalised `{ success: true, … }`. */
  async pause(deviceId: string): Promise<DlnaActionResult> {
    const res = await this.client.post<Partial<DlnaActionResult>>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/pause`,
    );
    return { success: true, ...res };
  }

  /** `POST /api/v1/dlna/renderers/:id/stop` → normalised `{ success: true, … }`. */
  async stop(deviceId: string): Promise<DlnaActionResult> {
    const res = await this.client.post<Partial<DlnaActionResult>>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/stop`,
    );
    return { success: true, ...res };
  }

  /**
   * `POST /api/v1/dlna/renderers/:id/seek`. The server expects
   * `position_ticks` in 100-nanosecond units (RendererListController::seek →
   * RemoteRendererClient), so convert from seconds.
   * @param positionSeconds - Target position in seconds
   */
  async seek(deviceId: string, positionSeconds: number): Promise<DlnaActionResult> {
    const res = await this.client.post<Partial<DlnaActionResult>>(
      `/api/v1/dlna/renderers/${encodeURIComponent(deviceId)}/seek`,
      { position_ticks: Math.round(positionSeconds * 10_000_000) },
    );
    return { success: true, ...res };
  }
}
