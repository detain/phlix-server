import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { FilesystemApi } from './filesystem';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function makeFs(responses: Array<{ status: number; body: unknown }>): {
  fs: FilesystemApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { fs: new FilesystemApi(client), calls };
}

describe('FilesystemApi', () => {
  it('browse() with no path lists roots and unwraps .data', async () => {
    const { fs, calls } = makeFs([
      {
        status: 200,
        body: {
          success: true,
          data: {
            path: null,
            parent: null,
            entries: [{ name: 'media', path: '/media' }],
          },
        },
      },
    ]);

    const result = await fs.browse();

    // No `?path=` query when omitted.
    expect(calls[0]!.url).toBe('/api/v1/admin/fs/browse');
    expect(result).toEqual({
      path: null,
      parent: null,
      entries: [{ name: 'media', path: '/media' }],
    });
  });

  it('browse() treats an empty string the same as roots (no query)', async () => {
    const { fs, calls } = makeFs([
      { status: 200, body: { success: true, data: { path: null, parent: null, entries: [] } } },
    ]);

    await fs.browse('');

    expect(calls[0]!.url).toBe('/api/v1/admin/fs/browse');
  });

  it('browse(path) drills down and passes the path as a query param', async () => {
    const { fs, calls } = makeFs([
      {
        status: 200,
        body: {
          success: true,
          data: {
            path: '/media',
            parent: null,
            entries: [{ name: 'movies', path: '/media/movies' }],
          },
        },
      },
    ]);

    const result = await fs.browse('/media');

    expect(calls[0]!.url).toContain('/api/v1/admin/fs/browse?');
    expect(calls[0]!.url).toContain('path=%2Fmedia');
    expect(result.path).toBe('/media');
    expect(result.entries[0]!.path).toBe('/media/movies');
  });

  it('maps a non-2xx (e.g. 403 outside roots) to an ApiError', async () => {
    const { fs } = makeFs([
      { status: 403, body: { success: false, error: 'Outside roots' } },
    ]);

    await expect(fs.browse('/etc')).rejects.toBeInstanceOf(ApiError);
    await expect(fs.browse('/etc')).rejects.toMatchObject({
      message: 'Outside roots',
      status: 403,
    });
  });
});
