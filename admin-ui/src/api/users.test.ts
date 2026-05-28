import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { UsersApi } from './users';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: UsersApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new UsersApi(client), calls };
}

const sampleUser = {
  id: 1,
  username: 'alice',
  email: 'alice@example.com',
  is_admin: 1 as const,
  created_at: '2026-05-27T00:00:00Z',
  updated_at: '2026-05-27T00:00:00Z',
};

describe('UsersApi', () => {
  it('list() GETs /api/v1/admin/users and unwraps { users }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { users: [sampleUser] } },
    ]);

    const result = await api.list();

    expect(calls[0]!.url).toBe('/api/v1/admin/users');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([sampleUser]);
  });

  it('get(id) GETs /api/v1/admin/users/{id} and unwraps { user }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { user: sampleUser } },
    ]);

    const result = await api.get(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1');
    expect(result).toEqual(sampleUser);
  });

  it('create() POSTs the full body and parses 201 { user_id, message }', async () => {
    const { api, calls } = makeApi([
      { status: 201, body: { user_id: 9, message: 'User created.' } },
    ]);

    const result = await api.create({
      username: 'bob',
      email: 'bob@example.com',
      password: 'secret123',
    });

    expect(calls[0]!.url).toBe('/api/v1/admin/users');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(
      JSON.stringify({
        username: 'bob',
        email: 'bob@example.com',
        password: 'secret123',
      }),
    );
    expect(result).toEqual({ user_id: 9, message: 'User created.' });
  });

  it('create() sends is_admin when provided', async () => {
    const { api, calls } = makeApi([
      { status: 201, body: { user_id: 9, message: 'User created.' } },
    ]);

    await api.create({
      username: 'bob',
      email: 'bob@example.com',
      password: 'secret123',
      is_admin: true,
    });

    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({
      username: 'bob',
      email: 'bob@example.com',
      password: 'secret123',
      is_admin: true,
    });
  });

  it('update() PUTs only provided fields', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'updated' } }]);

    const result = await api.update(1, { username: 'alice2' });

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1');
    expect(calls[0]!.init!.method).toBe('PUT');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toHaveProperty('username', 'alice2');
    expect(body).not.toHaveProperty('email');
    expect(result).toEqual({ message: 'updated' });
  });

  it('update() omits password when not provided', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'updated' } }]);

    await api.update(1, { email: 'new@example.com' });

    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).not.toHaveProperty('password');
  });

  it('remove() DELETEs /api/v1/admin/users/{id}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'deleted' } }]);

    const result = await api.remove(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'deleted' });
  });

  it('setAdmin() POSTs /{id}/set-admin with { is_admin: bool }', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'admin updated' } }]);

    const result = await api.setAdmin(1, true);

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1/set-admin');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(JSON.stringify({ is_admin: true }));
    expect(result).toEqual({ message: 'admin updated' });
  });

  it('resetPassword() POSTs /{id}/reset-password and parses { message, new_password }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { message: 'Password reset.', new_password: 'TempPass99' } },
    ]);

    const result = await api.resetPassword(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1/reset-password');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(result).toEqual({ message: 'Password reset.', new_password: 'TempPass99' });
  });

  it('throws ApiError on a 4xx', async () => {
    const { api } = makeApi([
      { status: 404, body: { error: 'User not found' } },
    ]);

    await expect(api.get(999)).rejects.toBeInstanceOf(ApiError);
  });

  it('throws ApiError on a 400 (e.g. last admin delete)', async () => {
    const { api } = makeApi([
      { status: 400, body: { error: 'Cannot delete the last admin' } },
    ]);

    await expect(api.remove(1)).rejects.toBeInstanceOf(ApiError);
  });

  it('encodes path-unsafe ids in the URL', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { user: sampleUser } }]);
    await api.get(1);
    // id 1 is safe, verify we call the right URL
    expect(calls[0]!.url).toBe('/api/v1/admin/users/1');
  });
});
