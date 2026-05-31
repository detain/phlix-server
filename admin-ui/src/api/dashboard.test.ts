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

  it('getStorage() unwraps the real server shape { data: { items: [...] } }', async () => {
    // DashboardService::getStorageSummary() returns an OBJECT with the
    // per-type rows under `items` (plus aggregate *_bytes fields), NOT a bare
    // list. getStorage() must return `data.items` so StorageCard gets an array.
    const { api } = makeApi([
      {
        status: 200,
        body: {
          success: true,
          data: {
            movie_bytes: 1_000_000_000_000,
            transcode_cache_bytes: 5_000_000_000,
            items: [storageSummary],
            formatted_transcode_cache: '5 GB',
          },
        },
      },
    ]);

    const result = await api.getStorage();

    expect(result).toEqual([storageSummary]);
  });

  it('getStorage() falls back to [] when the payload has no items array', async () => {
    const { api } = makeApi([{ status: 200, body: { success: true, data: {} } }]);
    const result = await api.getStorage();
    expect(result).toEqual([]);
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

  // -------------------------------------------------------------------------
  // Server→SPA field normalisation (DashboardService uses different names)
  // -------------------------------------------------------------------------

  it('getTopUsers() normalises server field names (username, total_watch_time)', async () => {
    const { api } = makeApi([
      { status: 200, body: { success: true, data: [
        { user_id: 'u1', username: 'Alice', total_watch_time: 3661, play_count: 12 },
      ] } },
    ]);
    const result = await api.getTopUsers();
    expect(result).toEqual([
      { user_id: 'u1', user_name: 'Alice', total_watch_time_seconds: 3661, play_count: 12, last_seen: '' },
    ]);
  });

  it('getTopMedia() normalises server field names (title, type, total_duration)', async () => {
    const { api } = makeApi([
      { status: 200, body: { success: true, data: [
        { media_item_id: 'm1', title: 'Movie One', type: 'movie', play_count: 42, total_duration: 7200 },
      ] } },
    ]);
    const result = await api.getTopMedia();
    expect(result).toEqual([
      { media_item_id: 'm1', media_title: 'Movie One', media_type: 'movie', play_count: 42, total_duration_seconds: 7200, last_played_at: '' },
    ]);
  });

  it('getNowPlaying() normalises server field names (stream_id, username)', async () => {
    const { api } = makeApi([
      { status: 200, body: { success: true, data: [
        { stream_id: 'sess-1', user_id: 'u1', username: 'Alice', media_item_id: 'm1', media_title: 'Movie One', media_type: 'movie', progress_percent: 45 },
      ] } },
    ]);
    const result = await api.getNowPlaying();
    expect(result[0]).toMatchObject({ session_id: 'sess-1', user_name: 'Alice', media_title: 'Movie One', progress_percent: 45 });
  });

  it('getActivity() normalises occurred_at→created_at and pulls media_title out of details', async () => {
    const { api } = makeApi([
      { status: 200, body: { success: true, data: [
        { id: 'e1', event_type: 'playback_completed', user_id: 'u1', username: 'Alice',
          occurred_at: '2026-05-28T10:00:00Z', details: { media_title: 'Movie One', duration_seconds: 100 } },
      ] } },
    ]);
    const result = await api.getActivity();
    expect(result[0]).toMatchObject({
      id: 'e1', user_name: 'Alice', media_title: 'Movie One', created_at: '2026-05-28T10:00:00Z', details: '',
    });
  });
});
