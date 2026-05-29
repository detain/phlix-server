import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import {
  SmartPlaylistsApi,
  RULE_FIELDS,
  STRING_OPS,
  NUMERIC_OPS,
} from './smartPlaylists';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: SmartPlaylistsApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new SmartPlaylistsApi(client), calls };
}

const sampleRules = [
  { logic: 'and' as const, rules: [
    { field: 'title', op: 'contains', value: 'Action' },
    { field: 'year', op: 'gte', value: 2020 },
  ]},
];

const samplePlaylist: import('./smartPlaylists').SmartPlaylist = {
  id: 'sp-1',
  name: 'Action Movies',
  library_id: 'lib-1',
  rules_json: sampleRules,
  limit: 50,
  sort_by: 'year',
  sort_desc: true,
  item_count: 24,
  created_at: '2026-05-27T00:00:00Z',
};

describe('SmartPlaylistsApi', () => {
  it('exposes RULE_FIELDS constant', () => {
    expect(RULE_FIELDS).toEqual([
      'title', 'year', 'genre', 'rating', 'runtime', 'added_at', 'play_count', 'media_type',
    ]);
  });

  it('exposes STRING_OPS constant', () => {
    expect(STRING_OPS).toEqual(['contains', 'equals', 'starts_with', 'ends_with']);
  });

  it('exposes NUMERIC_OPS constant', () => {
    expect(NUMERIC_OPS).toEqual(['eq', 'ne', 'gt', 'gte', 'lt', 'lte']);
  });

  it('list() GETs /api/v1/smart-playlists and unwraps { smart_playlists }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { smart_playlists: [samplePlaylist] } },
    ]);

    const result = await api.list();

    expect(calls[0]!.url).toBe('/api/v1/smart-playlists');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([samplePlaylist]);
  });

  it('get(id) GETs /api/v1/smart-playlists/{id} and unwraps { smart_playlist }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { smart_playlist: samplePlaylist } },
    ]);

    const result = await api.get('sp-1');

    expect(calls[0]!.url).toBe('/api/v1/smart-playlists/sp-1');
    expect(result).toEqual(samplePlaylist);
  });

  it('create() POSTs the body and parses { smart_playlist }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { smart_playlist: samplePlaylist } },
    ]);

    const result = await api.create({
      name: 'Action Movies',
      library_id: 'lib-1',
      rules_json: sampleRules,
      limit: 50,
      sort_by: 'year',
      sort_desc: true,
    });

    expect(calls[0]!.url).toBe('/api/v1/smart-playlists');
    expect(calls[0]!.init!.method).toBe('POST');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({
      name: 'Action Movies',
      library_id: 'lib-1',
      rules_json: sampleRules,
      limit: 50,
      sort_by: 'year',
      sort_desc: true,
    });
    expect(result.smart_playlist).toEqual(samplePlaylist);
  });

  it('update() PUTs the body and parses { smart_playlist }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { smart_playlist: { ...samplePlaylist, name: 'Updated' } } },
    ]);

    const result = await api.update('sp-1', {
      name: 'Updated',
      rules_json: sampleRules,
    });

    expect(calls[0]!.url).toBe('/api/v1/smart-playlists/sp-1');
    expect(calls[0]!.init!.method).toBe('PUT');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body.name).toBe('Updated');
    expect(result.smart_playlist.name).toBe('Updated');
  });

  it('remove() DELETEs /api/v1/smart-playlists/{id}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'deleted' } }]);

    const result = await api.remove('sp-1');

    expect(calls[0]!.url).toBe('/api/v1/smart-playlists/sp-1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'deleted' });
  });

  it('preview() POSTs rules_json and returns { media_items, total }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { media_items: [{ id: 'item-1' }], total: 1 } },
    ]);

    const result = await api.preview('sp-1', sampleRules);

    expect(calls[0]!.url).toBe('/api/v1/smart-playlists/sp-1/preview');
    expect(calls[0]!.init!.method).toBe('POST');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).toEqual({ rules_json: sampleRules });
    expect(result.media_items).toEqual([{ id: 'item-1' }]);
    expect(result.total).toBe(1);
  });

  it('throws ApiError on a 4xx', async () => {
    const { api } = makeApi([
      { status: 404, body: { error: 'Not found' } },
    ]);

    await expect(api.get('sp-999')).rejects.toBeInstanceOf(ApiError);
  });

  it('encodes path-unsafe ids in the URL', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { smart_playlists: [] } },
    ]);
    await api.get('a/b c');
    expect(calls[0]!.url).toBe('/api/v1/smart-playlists/a%2Fb%20c');
  });

  it('create() works without optional fields', async () => {
    const minimalPlaylist = { ...samplePlaylist, limit: undefined, sort_by: undefined };
    const { api, calls } = makeApi([
      { status: 200, body: { smart_playlist: minimalPlaylist } },
    ]);

    const result = await api.create({
      name: 'Minimal',
      library_id: 'lib-1',
      rules_json: sampleRules,
    });

    expect(calls[0]!.init!.method).toBe('POST');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).not.toHaveProperty('limit');
    expect(body).not.toHaveProperty('sort_by');
    expect(result.smart_playlist).toEqual(minimalPlaylist);
  });
});
