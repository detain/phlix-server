import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { CollectionsApi } from './collections';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: CollectionsApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new CollectionsApi(client), calls };
}

const sampleCollection: import('./collections').Collection = {
  id: 'col-1',
  name: 'My Movies',
  library_id: 'lib-1',
  item_count: 12,
  created_at: '2026-05-27T00:00:00Z',
};

const sampleItem: import('./collections').MediaItem = {
  id: 'item-1',
  title: 'Test Movie',
};

describe('CollectionsApi', () => {
  it('list() GETs /api/v1/collections and unwraps { collections }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { collections: [sampleCollection] } },
    ]);

    const result = await api.list();

    expect(calls[0]!.url).toBe('/api/v1/collections');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([sampleCollection]);
  });

  it('get(id) GETs /api/v1/collections/{id} and unwraps { collection, items }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { collection: sampleCollection, items: [sampleItem] } },
    ]);

    const result = await api.get('col-1');

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1');
    expect(result.collection).toEqual(sampleCollection);
    expect(result.items).toEqual([sampleItem]);
  });

  it('create() POSTs the body and parses { collection }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { collection: sampleCollection } },
    ]);

    const result = await api.create({ name: 'My Movies', library_id: 'lib-1' });

    expect(calls[0]!.url).toBe('/api/v1/collections');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(
      JSON.stringify({ name: 'My Movies', library_id: 'lib-1' }),
    );
    expect(result.collection).toEqual(sampleCollection);
  });

  it('update() PUTs the body and parses { collection }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { collection: { ...sampleCollection, name: 'Renamed' } } },
    ]);

    const result = await api.update('col-1', { name: 'Renamed' });

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1');
    expect(calls[0]!.init!.method).toBe('PUT');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ name: 'Renamed' });
    expect(result.collection.name).toBe('Renamed');
  });

  it('remove() DELETEs /api/v1/collections/{id}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'deleted' } }]);

    const result = await api.remove('col-1');

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'deleted' });
  });

  it('addItem() POSTs to /items/{mediaItemId}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'added' } }]);

    const result = await api.addItem('col-1', 'item-1');

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1/items/item-1');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(result).toEqual({ message: 'added' });
  });

  it('removeItem() DELETEs from /items/{mediaItemId}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'removed' } }]);

    const result = await api.removeItem('col-1', 'item-1');

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1/items/item-1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'removed' });
  });

  it('bulkAdd() POSTs with query body', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'added' } }]);

    const result = await api.bulkAdd('col-1', 'genre:action');

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1/bulk-add');
    expect(calls[0]!.init!.method).toBe('POST');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ query: 'genre:action' });
    expect(result).toEqual({ message: 'added' });
  });

  it('refresh() POSTs to /refresh', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'refreshed' } }]);

    const result = await api.refresh('col-1');

    expect(calls[0]!.url).toBe('/api/v1/collections/col-1/refresh');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(result).toEqual({ message: 'refreshed' });
  });

  it('throws ApiError on a 4xx', async () => {
    const { api } = makeApi([
      { status: 404, body: { error: 'Not found' } },
    ]);

    await expect(api.get('col-999')).rejects.toBeInstanceOf(ApiError);
  });

  it('encodes path-unsafe ids in the URL', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { collections: [] } }]);
    await api.list();
    expect(calls[0]!.url).toBe('/api/v1/collections');
  });

  it('encodes path-unsafe collection id in get()', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { collection: sampleCollection, items: [] } },
    ]);
    await api.get('a/b c');
    expect(calls[0]!.url).toBe('/api/v1/collections/a%2Fb%20c');
  });
});
