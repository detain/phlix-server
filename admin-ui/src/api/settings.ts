/**
 * SettingsApi — typed wrapper over the admin settings GET/PUT endpoints
 * (`/api/v1/admin/settings`).
 *
 * @since 1.3
 */
import type { ApiClient } from './client';

/**
 * Shape of the GET /api/v1/admin/settings response data.
 * @since 1.3
 */
export interface SettingsResponse {
  settings: Record<string, unknown>;
  overridden: string[];
  types: Record<string, string>;
}

/**
 * Shape of the PUT /api/v1/admin/settings success response data.
 * @since 1.3
 */
export interface SettingsSaveResponse {
  settings: Record<string, unknown>;
  overridden: string[];
}

/**
 * Typed client for the admin settings endpoints.
 *
 * @since 1.3
 */
export class SettingsApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/settings` → unwraps `{ success: true, data: { settings, overridden, types } }`.
   */
  async get(): Promise<SettingsResponse> {
    const { data } = await this.client.get<{ data: SettingsResponse }>(
      '/api/v1/admin/settings',
    );
    return data;
  }

  /**
   * `PUT /api/v1/admin/settings` → `{ success: true, message, data: { settings, overridden } }`.
   * @param settings - Record of setting key-value pairs to save.
   */
  async save(settings: Record<string, unknown>): Promise<SettingsSaveResponse> {
    const { data } = await this.client.put<{ data: SettingsSaveResponse; message: string }>(
      '/api/v1/admin/settings',
      { settings },
    );
    return data;
  }
}
