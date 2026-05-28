/**
 * UsersApi — typed wrapper over the existing {@link ApiClient} for the
 * admin user CRUD + set-admin + reset-password endpoints
 * (`/api/v1/admin/users/*`).
 *
 * Every method maps 1:1 to an endpoint shipped by `AdminUserController`
 * (1.2a) and parses the EXACT response envelope that controller
 * returns — unwrapping the single-key wrappers (`{ users }`, `{ user }`,
 * `{ user_id }`, `{ message }`, `{ message, new_password }`) so callers
 * receive the bare domain object. Non-2xx responses throw {@link ApiError}
 * via the shared client.
 *
 * Contract notes (traced from source, not assumed):
 *  - `is_admin` is TINYINT(1) on the DB — wire value is `0` or `1`, never
 *    a JSON boolean. The typed interface reflects this.
 *  - `POST /{id}/set-admin` sends `{ is_admin: boolean }` (a real boolean,
 *    not 0/1) and the controller casts it server-side.
 *  - `resetPassword()` returns `{ message, new_password }` — the UI shows
 *    the plaintext password so the admin can share it.
 *
 * @since 1.2c
 */
import type { ApiClient } from './client';

/**
 * A server user row as returned by `AdminUserController`.
 *
 * @since 1.2c
 */
export interface User {
  id: number;
  username: string;
  email: string;
  is_admin: 0 | 1;
  created_at: string;
  updated_at: string;
}

/** Body accepted by {@link UsersApi.create}. @since 1.2c */
export interface CreateUserInput {
  username: string;
  email: string;
  password: string;
  /** Defaults to false when omitted. */
  is_admin?: boolean;
}

/** Body accepted by {@link UsersApi.update}. @since 1.2c */
export interface UpdateUserInput {
  username?: string;
  email?: string;
  /** Optional — omit to keep the current password. */
  password?: string;
}

/**
 * Typed client for the admin user endpoints.
 *
 * @since 1.2c
 */
export class UsersApi {
  constructor(private readonly client: ApiClient) {}

  /** `GET /api/v1/admin/users` → unwraps `{ users }`. */
  async list(): Promise<User[]> {
    const { users } = await this.client.get<{ users: User[] }>(
      '/api/v1/admin/users',
    );
    return users;
  }

  /** `GET /api/v1/admin/users/{id}` → unwraps `{ user }`. */
  async get(id: number): Promise<User> {
    const { user } = await this.client.get<{ user: User }>(
      `/api/v1/admin/users/${encodeURIComponent(id)}`,
    );
    return user;
  }

  /** `POST /api/v1/admin/users` → `201 { user_id, message }`. */
  create(input: CreateUserInput): Promise<{ user_id: number; message: string }> {
    return this.client.post<{ user_id: number; message: string }>(
      '/api/v1/admin/users',
      input,
    );
  }

  /** `PUT /api/v1/admin/users/{id}` → `{ message }`. */
  update(
    id: number,
    input: UpdateUserInput,
  ): Promise<{ message: string }> {
    return this.client.put<{ message: string }>(
      `/api/v1/admin/users/${encodeURIComponent(id)}`,
      input,
    );
  }

  /** `DELETE /api/v1/admin/users/{id}` → `{ message }`. */
  remove(id: number): Promise<{ message: string }> {
    return this.client.delete<{ message: string }>(
      `/api/v1/admin/users/${encodeURIComponent(id)}`,
    );
  }

  /**
   * `POST /api/v1/admin/users/{id}/set-admin` → `{ message }`.
   * Sends `{ is_admin: boolean }` (real boolean, not 0/1).
   */
  setAdmin(id: number, isAdmin: boolean): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `/api/v1/admin/users/${encodeURIComponent(id)}/set-admin`,
      { is_admin: isAdmin },
    );
  }

  /**
   * `POST /api/v1/admin/users/{id}/reset-password` → `{ message, new_password }`.
   * The plaintext password is only available in this response.
   */
  resetPassword(
    id: number,
  ): Promise<{ message: string; new_password: string }> {
    return this.client.post<{ message: string; new_password: string }>(
      `/api/v1/admin/users/${encodeURIComponent(id)}/reset-password`,
    );
  }
}
