import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { DashboardApi } from './dashboard';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: DashboardApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new DashboardApi(client), calls };
}

const nowPlaying = {
  session_id: 'sess-1',
  user_id: 'u1',
  user_name: 'Alice',
  media_item_id: 'm1',
  media_title: 'Movie One',
  media_type: 'movie',
  progress_percent: 45,
  started_at: '2026-05-28T10:00:00Z',
};

const topUser = {
  user_id: 'u1',
  user_name: 'Alice',
  total_watch_time_seconds: 3661,
  play_count: 12,
  last_seen: '2026-05-28T10:00:00Z',
};

const topMedia = {
  media_item_id: 'm1',
  media_title: 'Movie One',
  media_type: 'movie',
  play_count: 42,
  total_duration_seconds: 7200,
  last_played_at: '2026-05-28T09:00:00Z',
};

const storageSummary = {
  media_type: 'movie',
  item_count: 150,
  total_bytes: 1_000_000_000_000,
  transcode_cache_bytes: 5_000_000_000,
};

const activityEvent = {
  id: 'evt-1',
  event_type: 'playback',
  user_id: 'u1',
  user_name: 'Alice',
  media_item_id: 'm1',
  media_title: 'Movie One',
  created_at: '2026-05-28T10:00:00Z',
  details: 'Started playback',
};

describe('DashboardApi', () => {
  it('getNowPlaying() GETs /api/v1/admin/dashboard/now-playing and unwraps { success, data }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [nowPlaying] } },
    ]);

    const result = await api.getNowPlaying();

    expect(calls[0]!.url).toBe('/api/v1/admin/dashboard/now-playing');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([nowPlaying]);
  });

  it('getTopUsers() GETs /api/v1/admin/dashboard/top-users with query params', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [topUser] } },
    ]);

    const result = await api.getTopUsers(5, 7);

    expect(calls[0]!.url).toContain('/api/v1/admin/dashboard/top-users');
    expect(calls[0]!.url).toContain('limit=5');
    expect(calls[0]!.url).toContain('days=7');
    expect(result).toEqual([topUser]);
  });

  it('getTopUsers() omits params when not provided', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [topUser] } },
    ]);

    await api.getTopUsers();

    expect(calls[0]!.url).toBe('/api/v1/admin/dashboard/top-users');
  });

  it('getTopMedia() GETs /api/v1/admin/dashboard/top-media with query params', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [topMedia] } },
    ]);

    const result = await api.getTopMedia(10, 30);

    expect(calls[0]!.url).toContain('/api/v1/admin/dashboard/top-media');
    expect(calls[0]!.url).toContain('limit=10');
    expect(calls[0]!.url).toContain('days=30');
    expect(result).toEqual([topMedia]);
  });

  it('getTopMedia() omits params when not provided', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [topMedia] } },
    ]);

    await api.getTopMedia();

    expect(calls[0]!.url).toBe('/api/v1/admin/dashboard/top-media');
  });

  it('getStorage() GETs /api/v1/admin/dashboard/storage and unwraps { success, data }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [storageSummary] } },
    ]);

    const result = await api.getStorage();

    expect(calls[0]!.url).toBe('/api/v1/admin/dashboard/storage');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([storageSummary]);
  });

  it('getActivity() GETs /api/v1/admin/dashboard/activity with limit param', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [activityEvent] } },
    ]);

    const result = await api.getActivity(20);

    expect(calls[0]!.url).toContain('/api/v1/admin/dashboard/activity');
    expect(calls[0]!.url).toContain('limit=20');
    expect(result).toEqual([activityEvent]);
  });

  it('getActivity() omits limit when not provided', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { success: true, data: [activityEvent] } },
    ]);

    await api.getActivity();

    expect(calls[0]!.url).toBe('/api/v1/admin/dashboard/activity');
  });

  it('throws ApiError on a 4xx', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' } },
    ]);

    await expect(api.getNowPlaying()).rejects.toBeInstanceOf(ApiError);
  });
});
