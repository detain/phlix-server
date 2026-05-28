import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { AirPlayApi, type AirPlayDevice, type AirPlayPlaybackState } from './airplay';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: AirPlayApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new AirPlayApi(client), calls };
}

const airplayDevice: AirPlayDevice = {
  device_id: 'airplay-1',
  name: 'Kitchen Speaker',
  host: '192.168.1.101',
  port: 7000,
  model: 'Apple TV 4K',
  address: 'aa:bb:cc:dd:ee:01',
};

const airplayPlaybackState: AirPlayPlaybackState = {
  device_id: 'airplay-1',
  media_title: 'My Podcast',
  media_item_id: 'p1',
  transport_state: 'PLAYING',
  volume_level: 0.5,
  muted: false,
};

describe('AirPlayApi', () => {
  describe('listDevices()', () => {
    it('GETs /api/v1/airplay/devices and returns device list', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: [airplayDevice] } },
      ]);

      const result = await api.listDevices();

      expect(calls[0]!.url).toBe('/api/v1/airplay/devices');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual([airplayDevice]);
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
    it('GETs /api/v1/airplay/devices/:id/status and returns playback state', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: airplayPlaybackState } },
      ]);

      const result = await api.getStatus('airplay-1');

      expect(calls[0]!.url).toContain('/api/v1/airplay/devices/airplay-1/status');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual(airplayPlaybackState);
    });

    it('encodes deviceId in URL', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, data: airplayPlaybackState } },
      ]);

      await api.getStatus('device with spaces');

      expect(calls[0]!.url).toContain('device%20with%20spaces');
    });
  });

  describe('play()', () => {
    it('POSTs to /api/v1/airplay/devices/:id/play', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Playing' } },
      ]);

      const result = await api.play('airplay-1');

      expect(calls[0]!.url).toContain('/api/v1/airplay/devices/airplay-1/play');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('pause()', () => {
    it('POSTs to /api/v1/airplay/devices/:id/pause', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, message: 'Paused' } },
      ]);

      const result = await api.pause('airplay-1');

      expect(calls[0]!.url).toContain('/api/v1/airplay/devices/airplay-1/pause');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  describe('stop()', () => {
    it('POSTs to /api/v1/airplay/devices/:id/stop', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.stop('airplay-1');

      expect(calls[0]!.url).toContain('/api/v1/airplay/devices/airplay-1/stop');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result.success).toBe(true);
    });
  });

  it('throws ApiError on non-2xx response', async () => {
    const { api } = makeApi([
      { status: 500, body: { error: 'Server error' } },
    ]);

    await expect(api.listDevices()).rejects.toBeInstanceOf(ApiError);
  });
});
