import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { StatsApi } from './stats';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: StatsApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new StatsApi(client), calls };
}

const playbackStat = {
  date: '2026-05-28',
  play_count: 15,
  total_duration_seconds: 36000,
  completed_count: 8,
};

const statsTopUser = {
  user_id: 'u1',
  total_watch_time_seconds: 7320,
  play_count: 24,
};

const statsTopMedia = {
  media_item_id: 'm1',
  play_count: 42,
  total_duration_seconds: 86400,
};

describe('StatsApi', () => {
  it('getPlaybackStats() GETs /api/v1/admin/stats/playback with optional from/to params', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [playbackStat] } },
    ]);

    const result = await api.getPlaybackStats('-30 days', 'now');

    expect(calls[0]!.url).toContain('/api/v1/admin/stats/playback');
    expect(calls[0]!.url).toContain('from=-30+days');
    expect(calls[0]!.url).toContain('to=now');
    expect(result).toEqual([playbackStat]);
  });

  it('getPlaybackStats() omits params when not provided', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [playbackStat] } },
    ]);

    await api.getPlaybackStats();

    expect(calls[0]!.url).toBe('/api/v1/admin/stats/playback');
  });

  it('getTopUsers() GETs /api/v1/admin/stats/top-users with limit and since', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [statsTopUser] } },
    ]);

    const result = await api.getTopUsers(10, '2024-01-01');

    expect(calls[0]!.url).toContain('/api/v1/admin/stats/top-users');
    expect(calls[0]!.url).toContain('limit=10');
    expect(calls[0]!.url).toContain('since=2024-01-01');
    expect(result).toEqual([statsTopUser]);
  });

  it('getTopUsers() omits params when not provided', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [statsTopUser] } },
    ]);

    await api.getTopUsers();

    expect(calls[0]!.url).toBe('/api/v1/admin/stats/top-users');
  });

  it('getTopMedia() GETs /api/v1/admin/stats/top-media with limit and since', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [statsTopMedia] } },
    ]);

    const result = await api.getTopMedia(10, '2024-01-01');

    expect(calls[0]!.url).toContain('/api/v1/admin/stats/top-media');
    expect(calls[0]!.url).toContain('limit=10');
    expect(calls[0]!.url).toContain('since=2024-01-01');
    expect(result).toEqual([statsTopMedia]);
  });

  it('getTopMedia() omits params when not provided', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [statsTopMedia] } },
    ]);

    await api.getTopMedia();

    expect(calls[0]!.url).toBe('/api/v1/admin/stats/top-media');
  });

  it('getStorageStats() GETs /api/v1/admin/stats/storage and returns empty array', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { data: [] } },
    ]);

    const result = await api.getStorageStats();

    expect(calls[0]!.url).toBe('/api/v1/admin/stats/storage');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([]);
  });

  it('throws ApiError on a 4xx', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' } },
    ]);

    await expect(api.getPlaybackStats()).rejects.toBeInstanceOf(ApiError);
  });
});
