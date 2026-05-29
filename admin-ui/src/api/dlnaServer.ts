/**
 * DlnaServerApi — typed wrapper over {@link ApiClient} for DLNA CDS
 * server admin endpoints (`/api/v1/admin/dlna/*`).
 *
 * @since 2.2
 */
import type { ApiClient } from './client';

/** Current DLNA CDS server status. */
export interface DlnaServerStatus {
  enabled: boolean;
  running: boolean;
  serverId: string | null;
  friendlyName: string | null;
  port: number | null;
  baseUrl: string | null;
  message?: string;
}

/** Result of a start/stop action. */
export interface DlnaServerActionResult {
  success: boolean;
  message?: string;
}

/**
 * Typed client for DLNA CDS server admin endpoints.
 *
 * @since 2.2
 */
export class DlnaServerApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/dlna/status` → DlnaServerStatus
   */
  async getStatus(): Promise<DlnaServerStatus> {
    return this.client.get<DlnaServerStatus>(
      '/api/v1/admin/dlna/status',
    );
  }

  /**
   * `POST /api/v1/admin/dlna/start` → DlnaServerActionResult
   */
  async start(): Promise<DlnaServerActionResult> {
    return this.client.post<DlnaServerActionResult>(
      '/api/v1/admin/dlna/start',
    );
  }

  /**
   * `POST /api/v1/admin/dlna/stop` → DlnaServerActionResult
   */
  async stop(): Promise<DlnaServerActionResult> {
    return this.client.post<DlnaServerActionResult>(
      '/api/v1/admin/dlna/stop',
    );
  }
}
