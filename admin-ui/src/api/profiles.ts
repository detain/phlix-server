/**
 * ProfilesApi — typed wrapper over the existing {@link ApiClient} for the
 * admin profile CRUD + PIN management endpoints
 * (`/api/v1/admin/users/{userId}/profiles` and `/api/v1/admin/profiles/{id}`).
 *
 * Every method maps 1:1 to an endpoint shipped by `AdminProfileController`
 * (1.2b) and parses the EXACT response envelope that controller returns.
 * `pin_hash` is always `null` in GET responses — it is write-only from the
 * UI's perspective.
 *
 * Contract notes (traced from source, not assumed):
 *  - `rating` is an integer 0-6 mapping to content labels
 *    (0=G, 1=PG, 2=PG-13, 3=R, 4=NC-17, 5=X, 6=UNRATED).
 *  - `POST /profiles/{id}/pin` accepts `{ pin: "1234" }` — 4 or 6 digits.
 *  - `DELETE /profiles/{id}/pin` clears the PIN.
 *  - Max 5 profiles per user enforced server-side; a 400 returns
 *    `{ error: "Maximum 5 profiles allowed" }`.
 *
 * @since 1.2c
 */
import type { ApiClient } from './client';

/**
 * A user profile row as returned by `AdminProfileController`.
 * `pin_hash` is always `null` in GET responses — write-only from the UI.
 *
 * @since 1.2c
 */
export interface Profile {
  id: number;
  user_id: number;
  name: string;
  /** Always null in GET responses — PIN is write-only. */
  pin_hash: null;
  /** 0=G, 1=PG, 2=PG-13, 3=R, 4=NC-17, 5=X, 6=UNRATED */
  rating: number;
  created_at: string;
}

/** Rating display labels. @since 1.2c */
export const RATING_LABELS: Record<number, string> = {
  0: 'G — General Audiences',
  1: 'PG — Parental Guidance',
  2: 'PG-13 — Parents Strongly Cautioned',
  3: 'R — Restricted',
  4: 'NC-17 — No One 17 & Under',
  5: 'X — Adult',
  6: 'UNRATED — Unrated Content',
};

/** Rating options for select elements. @since 1.2c */
export const RATING_OPTIONS = Object.entries(RATING_LABELS).map(
  ([value, label]) => ({
    value: Number(value),
    label,
  }),
);

/** Body accepted by {@link ProfilesApi.createForUser}. @since 1.2c */
export interface CreateProfileInput {
  name: string;
  /** 0=G … 6=UNRATED */
  rating: number;
}

/** Body accepted by {@link ProfilesApi.update}. @since 1.2c */
export interface UpdateProfileInput {
  name?: string;
  rating?: number;
}

/**
 * Typed client for the admin profile endpoints.
 *
 * @since 1.2c
 */
export class ProfilesApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/admin/users/{userId}/profiles` → unwraps `{ profiles }`. */
  async listForUser(userId: number): Promise<Profile[]> {
    const { profiles } = await this.client.get<{ profiles: Profile[] }>(
      `/api/v1/admin/users/${encodeURIComponent(userId)}/profiles`,
    );
    return profiles;
  }

  /**
   * `POST /api/v1/admin/users/{userId}/profiles` → `201 { profile_id, message }`.
   */
  createForUser(
    userId: number,
    input: CreateProfileInput,
  ): Promise<{ profile_id: number; message: string }> {
    return this.client.post<{ profile_id: number; message: string }>(
      `/api/v1/admin/users/${encodeURIComponent(userId)}/profiles`,
      input,
    );
  }

  /** `GET /api/v1/admin/profiles/{id}` → unwraps `{ profile }`. */
  async get(id: number): Promise<Profile> {
    const { profile } = await this.client.get<{ profile: Profile }>(
      `/api/v1/admin/profiles/${encodeURIComponent(id)}`,
    );
    return profile;
  }

  /** `PUT /api/v1/admin/profiles/{id}` → `{ message }`. */
  update(
    id: number,
    input: UpdateProfileInput,
  ): Promise<{ message: string }> {
    return this.client.put<{ message: string }>(
      `/api/v1/admin/profiles/${encodeURIComponent(id)}`,
      input,
    );
  }

  /** `DELETE /api/v1/admin/profiles/{id}` → `{ message }`. */
  remove(id: number): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/admin/profiles/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/profiles/{id}/pin` → `{ message }`.
   * Body: `{ pin: "1234" }` — 4 or 6 digit PIN.
   */
  setPin(id: number, pin: string): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/admin/profiles/${encodeURIComponent(id)}/pin`,
      { pin },
    );
  }

  /** `DELETE /api/v1/admin/profiles/{id}/pin` → `{ message }`. */
  deletePin(id: number): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/admin/profiles/${encodeURIComponent(id)}/pin`,
    );
  }
}
