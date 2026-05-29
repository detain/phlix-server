import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { HistoryApi } from './history';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: HistoryApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new HistoryApi(client), calls };
}

const sampleItem: import('./history').RecentlyWatchedItem = {
  id: 'wh-1',
  media_item_id: 'media-1',
  name: 'Test Movie',
  title: 'Test Movie',
  media_type: 'movie',
  type: 'movie',
  progress_percent: 45.5,
  last_watched_at: '2026-05-28T10:30:00Z',
  thumbnail_url: 'https://example.com/thumb.jpg',
  poster_url: 'https://example.com/poster.jpg',
};

describe('HistoryApi', () => {
  it('getRecentlyWatched() GETs /api/v1/users/me/recently-watched and unwraps { items }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { items: [sampleItem] } },
    ]);

    const result = await api.getRecentlyWatched();

    expect(calls[0]!.url).toBe('/api/v1/users/me/recently-watched');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([sampleItem]);
  });

  it('removeFromHistory(id) DELETEs /api/v1/users/me/history/{mediaItemId}', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { message: 'Removed from watch history' } },
    ]);

    const result = await api.removeFromHistory('media-1');

    expect(calls[0]!.url).toBe('/api/v1/users/me/history/media-1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'Removed from watch history' });
  });

  it('clearHistory() DELETEs /api/v1/users/me/history', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { message: 'Watch history cleared' } },
    ]);

    const result = await api.clearHistory();

    expect(calls[0]!.url).toBe('/api/v1/users/me/history');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'Watch history cleared' });
  });

  it('throws ApiError on a 4xx for getRecentlyWatched()', async () => {
    const { api } = makeApi([
      { status: 401, body: { error: 'Unauthorized' } },
    ]);

    await expect(api.getRecentlyWatched()).rejects.toBeInstanceOf(ApiError);
  });

  it('throws ApiError on a 4xx for removeFromHistory()', async () => {
    const { api } = makeApi([
      { status: 404, body: { error: 'Item not found in watch history' } },
    ]);

    await expect(api.removeFromHistory('media-999')).rejects.toBeInstanceOf(
      ApiError,
    );
  });

  it('throws ApiError on a 4xx for clearHistory()', async () => {
    const { api } = makeApi([
      { status: 401, body: { error: 'Unauthorized' } },
    ]);

    await expect(api.clearHistory()).rejects.toBeInstanceOf(ApiError);
  });

  it('encodes path-unsafe media item IDs in removeFromHistory()', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { message: 'Removed from watch history' } },
    ]);

    await api.removeFromHistory('a/b c');

    expect(calls[0]!.url).toBe('/api/v1/users/me/history/a%2Fb%20c');
  });
});
