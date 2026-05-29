import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { SyncPlayApi } from './syncPlay';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: SyncPlayApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new SyncPlayApi(client), calls };
}

const sampleGroup: import('./syncPlay').SyncPlayGroup = {
  id: 'sp_abc123',
  name: 'Movie Night',
  member_count: 3,
  has_password: false,
  current_media: null,
  is_playing: false,
};

const sampleGroupState: import('./syncPlay').SyncPlayGroupState = {
  id: 'sp_abc123',
  name: 'Movie Night',
  host_id: 'user-1',
  has_password: false,
  members: [
    { id: 'user-1', name: 'Alice', is_host: true, joined_at: 1717000000 },
    { id: 'user-2', name: 'Bob', is_host: false, joined_at: 1717000010 },
  ],
  playback_state: {
    state: 'paused',
    position: 120000,
    server_time: 1717000100,
  },
  queue: [],
  created_at: 1717000000,
  last_activity: 1717000100,
};

describe('SyncPlayApi', () => {
  it('listGroups() GETs /api/v1/syncplay/groups and unwraps { groups }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { groups: [sampleGroup] } },
    ]);

    const result = await api.listGroups();

    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([sampleGroup]);
  });

  it('createGroup() POSTs the body and parses { success, group }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, group: sampleGroupState } },
    ]);

    const result = await api.createGroup({ name: 'Movie Night' });

    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(JSON.stringify({ name: 'Movie Night' }));
    expect(result).toEqual({ success: true, group: sampleGroupState });
  });

  it('createGroup() with password POSTs the body with password', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, group: { ...sampleGroupState, has_password: true } } },
    ]);

    await api.createGroup({ name: 'Private Watch Party', password: 'secret' });

    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ name: 'Private Watch Party', password: 'secret' });
  });

  it('getGroup() GETs /api/v1/syncplay/groups/{id} and unwraps { group }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { group: sampleGroupState } },
    ]);

    const result = await api.getGroup('sp_abc123');

    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups/sp_abc123');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result.group).toEqual(sampleGroupState);
  });

  it('joinGroup() POSTs to /join and parses { success, group }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, group: sampleGroupState } },
    ]);

    const result = await api.joinGroup('sp_abc123');

    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups/sp_abc123/join');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(JSON.stringify({}));
    expect(result).toEqual({ success: true, group: sampleGroupState });
  });

  it('joinGroup() with password POSTs the body with password', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, group: sampleGroupState } },
    ]);

    await api.joinGroup('sp_abc123', { password: 'secret' });

    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ password: 'secret' });
  });

  it('leaveGroup() POSTs to /leave and parses { success, message }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, message: 'Left the group' } },
    ]);

    const result = await api.leaveGroup('sp_abc123');

    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups/sp_abc123/leave');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(JSON.stringify({}));
    expect(result).toEqual({ success: true, message: 'Left the group' });
  });

  it('throws ApiError on a 4xx', async () => {
    const { api } = makeApi([
      { status: 400, body: { error: 'Group not found' } },
    ]);

    await expect(api.getGroup('sp_notfound')).rejects.toBeInstanceOf(ApiError);
  });

  it('encodes path-unsafe ids in the URL', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { groups: [] } }]);
    await api.listGroups();
    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups');
  });

  it('encodes path-unsafe group id in getGroup()', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { group: sampleGroupState } }]);
    await api.getGroup('sp_abc/123');
    expect(calls[0]!.url).toBe('/api/v1/syncplay/groups/sp_abc%2F123');
  });
});
