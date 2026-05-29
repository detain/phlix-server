/**
 * LiveTvApi — typed wrapper over the admin Live TV / DVR endpoints
 * for tuners, channels, EPG/guide, recordings, and series rules
 * (`/api/v1/admin/livetv/...`).
 *
 * @since 2.5
 */
import type { ApiClient } from './client';

// ─── Tuner types ────────────────────────────────────────────────────────────

/**
 * Shape of a TV tuner device (HDHomeRun, IPTV, etc.).
 * @since 2.4
 */
export interface Tuner {
  id?: string | number;
  tuner_id: string;
  type: string;
  name: string;
  host: string;
  port: number;
  device_id?: string;
  enabled: boolean | number;
  last_seen?: string;
  status?: string;
  capabilities?: string[];
  discovered_at?: string;
}

/**
 * Shape of a TV channel.
 * @since 2.4
 */
export interface Channel {
  id: string;
  tuner_id?: string;
  name: string;
  number: string;
  callsign?: string;
  transport?: string;
  frequency?: number;
  modulation?: string;
  enabled: boolean | number;
  created_at?: string;
}

// ─── Guide / EPG types ──────────────────────────────────────────────────────

/**
 * Shape of a programme in the TV guide / EPG.
 * @since 2.4
 */
export interface Program {
  id: string;
  channel_id?: string;
  title: string;
  description?: string;
  start_time: number;
  end_time: number;
  season?: number;
  episode?: number;
  year?: number;
  rating?: string;
  poster?: string;
}

// ─── Recording types ────────────────────────────────────────────────────────

/**
 * Shape of a DVR recording.
 * @since 2.4
 */
export interface Recording {
  id: string;
  channel_id: string;
  channel_name?: string;
  program_title?: string;
  start_time: number;
  end_time: number;
  status?: string;
  file_path?: string;
  size?: number;
  series_rule_id?: string;
}

// ─── Series Rule types ───────────────────────────────────────────────────────

/**
 * Shape of an auto-DVR series rule.
 * @since 2.4
 */
export interface SeriesRule {
  id: string;
  title_pattern: string;
  channel_id?: string;
  keep_until?: string;
  priority?: number;
  enabled: boolean | number;
  created_at?: string;
}

// ─── API class ────────────────────────────────────────────────────────────────

/**
 * Typed client for the admin Live TV / DVR endpoints.
 *
 * @since 2.5
 */
export class LiveTvApi {
  constructor(private readonly client: ApiClient) {}

  // ─── Tuners ────────────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/livetv/tuners` → list all tuners.
   */
  async listTuners(): Promise<{ success: boolean; tuners: Tuner[] }> {
    return this.client.get<{ success: boolean; tuners: Tuner[] }>(
      '/api/v1/admin/livetv/tuners',
    );
  }

