/**
 * SmartPlaylistsApi — typed wrapper over the existing {@link ApiClient} for the
 * smart playlist CRUD + preview endpoints (`/api/v1/smart-playlists/*`).
 *
 * Every method maps 1:1 to an endpoint shipped by `SmartPlaylistController` and
 * parses the EXACT response envelope that controller returns — unwrapping the
 * single-key wrappers (`{ smart_playlists }`, `{ smart_playlist }`,
 * `{ media_items }`, `{ message }`) so callers receive the bare domain object.
 * Non-2xx responses throw {@link ApiError} via the shared client.
 *
 * @since 3.3
 */
import type { ApiClient } from './client';

// ---------------------------------------------------------------------------
// Rule DSL types
// ---------------------------------------------------------------------------

/**
 * A single rule condition: `field OP value`.
 * @since 3.3
 */
export interface Rule {
  field: string;
  op: string;
  value: string | number;
}

/**
 * A group of rules combined with AND/OR logic. Can contain nested groups.
 * @since 3.3
 */
export interface RuleGroup {
  logic: 'and' | 'or';
  rules: (Rule | RuleGroup)[];
}

// ---------------------------------------------------------------------------
// Domain types
// ---------------------------------------------------------------------------

/**
 * Supported fields for smart playlist rules.
 * @since 3.3
 */
export const RULE_FIELDS = [
  'title',
  'year',
  'genre',
  'rating',
  'runtime',
  'added_at',
  'play_count',
  'media_type',
] as const;

/** A supported rule field. @since 3.3 */
export type RuleField = (typeof RULE_FIELDS)[number];

/**
 * Supported operators for string fields.
 * @since 3.3
 */
export const STRING_OPS = ['contains', 'equals', 'starts_with', 'ends_with'] as const;

/**
 * Supported operators for numeric fields.
 * @since 3.3
 */
export const NUMERIC_OPS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'] as const;

/**
 * Operators that apply to string fields.
 * @since 3.3
 */
export type StringOp = (typeof STRING_OPS)[number];

/**
 * Operators that apply to numeric fields.
 * @since 3.3
 */
export type NumericOp = (typeof NUMERIC_OPS)[number];

/**
 * A smart playlist row as returned by the API.
 *
 * @since 3.3
 */
export interface SmartPlaylist {
  id: string;
  name: string;
  library_id: string;
  rules_json: RuleGroup[];
  limit?: number;
  sort_by?: string;
  sort_desc?: boolean;
  item_count?: number;
  created_at?: string;
  [k: string]: unknown;
}

/** Body accepted by {@link SmartPlaylistsApi.create}. @since 3.3 */
export interface CreateSmartPlaylistInput {
  name: string;
  library_id: string;
  rules_json: RuleGroup[];
  limit?: number;
  sort_by?: string;
  sort_desc?: boolean;
}

/** Body accepted by {@link SmartPlaylistsApi.update}. @since 3.3 */
export interface UpdateSmartPlaylistInput {
  name: string;
  rules_json: RuleGroup[];
  limit?: number;
  sort_by?: string;
  sort_desc?: boolean;
}

// ---------------------------------------------------------------------------
// API class
// ---------------------------------------------------------------------------

/**
 * Typed client for the smart playlist endpoints.
 *
 * @since 3.3
 */
export class SmartPlaylistsApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/smart-playlists` → unwraps `{ smart_playlists }`. */
  async list(): Promise<SmartPlaylist[]> {
    const { smart_playlists } = await this.client.get<{
      smart_playlists: SmartPlaylist[];
    }>('/api/v1/smart-playlists');
    return smart_playlists;
  }

  /** `GET /api/v1/smart-playlists/{id}` → unwraps `{ smart_playlist }`. */
  async get(id: string): Promise<SmartPlaylist> {
    const { smart_playlist } = await this.client.get<{
      smart_playlist: SmartPlaylist;
    }>(`/api/v1/smart-playlists/${encodeURIComponent(id)}`);
    return smart_playlist;
  }

  /** `POST /api/v1/smart-playlists` → `{ smart_playlist }`. */
  create(input: CreateSmartPlaylistInput): Promise<{ smart_playlist: SmartPlaylist }> {
    return this.client.post<{ smart_playlist: SmartPlaylist }>(
      '/api/v1/smart-playlists',
      input,
    );
  }

  /** `PUT /api/v1/smart-playlists/{id}` → `{ smart_playlist }`. */
  update(
    id: string,
    input: UpdateSmartPlaylistInput,
  ): Promise<{ smart_playlist: SmartPlaylist }> {
    return this.client.put<{ smart_playlist: SmartPlaylist }>(
      `/api/v1/smart-playlists/${encodeURIComponent(id)}`,
      input,
    );
  }

  /** `DELETE /api/v1/smart-playlists/{id}` → `{ message }`. */
  remove(id: string): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/smart-playlists/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/smart-playlists/{id}/preview` → `{ media_items, total }`.
   * Previews the results of a rules_json against a specific smart playlist.
   * @since 3.3
   */
  preview(
    id: string,
    rulesJson: RuleGroup[],
  ): Promise<{ media_items: unknown[]; total: number }> {
    return this.client.post<{ media_items: unknown[]; total: number }>(
      `/api/v1/smart-playlists/${encodeURIComponent(id)}/preview`,
      { rules_json: rulesJson },
    );
  }
}
