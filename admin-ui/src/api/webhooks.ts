/**
 * WebhooksApi — typed wrapper over the admin webhook CRUD + test endpoints
 * (`/api/v1/admin/webhooks`).
 *
 * @since 1.4a
 */
import type { ApiClient } from './client';

/**
 * A webhook subscription row as returned by `WebhookAdminController`.
 *
 * @since 1.4a
 */
export interface Webhook {
  id: string;
  name: string;
  url: string;
  /** Secret is write-only — never returned by GET. */
  secret?: string;
  events: string[];
  created_at?: string;
  [k: string]: unknown;
}

/**
 * Body accepted by {@link WebhooksApi.create}.
 *
 * @since 1.4a
 */
export interface CreateWebhookInput {
  name: string;
  url: string;
  secret: string;
  events: string[];
}

/**
 * Body accepted by {@link WebhooksApi.update}.
 * Secret is optional — omit to keep the existing secret server-side.
 *
 * @since 1.4a
 */
export interface UpdateWebhookInput {
  name: string;
  url: string;
  /** Omit to keep the current secret server-side. */
  secret?: string;
  events: string[];
}

/** Result of {@link WebhooksApi.test}. @since 1.4a */
export interface TestResult {
  success: boolean;
  success_count: number;
  failure_count: number;
  failures: string[];
}

/**
 * Event catalog — grouped by category for the checkbox UI.
 * `webhook.test` is NOT user-subscribable (internal only, from the test button).
 *
 * @since 1.4a
 */
export const WEBHOOK_EVENT_CATEGORIES: ReadonlyArray<{
  label: string;
  events: ReadonlyArray<{ id: string; label: string }>;
}> = Object.freeze([
  {
    label: 'Playback',
    events: [
      { id: 'playback.started', label: 'Playback started' },
      { id: 'playback.ended', label: 'Playback ended' },
    ],
  },
  {
    label: 'Library',
    events: [
      { id: 'library.updated', label: 'Library updated' },
    ],
  },
  {
    label: 'Downloads',
    events: [
      { id: 'download.complete', label: 'Download complete' },
    ],
  },
  {
    label: 'Recordings',
    events: [
      { id: 'recording.started', label: 'Recording started' },
      { id: 'recording.stopped', label: 'Recording stopped' },
    ],
  },
  {
    label: 'System',
    events: [
      { id: 'alert', label: 'Alert' },
    ],
  },
]);

/**
 * All user-subscribable event IDs (excludes `webhook.test`).
 */
export const SUBSCRIBABLE_EVENTS = Object.freeze(
  WEBHOOK_EVENT_CATEGORIES.flatMap((cat) => cat.events.map((e) => e.id)),
);

/**
 * Typed client for the admin webhook endpoints.
 *
 * @since 1.4a
 */
export class WebhooksApi {
  constructor(private readonly client: ApiClient) {}

  /**
   * `GET /api/v1/admin/webhooks` → unwraps `{ webhooks }`.
   */
  async list(): Promise<Webhook[]> {
    const { webhooks } = await this.client.get<{ webhooks: Webhook[] }>(
      '/api/v1/admin/webhooks',
    );
    return webhooks;
  }

  /**
   * `POST /api/v1/admin/webhooks` → `201 { webhook }`.
   */
  create(input: CreateWebhookInput): Promise<Webhook> {
    return this.client.post<{ webhook: Webhook }>(
      '/api/v1/admin/webhooks',
      input,
    ).then(({ webhook }) => webhook);
  }

  /**
   * `PUT /api/v1/admin/webhooks/{id}` → `{ webhook }`.
   * Secret is never returned by GET — if omitted on update, server keeps existing.
   */
  update(id: string, input: UpdateWebhookInput): Promise<Webhook> {
    return this.client.put<{ webhook: Webhook }>(
      `/api/v1/admin/webhooks/${encodeURIComponent(id)}`,
      input,
    ).then(({ webhook }) => webhook);
  }

  /**
   * `DELETE /api/v1/admin/webhooks/{id}` → `{ message }` or 204 No Content.
   */
  remove(id: string): Promise<{ message: string }> {
    return this.client.delete<{ message: string } | null>(
      `/api/v1/admin/webhooks/${encodeURIComponent(id)}`,
    ).then((res) => {
      if (res === null || res === undefined) {
        return { message: 'Webhook deleted' };
      }
      return res;
    });
  }

  /**
   * `POST /api/v1/admin/webhooks/{id}/test` → `{ success, message }`.
   */
  test(id: string): Promise<TestResult> {
    return this.client.post<TestResult>(
      `/api/v1/admin/webhooks/${encodeURIComponent(id)}/test`,
    );
  }
}
