import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { ProfilesApi, RATING_OPTIONS } from './profiles';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: ProfilesApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new ProfilesApi(client), calls };
}

const sampleProfile = {
  id: 1,
  user_id: 1,
  name: 'Alice Adult',
  pin_hash: null,
  rating: 3,
  created_at: '2026-05-27T00:00:00Z',
};

describe('ProfilesApi', () => {
  it('exposes RATING_OPTIONS with 7 options (0-6)', () => {
    expect(RATING_OPTIONS).toHaveLength(7);
    expect(RATING_OPTIONS[0]).toEqual({ value: 0, label: 'G — General Audiences' });
    expect(RATING_OPTIONS[6]).toEqual({ value: 6, label: 'UNRATED — Unrated Content' });
  });

  it('listForUser() GETs /api/v1/admin/users/{userId}/profiles and unwraps { profiles }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { profiles: [sampleProfile] } },
    ]);

    const result = await api.listForUser(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1/profiles');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([sampleProfile]);
  });

  it('createForUser() POSTs /api/v1/admin/users/{userId}/profiles and parses 201 { profile_id, message }', async () => {
    const { api, calls } = makeApi([
      { status: 201, body: { profile_id: 5, message: 'Profile created.' } },
    ]);

    const result = await api.createForUser(1, { name: 'Kids', rating: 0 });

    expect(calls[0]!.url).toBe('/api/v1/admin/users/1/profiles');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(JSON.stringify({ name: 'Kids', rating: 0 }));
    expect(result).toEqual({ profile_id: 5, message: 'Profile created.' });
  });

  it('get(id) GETs /api/v1/admin/profiles/{id} and unwraps { profile }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { profile: sampleProfile } },
    ]);

    const result = await api.get(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/profiles/1');
    expect(result).toEqual(sampleProfile);
  });

  it('update(id, input) PUTs only provided fields and returns { message }', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'updated' } }]);

    const result = await api.update(1, { name: 'Alice Restricted', rating: 4 });

    expect(calls[0]!.url).toBe('/api/v1/admin/profiles/1');
    expect(calls[0]!.init!.method).toBe('PUT');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ name: 'Alice Restricted', rating: 4 });
    expect(result).toEqual({ message: 'updated' });
  });

  it('update() sends only the fields provided (partial)', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'updated' } }]);

    await api.update(1, { rating: 5 });

    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).not.toHaveProperty('name');
    expect(body).toHaveProperty('rating', 5);
  });

  it('remove(id) DELETEs /api/v1/admin/profiles/{id}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'deleted' } }]);

    const result = await api.remove(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/profiles/1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'deleted' });
  });

  it('setPin(id, pin) POSTs /profiles/{id}/pin with { pin }', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'PIN set.' } }]);

    const result = await api.setPin(1, '1234');

    expect(calls[0]!.url).toBe('/api/v1/admin/profiles/1/pin');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(JSON.stringify({ pin: '1234' }));
    expect(result).toEqual({ message: 'PIN set.' });
  });

  it('setPin() accepts 6-digit PINs', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'PIN set.' } }]);

    await api.setPin(1, '123456');

    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ pin: '123456' });
  });

  it('deletePin(id) DELETEs /api/v1/admin/profiles/{id}/pin', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'PIN cleared.' } }]);

    const result = await api.deletePin(1);

    expect(calls[0]!.url).toBe('/api/v1/admin/profiles/1/pin');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'PIN cleared.' });
  });

  it('throws ApiError on a 4xx for get', async () => {
    const { api } = makeApi([{ status: 404, body: { error: 'Profile not found' } }]);

    await expect(api.get(999)).rejects.toBeInstanceOf(ApiError);
  });

  it('throws ApiError on a 400 for max profiles', async () => {
    const { api } = makeApi([
      { status: 400, body: { error: 'Maximum 5 profiles allowed' } },
    ]);

    await expect(api.createForUser(1, { name: 'Too Many', rating: 0 })).rejects.toBeInstanceOf(ApiError);
  });

  it('throws ApiError on a 400 for setPin with invalid pin', async () => {
    const { api } = makeApi([
      { status: 400, body: { error: 'PIN must be 4 or 6 digits' } },
    ]);

    await expect(api.setPin(1, '123')).rejects.toBeInstanceOf(ApiError);
  });
});