  /**
   * `GET /api/v1/admin/livetv/tuners/{id}` → single tuner.
   */
  async getTuner(
    id: string,
  ): Promise<{ success: boolean; tuner: Tuner }> {
    return this.client.get<{ success: boolean; tuner: Tuner }>(
      `/api/v1/admin/livetv/tuners/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/livetv/tuners/scan` → discover HDHomeRun tuners.
   */
  async scanTuners(): Promise<{ success: boolean; tuners: Tuner[] }> {
    return this.client.post<{ success: boolean; tuners: Tuner[] }>(
      '/api/v1/admin/livetv/tuners/scan',
    );
  }

  /**
   * `PUT /api/v1/admin/livetv/tuners/{id}` → update tuner name / enabled.
   */
  async updateTuner(
    id: string,
    data: { name?: string; enabled?: boolean },
  ): Promise<{ success: boolean; tuner: Tuner }> {
    return this.client.put<{ success: boolean; tuner: Tuner }>(
      `/api/v1/admin/livetv/tuners/${encodeURIComponent(id)}`,
      data,
    );
  }

  /**
   * `DELETE /api/v1/admin/livetv/tuners/{id}` → remove tuner.
   */
  async deleteTuner(id: string): Promise<{ success: boolean }> {
    return this.client.delete<{ success: boolean }>(
      `/api/v1/admin/livetv/tuners/${encodeURIComponent(id)}`,
    );
  }

  // ─── Channels ──────────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/livetv/channels` → list all channels.
   */
  async listChannels(): Promise<{ success: boolean; channels: Channel[] }> {
    return this.client.get<{ success: boolean; channels: Channel[] }>(
      '/api/v1/admin/livetv/channels',
    );
  }

  /**
   * `GET /api/v1/admin/livetv/channels/{id}` → single channel.
   */
  async getChannel(
    id: string,
  ): Promise<{ success: boolean; channel: Channel }> {
    return this.client.get<{ success: boolean; channel: Channel }>(
      `/api/v1/admin/livetv/channels/${encodeURIComponent(id)}`,
    );
  }

  // ─── Guide / EPG ────────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/livetv/guide` → list guide programmes.
   * @param params - Optional channel_id, from, to (Unix timestamps).
   */
  async listGuide(params?: {
    channel_id?: string;
    from?: number;
    to?: number;
  }): Promise<{ success: boolean; programs: Program[] }> {
    const query: Record<string, string> = {};
    if (params?.channel_id) query.channel_id = params.channel_id;
    if (params?.from) query.from = String(params.from);
    if (params?.to) query.to = String(params.to);

    const qs = Object.keys(query).length > 0
      ? '?' + new URLSearchParams(query).toString()
      : '';
    return this.client.get<{ success: boolean; programs: Program[] }>(
      `/api/v1/admin/livetv/guide${qs}`,
    );
  }

  /**
   * `GET /api/v1/admin/livetv/guide/programs/{id}` → single program.
   */
  async getProgram(
    id: string,
  ): Promise<{ success: boolean; program: Program }> {
    return this.client.get<{ success: boolean; program: Program }>(
      `/api/v1/admin/livetv/guide/programs/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/livetv/guide/refresh` → refresh EPG data.
   */
  async refreshGuide(
    daysAhead?: number,
  ): Promise<{ success: boolean; programs: number }> {
    return this.client.post<{ success: boolean; programs: number }>(
      '/api/v1/admin/livetv/guide/refresh',
      daysAhead !== undefined ? { days_ahead: daysAhead } : undefined,
    );
  }

  // ─── Recordings ───────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/livetv/recordings` → list all recordings.
   * @param params - Optional status filter.
   */
  async listRecordings(params?: {
    status?: string;
  }): Promise<{ success: boolean; recordings: Recording[] }> {
    const query: Record<string, string> = {};
    if (params?.status) query.status = params.status;
    const qs = Object.keys(query).length > 0
      ? '?' + new URLSearchParams(query).toString()
      : '';
    return this.client.get<{ success: boolean; recordings: Recording[] }>(
      `/api/v1/admin/livetv/recordings${qs}`,
    );
  }

  /**
   * `GET /api/v1/admin/livetv/recordings/{id}` → single recording.
   */
  async getRecording(
    id: string,
  ): Promise<{ success: boolean; recording: Recording }> {
    return this.client.get<{ success: boolean; recording: Recording }>(
      `/api/v1/admin/livetv/recordings/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/livetv/recordings` → schedule a manual recording.
   */
  async createRecording(data: {
    channel_id: string;
    start_time: number;
    end_time: number;
    title?: string;
    program_id?: string;
    priority?: number;
  }): Promise<{ success: boolean; recording: Recording }> {
    return this.client.post<{ success: boolean; recording: Recording }>(
      '/api/v1/admin/livetv/recordings',
      data,
    );
  }

  /**
   * `DELETE /api/v1/admin/livetv/recordings/{id}` → delete a recording.
   */
  async deleteRecording(id: string): Promise<{ success: boolean }> {
    return this.client.delete<{ success: boolean }>(
      `/api/v1/admin/livetv/recordings/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `GET /api/v1/admin/livetv/recordings/upcoming` → upcoming scheduled recordings.
   */
  async listUpcoming(
    limit = 10,
  ): Promise<{ success: boolean; recordings: Recording[] }> {
    return this.client.get<{ success: boolean; recordings: Recording[] }>(
      `/api/v1/admin/livetv/recordings/upcoming?limit=${limit}`,
    );
  }

  /**
   * `GET /api/v1/admin/livetv/recordings/series/{seriesId}` → recordings by series.
   */
  async listBySeries(
    seriesId: string,
  ): Promise<{ success: boolean; recordings: Recording[] }> {
    return this.client.get<{ success: boolean; recordings: Recording[] }>(
      `/api/v1/admin/livetv/recordings/series/${encodeURIComponent(seriesId)}`,
    );
  }

  // ─── Series Rules ───────────────────────────────────────────────────────────

  /**
   * `GET /api/v1/admin/livetv/series-rules` → list all series rules.
   */
  async listSeriesRules(): Promise<{ success: boolean; rules: SeriesRule[] }> {
    return this.client.get<{ success: boolean; rules: SeriesRule[] }>(
      '/api/v1/admin/livetv/series-rules',
    );
  }

  /**
   * `GET /api/v1/admin/livetv/series-rules/{id}` → single series rule.
   */
  async getSeriesRule(
    id: string,
  ): Promise<{ success: boolean; rule: SeriesRule }> {
    return this.client.get<{ success: boolean; rule: SeriesRule }>(
      `/api/v1/admin/livetv/series-rules/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/livetv/series-rules` → create an auto-DVR rule.
   */
  async createSeriesRule(data: {
    series_id: string;
    channel_id: string;
    title?: string;
    priority?: number;
    pre_padding_seconds?: number;
    post_padding_seconds?: number;
    max_recordings?: number;
    days_ahead?: number;
  }): Promise<{ success: boolean; rule: SeriesRule }> {
    return this.client.post<{ success: boolean; rule: SeriesRule }>(
      '/api/v1/admin/livetv/series-rules',
      data,
    );
  }

  /**
   * `PUT /api/v1/admin/livetv/series-rules/{id}` → update a series rule.
   */
  async updateSeriesRule(
    id: string,
    data: Partial<SeriesRule>,
  ): Promise<{ success: boolean; rule: SeriesRule }> {
    return this.client.put<{ success: boolean; rule: SeriesRule }>(
      `/api/v1/admin/livetv/series-rules/${encodeURIComponent(id)}`,
      data,
    );
  }

  /**
   * `DELETE /api/v1/admin/livetv/series-rules/{id}` → delete a series rule.
   */
  async deleteSeriesRule(id: string): Promise<{ success: boolean }> {
    return this.client.delete<{ success: boolean }>(
      `/api/v1/admin/livetv/series-rules/${encodeURIComponent(id)}`,
    );
  }
}
