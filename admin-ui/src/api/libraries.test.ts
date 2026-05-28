import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { LibrariesApi, LIBRARY_TYPES } from './libraries';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: LibrariesApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new LibrariesApi(client), calls };
}

const sampleLibrary = {
  id: 'lib-1',
  name: 'Movies',
  type: 'movie',
  paths: ['/media/movies'],
  options: {},
  created_at: '2026-05-27T00:00:00Z',
};

const sampleJob = {
  id: 'job-1',
  library_id: 'lib-1',
  type: 'scan' as const,
  status: 'running' as const,
  items_found: 0,
  items_added: 0,
  items_updated: 0,
  items_removed: 0,
  current_path: null,
  error: null,
  queued_at: '2026-05-27T00:00:00Z',
  started_at: '2026-05-27T00:00:01Z',
  completed_at: null,
};

describe('LibrariesApi', () => {
  it('exposes only the 5 DB-valid types (no `book`)', () => {
    expect(LIBRARY_TYPES).toEqual(['movie', 'series', 'music', 'photo', 'video']);
    expect(LIBRARY_TYPES).not.toContain('book');
  });

  it('list() GETs /api/v1/libraries and unwraps { libraries }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { libraries: [sampleLibrary] } },
    ]);

    const result = await api.list();

    expect(calls[0]!.url).toBe('/api/v1/libraries');
    expect(calls[0]!.init!.method).toBe('GET');
    expect(result).toEqual([sampleLibrary]);
  });

  it('get(id) GETs /api/v1/libraries/{id} and unwraps { library }', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { library: sampleLibrary } },
    ]);

    const result = await api.get('lib-1');

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1');
    expect(result).toEqual(sampleLibrary);
  });

  it('create() POSTs the full body and parses 201 { library_id, message }', async () => {
    const { api, calls } = makeApi([
      { status: 201, body: { library_id: 'lib-9', message: 'created' } },
    ]);

    const result = await api.create({
      name: 'Shows',
      type: 'series',
      paths: ['/media/tv'],
    });

    expect(calls[0]!.url).toBe('/api/v1/libraries');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(calls[0]!.init!.body).toBe(
      JSON.stringify({ name: 'Shows', type: 'series', paths: ['/media/tv'] }),
    );
    expect(result).toEqual({ library_id: 'lib-9', message: 'created' });
  });

  it('update() PUTs only editable fields and NEVER sends `type`', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'updated' } }]);

    const result = await api.update('lib-1', {
      name: 'Renamed',
      paths: ['/m'],
    });

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1');
    expect(calls[0]!.init!.method).toBe('PUT');
    const body = JSON.parse(calls[0]!.init!.body as string) as Record<string, unknown>;
    expect(body).not.toHaveProperty('type');
    expect(body).toEqual({ name: 'Renamed', paths: ['/m'] });
    expect(result).toEqual({ message: 'updated' });
  });

  it('remove() DELETEs /api/v1/libraries/{id}', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { message: 'deleted' } }]);

    const result = await api.remove('lib-1');

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1');
    expect(calls[0]!.init!.method).toBe('DELETE');
    expect(result).toEqual({ message: 'deleted' });
  });

  it('scan() POSTs /scan and parses 202 { job_id, status, message }', async () => {
    const { api, calls } = makeApi([
      { status: 202, body: { job_id: 'job-1', status: 'queued', message: 'ok' } },
    ]);

    const result = await api.scan('lib-1');

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1/scan');
    expect(calls[0]!.init!.method).toBe('POST');
    expect(result).toEqual({ job_id: 'job-1', status: 'queued', message: 'ok' });
  });

  it('rescan() POSTs /rescan with the same 202 shape', async () => {
    const { api, calls } = makeApi([
      { status: 202, body: { job_id: 'job-2', status: 'queued', message: 'ok' } },
    ]);

    const result = await api.rescan('lib-1');

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1/rescan');
    expect(result.status).toBe('queued');
  });

  it('scanStatus() unwraps { scan_status } (job)', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { scan_status: sampleJob } },
    ]);

    const result = await api.scanStatus('lib-1');

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1/scan-status');
    expect(result).toEqual(sampleJob);
  });

  it('scanStatus() returns null when there is no job', async () => {
    const { api } = makeApi([{ status: 200, body: { scan_status: null } }]);
    await expect(api.scanStatus('lib-1')).resolves.toBeNull();
  });

  it('scanHistory() unwraps { history } and omits limit when undefined', async () => {
    const { api, calls } = makeApi([
      { status: 200, body: { history: [sampleJob] } },
    ]);

    const result = await api.scanHistory('lib-1');

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1/scan-history');
    expect(result).toEqual([sampleJob]);
  });

  it('scanHistory() appends ?limit=N when provided', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { history: [] } }]);

    await api.scanHistory('lib-1', 5);

    expect(calls[0]!.url).toBe('/api/v1/libraries/lib-1/scan-history?limit=5');
  });

  it('throws ApiError on a 4xx (e.g. create 400)', async () => {
    const { api } = makeApi([
      { status: 400, body: { error: 'Invalid type', valid_types: [] } },
    ]);

    await expect(
      api.create({ name: 'X', type: 'movie', paths: ['/p'] }),
    ).rejects.toBeInstanceOf(ApiError);
  });

  it('encodes path-unsafe ids in the URL', async () => {
    const { api, calls } = makeApi([{ status: 200, body: { library: sampleLibrary } }]);
    await api.get('a/b c');
    expect(calls[0]!.url).toBe('/api/v1/libraries/a%2Fb%20c');
  });
});
