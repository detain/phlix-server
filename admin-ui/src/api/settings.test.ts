import { describe, expect, it } from 'vitest';
import { SettingsApi } from './settings';
import { ApiClient } from './client';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function makeClient(responses: Array<{ status: number; body: unknown }>): {
  api: SettingsApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new SettingsApi(client), calls };
}

describe('SettingsApi', () => {
  it('get() issues GET /api/v1/admin/settings and unwraps envelope fields', async () => {
    const mockSettings = {
      'hwaccel.enabled': true,
      'tmdb.api_key': 'secret',
    };
    const { api, calls } = makeClient([
      {
        status: 200,
        body: {
          success: true,
          data: {
            settings: mockSettings,
            overridden: ['hwaccel.enabled'],
            types: { 'hwaccel.enabled': 'bool', 'tmdb.api_key': 'string' },
          },
        },
      },
    ]);

    const result = await api.get();

    expect(calls[0]!.init?.method).toBe('GET');
    expect(calls[0]!.url).toContain('/api/v1/admin/settings');
    expect(result.settings).toEqual(mockSettings);
    expect(result.overridden).toEqual(['hwaccel.enabled']);
    expect(result.types).toEqual({
      'hwaccel.enabled': 'bool',
      'tmdb.api_key': 'string',
    });
  });

  it('save() issues PUT with correct body and unwraps success envelope', async () => {
    const { api, calls } = makeClient([
      {
        status: 200,
        body: {
          success: true,
          message: 'Settings saved.',
          data: {
            settings: { 'hwaccel.enabled': false },
            overridden: ['hwaccel.enabled'],
          },
        },
      },
    ]);

    const result = await api.save({ 'hwaccel.enabled': false });

    expect(calls[0]!.init?.method).toBe('PUT');
    expect(calls[0]!.url).toContain('/api/v1/admin/settings');
    const reqBody = JSON.parse(calls[0]!.init!.body as string);
    expect(reqBody).toEqual({ settings: { 'hwaccel.enabled': false } });
    expect(result.settings).toEqual({ 'hwaccel.enabled': false });
    expect(result.overridden).toEqual(['hwaccel.enabled']);
  });

  it('save() throws ApiError on 400 with per-field errors', async () => {
    const { api } = makeClient([
      {
        status: 400,
        body: {
          success: false,
          error: 'Validation failed',
          errors: { 'discovery.discovery_port': 'Must be between 1 and 65535' },
        },
      },
    ]);

    await expect(api.save({ 'discovery.discovery_port': 0 })).rejects.toThrow(
      'Validation failed',
    );
  });

  it('get() throws ApiError on 500', async () => {
    const { api } = makeClient([
      {
        status: 500,
        body: { success: false, error: 'Internal server error' },
      },
    ]);

    await expect(api.get()).rejects.toThrow('Internal server error');
  });
});
