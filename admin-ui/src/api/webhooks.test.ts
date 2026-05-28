import { describe, expect, it } from 'vitest';
import { ApiClient, ApiError } from './client';
import { WebhooksApi, SUBSCRIBABLE_EVENTS, WEBHOOK_EVENT_CATEGORIES } from './webhooks';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a real ApiClient driven by an ordered list of real-shaped responses. */
function makeApi(responses: Array<{ status: number; body: unknown }>): {
  api: WebhooksApi;
  calls: ReturnType<typeof makeFetch>['calls'];
} {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  return { api: new WebhooksApi(client), calls };
}

const sampleWebhook = {
  id: 'wh-1',
  name: 'My Webhook',
  url: 'https://example.com/webhook',
  events: ['playback.started', 'library.updated'],
  created_at: '2026-05-27T00:00:00Z',
};

describe('WebhooksApi', () => {
  describe('event catalog', () => {
    it('exposes 7 subscribable events (excludes webhook.test)', () => {
      expect(SUBSCRIBABLE_EVENTS).toHaveLength(7);
      expect(SUBSCRIBABLE_EVENTS).not.toContain('webhook.test');
    });

    it('groups events into 5 categories', () => {
      expect(WEBHOOK_EVENT_CATEGORIES).toHaveLength(5);
      expect(WEBHOOK_EVENT_CATEGORIES.map((c) => c.label)).toEqual([
        'Playback',
        'Library',
        'Downloads',
        'Recordings',
        'System',
      ]);
    });
  });

  describe('list()', () => {
    it('GETs /api/v1/admin/webhooks and unwraps { webhooks }', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { webhooks: [sampleWebhook] } },
      ]);

      const result = await api.list();

      expect(calls[0]!.url).toBe('/api/v1/admin/webhooks');
      expect(calls[0]!.init!.method).toBe('GET');
      expect(result).toEqual([sampleWebhook]);
    });

    it('returns empty array when no webhooks', async () => {
      const { api } = makeApi([{ status: 200, body: { webhooks: [] } }]);
      await expect(api.list()).resolves.toEqual([]);
    });
  });

  describe('create()', () => {
    it('POSTs to /api/v1/admin/webhooks with correct body', async () => {
      const { api, calls } = makeApi([
        {
          status: 201,
          body: {
            webhook: { id: 'wh-new', name: 'New', url: 'https://x.com/wh', events: ['playback.started'] },
          },
        },
      ]);

      const result = await api.create({
        name: 'New',
        url: 'https://x.com/wh',
        secret: 's3cr3t',
        events: ['playback.started'],
      });

      expect(calls[0]!.url).toBe('/api/v1/admin/webhooks');
      expect(calls[0]!.init!.method).toBe('POST');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({
        name: 'New',
        url: 'https://x.com/wh',
        secret: 's3cr3t',
        events: ['playback.started'],
      });
      expect(result.name).toBe('New');
    });

    it('throws ApiError on 400', async () => {
      const { api } = makeApi([
        { status: 400, body: { error: 'URL is not a valid webhook URL' } },
      ]);

      await expect(
        api.create({ name: 'X', url: 'not-a-url', secret: 'x', events: [] }),
      ).rejects.toThrow('URL is not a valid webhook URL');
    });
  });

  describe('update()', () => {
    it('PUTs to /api/v1/admin/webhooks/{id} with correct body', async () => {
      const { api, calls } = makeApi([
        {
          status: 200,
          body: {
            webhook: { id: 'wh-1', name: 'Updated', url: 'https://x.com/wh2', events: ['alert'] },
          },
        },
      ]);

      const result = await api.update('wh-1', {
        name: 'Updated',
        url: 'https://x.com/wh2',
        events: ['alert'],
      });

      expect(calls[0]!.url).toBe('/api/v1/admin/webhooks/wh-1');
      expect(calls[0]!.init!.method).toBe('PUT');
      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body).toEqual({
        name: 'Updated',
        url: 'https://x.com/wh2',
        events: ['alert'],
      });
      // secret must NOT be in body when omitted
      expect(body).not.toHaveProperty('secret');
      expect(result.name).toBe('Updated');
    });

    it('includes secret in body when provided', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { webhook: { id: 'wh-1', name: 'X', url: 'https://x.com', events: [] } } },
      ]);

      await api.update('wh-1', {
        name: 'X',
        url: 'https://x.com',
        secret: 'new-secret',
        events: [],
      });

      const body = JSON.parse(calls[0]!.init!.body as string);
      expect(body.secret).toBe('new-secret');
    });

    it('throws ApiError on 404', async () => {
      const { api } = makeApi([
        { status: 404, body: { error: 'Webhook not found' } },
      ]);

      await expect(
        api.update('nonexistent', { name: 'X', url: 'https://x.com', events: [] }),
      ).rejects.toBeInstanceOf(ApiError);
    });
  });

  describe('remove()', () => {
    it('DELETEs /api/v1/admin/webhooks/{id}', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { message: 'Webhook deleted' } },
      ]);

      const result = await api.remove('wh-1');

      expect(calls[0]!.url).toBe('/api/v1/admin/webhooks/wh-1');
      expect(calls[0]!.init!.method).toBe('DELETE');
      expect(result).toEqual({ message: 'Webhook deleted' });
    });

    it('throws ApiError on 404', async () => {
      const { api } = makeApi([
        { status: 404, body: { error: 'Webhook not found' } },
      ]);

      await expect(api.remove('nonexistent')).rejects.toBeInstanceOf(ApiError);
    });
  });

  describe('test()', () => {
    it('POSTs /api/v1/admin/webhooks/{id}/test and returns dispatch result', async () => {
      const { api, calls } = makeApi([
        { status: 200, body: { success: true, success_count: 1, failure_count: 0, failures: [] } },
      ]);

      const result = await api.test('wh-1');

      expect(calls[0]!.url).toBe('/api/v1/admin/webhooks/wh-1/test');
      expect(calls[0]!.init!.method).toBe('POST');
      expect(result).toEqual({ success: true, success_count: 1, failure_count: 0, failures: [] });
    });

    it('returns failure result when delivery fails', async () => {
      const { api } = makeApi([
        { status: 200, body: { success: false, success_count: 0, failure_count: 1, failures: ['Connection refused'] } },
      ]);

      const result = await api.test('wh-1');

      expect(result.success).toBe(false);
      expect(result.failure_count).toBe(1);
      expect(result.failures[0]).toBe('Connection refused');
    });

    it('throws ApiError on 404', async () => {
      const { api } = makeApi([
        { status: 404, body: { error: 'Webhook not found' } },
      ]);

      await expect(api.test('nonexistent')).rejects.toBeInstanceOf(ApiError);
    });
  });
});
