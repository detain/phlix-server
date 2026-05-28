import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { CastApi, type CastDevice, type CastPlaybackState } from './cast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: CastApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new CastApi(client), calls };
}

const castDevice: CastDevice = {
  device_id: 'cast-1',
  name: 'Living Room TV',
  host: '192.168.1.100',
  port: 8009,
  model: 'Chromecast Ultra',
  address: 'aa:bb:cc:dd:ee:ff',
};

const castPlaybackState: CastPlaybackState = {
  device_id: 'cast-1',
  media_title: 'My Movie',
  media_item_id: 'm1',
  transport_state: 'PLAYING',
  volume_level: 0.75,
  muted: false,
  duration_seconds: 7200,
  position_seconds: 1800,
};

describe('CastApi', () => {
  describe('listDevices()', () => {
    it('GETs /api/v1/cast/devices and returns device list', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: [castDevice] } },
      ]);

      const result = await api.listDevices();

      expect(calls[0]!.url).toBe('/api/v1/cast/devices');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual([castDevice]);
    });

    it('returns empty array when no devices', async () => {
      const { api } = makeApi([
        { status: 200, body: { success: true, data: [] } },
      ]);

      const result = await api.listDevices();

      expect(result).toEqual([]);
    });
  });

  describe('getStatus()', () => {
    it('GETs /api/v1/cast/devices/:id/status and returns playback state', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: castPlaybackState } },
      ]);

      const result = await api.getStatus('cast-1');

      expect(calls[0]!.url).toContain('/api/v1/cast/devices/cast-1/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(castPlaybackState);
    });

    it('encodes deviceId in URL', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: castPlaybackState } },
      ]);

      await api.getStatus('device with spaces');

      expect(calls[0]!.url).toContain('device%20with%20spaces');
    });
  });

  describe('play()', () => {
    it('POSTs to /api/v1/cast/devices/:id/play', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Playing' } },
      ]);

      const result = await api.play('cast-1');

      expect(calls[0]!.url).toContain('/api/v1/cast/devices/cast-1/play');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('pause()', () => {
    it('POSTs to /api/v1/cast/devices/:id/pause', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Paused' } },
      ]);

      const result = await api.pause('cast-1');

      expect(calls[0]!.url).toContain('/api/v1/cast/devices/cast-1/pause');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('stop()', () => {
    it('POSTs to /api/v1/cast/devices/:id/stop', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.stop('cast-1');

      expect(calls[0]!.url).toContain('/api/v1/cast/devices/cast-1/stop');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('seek()', () => {
    it('POSTs to /api/v1/cast/devices/:id/seek with position_seconds', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.seek('cast-1', 3600);

      expect(calls[0]!.url).toContain('/api/v1/cast/devices/cast-1/seek');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(calls[0]!.init!.body).toBe(JSON.stringify({ position_seconds: 3600 }));
      expect(result.success).toBe(true);
    });

    it('handles seek to position 0', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      await api.seek('cast-1', 0);

      expect(calls[0]!.init!.body).toBe(JSON.stringify({ position_seconds: 0 }));
    });
  });

  it('throws ApiError on non-2xx response', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' } },
    ]);

    await expect(api.listDevices()).rejects.toBeInstanceOf(ApiError);
  });
});
