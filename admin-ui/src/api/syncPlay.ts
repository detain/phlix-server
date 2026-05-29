/**
 * SyncPlayApi — typed wrapper over the existing {@link ApiClient} for the
 * SyncPlay group watching endpoints (`/api/v1/syncplay/*`).
 *
 * Every method maps 1:1 to an endpoint shipped by `SyncPlayController` and
 * parses the EXACT response envelope that controller returns — unwrapping the
 * single-key wrappers (`{ groups }`, `{ group }`, `{ success }`) so callers
 * receive the bare domain object. Non-2xx responses throw {@link ApiError}
 * via the shared client.
 *
 * @since 3.5
 */
import type { ApiClient } from './client';

/**
 * A SyncPlay group summary as returned by the API.
 *
 * @since 3.5
 */
export interface SyncPlayGroup {
  /** Unique group identifier (format: sp_*) */
  id: string;
  /** Display name of the group */
  name: string;
  /** Number of members currently in the group */
  member_count: number;
  /** Whether the group requires a password to join */
  has_password: boolean;
  /** Currently playing media ID, or null if nothing is playing */
  current_media: string | null;
  /** Whether playback is currently active */
  is_playing: boolean;
}

/**
 * Full group state as returned by getGroupState.
 *
 * @since 3.5
 */
export interface SyncPlayGroupState {
  id: string;
  name: string;
  host_id: string;
  has_password: boolean;
  members: SyncPlayMember[];
  playback_state: SyncPlayPlaybackState;
  queue: SyncPlayQueueItem[];
  created_at: number;
  last_activity: number;
}

/**
 * A member within a SyncPlay group.
 *
 * @since 3.5
 */
export interface SyncPlayMember {
  id: string;
  name: string;
  is_host: boolean;
  joined_at: number;
}

/**
 * Playback state within a SyncPlay group.
 *
 * @since 3.5
 */
export interface SyncPlayPlaybackState {
  state: 'playing' | 'paused' | 'stopped';
  position: number;
  server_time: number;
}

/**
 * A queued media item in a SyncPlay group.
 *
 * @since 3.5
 */
export interface SyncPlayQueueItem {
  media_id: string;
  media_info: Record<string, unknown>;
  added_by: string;
  added_at: number;
}

/** Body accepted by {@link SyncPlayApi.createGroup}. @since 3.5 */
export interface CreateGroupInput {
  name: string;
  password?: string;
}

/** Body accepted by {@link SyncPlayApi.joinGroup}. @since 3.5 */
export interface JoinGroupInput {
  password?: string;
}

/**
 * Typed client for the SyncPlay endpoints.
 *
 * @since 3.5
 */
export class SyncPlayApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/syncplay/groups` → unwraps `{ groups }`.
   *
   * @since 3.5
   */
  async listGroups(): Promise<SyncPlayGroup[]> {
    const { groups } = await this.client.get<{ groups: SyncPlayGroup[] }>(
      '/api/v1/syncplay/groups',
    );
    return groups;
  }

  /**
   * `POST /api/v1/syncplay/groups` → `{ success, group }`.
   *
   * @since 3.5
   */
  createGroup(input: CreateGroupInput): Promise<{ success: boolean; group: SyncPlayGroupState }> {
    return this.client.post<{ success: boolean; group: SyncPlayGroupState }>(
      '/api/v1/syncplay/groups',
      input,
    );
  }

  /**
   * `GET /api/v1/syncplay/groups/{id}` → `{ group }`.
   *
   * @since 3.5
   */
  getGroup(id: string): Promise<{ group: SyncPlayGroupState }> {
    return this.client.get<{ group: SyncPlayGroupState }>(
      `/api/v1/syncplay/groups/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/syncplay/groups/{id}/join` → `{ success, group }`.
   *
   * @since 3.5
   */
  joinGroup(
    id: string,
    input?: JoinGroupInput,
  ): Promise<{ success: boolean; group: SyncPlayGroupState }> {
    return this.client.post<{ success: boolean; group: SyncPlayGroupState }>(
      `/api/v1/syncplay/groups/${encodeURIComponent(id)}/join`,
      input ?? {},
    );
  }

  /**
   * `POST /api/v1/syncplay/groups/{id}/leave` → `{ success, message? }`.
   *
   * @since 3.5
   */
  leaveGroup(id: string): Promise<{ success: boolean; message?: string }> {
    return this.client.post<{ success: boolean; message?: string }>(
      `/api/v1/syncplay/groups/${encodeURIComponent(id)}/leave`,
      {},
    );
  }
}
