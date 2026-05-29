import { describe, expect, it } from 'vitest';
import { LiveTvApi } from './liveTv';
import { ApiClient } from './client';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function makeApiClient(
  responses: Array<{ status: number; body: unknown }>,
): {
  api: LiveTvApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new LiveTvApi(client), calls };
}

describe('LiveTvApi', () => {
  // ─── Tuner tests ────────────────────────────────────────────────────────────

  describe('listTuners()', () => {
    it('issues GET /api/v1/admin/livetv/tuners', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            tuners: [
              {
                tuner_id: 'tuner-1',
                type: 'HDHomeRun',
                name: 'Front Room',
                host: '192.168.1.100',
                port: 5004,
                enabled: true,
                status: 'active',
              },
            ],
          },
        },
      ]);

      const result = await api.listTuners();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/tuners');
      expect(result.tuners.length).toBe(1);
      expect(result.tuners[0]!.tuner_id).toBe('tuner-1');
    });

    it('returns empty tuners array', async () => {
      const { api } = makeApiClient([
        { status: 200, body: { success: true, tuners: [] } },
      ]);

      const result = await api.listTuners();

      expect(result.tuners).toHaveLength(0);
    });
  });

  describe('scanTuners()', () => {
    it('issues POST /api/v1/admin/livetv/tuners/scan', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, tuners: [] } },
      ]);

      await api.scanTuners();

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/tuners/scan');
    });

    it('returns discovered tuners', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            tuners: [
              { tuner_id: 'hdhr-1', type: 'HDHomeRun', name: 'Test', host: '192.168.1.99', port: 5004, enabled: true },
            ],
          },
        },
      ]);

      const result = await api.scanTuners();

      expect(result.tuners).toHaveLength(1);
      expect(result.tuners[0]!.type).toBe('HDHomeRun');
    });
  });

  describe('updateTuner()', () => {
    it('issues PUT /api/v1/admin/livetv/tuners/{id} with name and enabled', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, tuner: { tuner_id: 'tuner-1', name: 'New Name', enabled: false } } },
      ]);

      await api.updateTuner('tuner-1', { name: 'New Name', enabled: false });

      expect(calls[0]!.init?.method).toBe('PUT');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/tuners/tuner-1');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({ name: 'New Name', enabled: false });
    });
  });

  describe('deleteTuner()', () => {
    it('issues DELETE /api/v1/admin/livetv/tuners/{id}', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.deleteTuner('tuner-1');

      expect(calls[0]!.init?.method).toBe('DELETE');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/tuners/tuner-1');
      expect(result.success).toBe(true);
    });
  });

  // ─── Guide tests ────────────────────────────────────────────────────────────

  describe('listGuide()', () => {
    it('issues GET /api/v1/admin/livetv/guide', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, programs: [] } },
      ]);

      await api.listGuide();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/guide');
    });

    it('passes channel_id and time range params', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, programs: [] } },
      ]);

      await api.listGuide({ channel_id: 'ch-1', from: 1700000000, to: 1700100000 });

      expect(calls[0]!.url).toContain('channel_id=ch-1');
      expect(calls[0]!.url).toContain('from=1700000000');
      expect(calls[0]!.url).toContain('to=1700100000');
    });

    it('returns programmes', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            programs: [
              { id: 'prog-1', title: 'News', start_time: 1700000000, end_time: 1700003600 },
            ],
          },
        },
      ]);

      const result = await api.listGuide();

      expect(result.programs).toHaveLength(1);
      expect(result.programs[0]!.title).toBe('News');
    });
  });

  describe('refreshGuide()', () => {
    it('issues POST /api/v1/admin/livetv/guide/refresh', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, programs: 42 } },
      ]);

      const result = await api.refreshGuide(14);

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/guide/refresh');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({ days_ahead: 14 });
      expect(result.programs).toBe(42);
    });
  });

  // ─── Recording tests ────────────────────────────────────────────────────────

  describe('listRecordings()', () => {
    it('issues GET /api/v1/admin/livetv/recordings', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, recordings: [] } },
      ]);

      await api.listRecordings();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/recordings');
    });

    it('passes status filter', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, recordings: [] } },
      ]);

      await api.listRecordings({ status: 'completed' });

      expect(calls[0]!.url).toContain('status=completed');
    });

    it('returns recordings', async () => {
      const { api } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            recordings: [
              { id: 'rec-1', channel_id: 'ch-1', program_title: 'Movie', start_time: 1700000000, end_time: 1700007200 },
            ],
          },
        },
      ]);

      const result = await api.listRecordings();

      expect(result.recordings).toHaveLength(1);
      expect(result.recordings[0]!.program_title).toBe('Movie');
    });
  });

  describe('createRecording()', () => {
    it('issues POST /api/v1/admin/livetv/recordings with all fields', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            recording: { id: 'rec-new', channel_id: 'ch-1', program_title: 'Test', start_time: 1700000000, end_time: 1700003600 },
          },
        },
      ]);

      const result = await api.createRecording({
        channel_id: 'ch-1',
        start_time: 1700000000,
        end_time: 1700003600,
        title: 'Test Recording',
        program_id: 'prog-1',
        priority: 3,
      });

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/recordings');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({
        channel_id: 'ch-1',
        start_time: 1700000000,
        end_time: 1700003600,
        title: 'Test Recording',
        program_id: 'prog-1',
        priority: 3,
      });
      expect(result.recording.id).toBe('rec-new');
    });
  });

  describe('deleteRecording()', () => {
    it('issues DELETE /api/v1/admin/livetv/recordings/{id}', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.deleteRecording('rec-1');

      expect(calls[0]!.init?.method).toBe('DELETE');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/recordings/rec-1');
      expect(result.success).toBe(true);
    });
  });

  describe('listUpcoming()', () => {
    it('issues GET /api/v1/admin/livetv/recordings/upcoming', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, recordings: [] } },
      ]);

      await api.listUpcoming();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/recordings/upcoming');
    });
  });

  describe('listBySeries()', () => {
    it('issues GET /api/v1/admin/livetv/recordings/series/{seriesId}', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, recordings: [] } },
      ]);

      await api.listBySeries('series-abc');

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/recordings/series/series-abc');
    });
  });

  // ─── Series Rule tests ─────────────────────────────────────────────────────

  describe('listSeriesRules()', () => {
    it('issues GET /api/v1/admin/livetv/series-rules', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            rules: [
              { id: 'rule-1', title_pattern: 'News%', channel_id: 'ch-1', enabled: true },
            ],
          },
        },
      ]);

      const result = await api.listSeriesRules();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/series-rules');
      expect(result.rules).toHaveLength(1);
    });
  });

  describe('createSeriesRule()', () => {
    it('issues POST /api/v1/admin/livetv/series-rules with all fields', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            rule: { id: 'rule-new', title_pattern: 'News%', channel_id: 'ch-1', enabled: true },
          },
        },
      ]);

      const result = await api.createSeriesRule({
        series_id: 'series-1',
        channel_id: 'ch-1',
        title: 'News Recordings',
        priority: 3,
      });

      expect(calls[0]!.init?.method).toBe('POST');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/series-rules');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body.series_id).toBe('series-1');
      expect(body.channel_id).toBe('ch-1');
      expect(body.title).toBe('News Recordings');
      expect(result.rule.id).toBe('rule-new');
    });
  });

  describe('updateSeriesRule()', () => {
    it('issues PUT /api/v1/admin/livetv/series-rules/{id} with partial data', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true, rule: { id: 'rule-1', priority: 5 } } },
      ]);

      await api.updateSeriesRule('rule-1', { priority: 5 });

      expect(calls[0]!.init?.method).toBe('PUT');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/series-rules/rule-1');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({ priority: 5 });
    });
  });

  describe('deleteSeriesRule()', () => {
    it('issues DELETE /api/v1/admin/livetv/series-rules/{id}', async () => {
      const { api, calls } = makeApiClient([
        { status: 200, body: { success: true } },
      ]);

      const result = await api.deleteSeriesRule('rule-1');

      expect(calls[0]!.init?.method).toBe('DELETE');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/series-rules/rule-1');
      expect(result.success).toBe(true);
    });
  });

  describe('listChannels()', () => {
    it('issues GET /api/v1/admin/livetv/channels', async () => {
      const { api, calls } = makeApiClient([
        {
          status: 200,
          body: {
            success: true,
            channels: [
              { id: 'ch-1', name: 'BBC One', number: '1', enabled: true },
            ],
          },
        },
      ]);

      const result = await api.listChannels();

      expect(calls[0]!.init?.method).toBe('GET');
      expect(calls[0]!.url).toContain('/api/v1/admin/livetv/channels');
      expect(result.channels).toHaveLength(1);
    });
  });
});
