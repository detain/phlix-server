/**
 * WebhooksPage — the admin "Webhooks" feature page.
 *
 * Lists webhook subscriptions (name, URL, event count, actions), allows
 * creating/editing them through a modal + Form, deleting through a confirm
 * modal, and testing delivery through a test-result modal.
 *
 * Security:
 *  - Every server/API string is rendered as a React text child — no
 *    `dangerouslySetInnerHTML`.
 *  - Admin gate is handled server-side + `useAdminGuard` client-side
 *    (in the App shell).
 *  - URL validation happens server-side via `filter_var(VALIDATE_URL)`.
 *
 * Async/resident rules:
 *  - No polling needed. useEffect cleans up on unmount.
 *
 * @since 1.4a
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  WebhooksApi,
  WEBHOOK_EVENT_CATEGORIES,
  type Webhook,
} from '../api/webhooks';
import { DataTable, type Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { Form, Field } from '../components/Form';
import { useToast } from '../components/Toast';

export interface WebhooksPageProps {
  client: ApiClient;
}

/** Validate a URL string using the same logic the server uses. */
function isValidUrl(url: string): boolean {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:';
  } catch {
    return false;
  }
}

export function WebhooksPage({ client }: WebhooksPageProps): JSX.Element {
  const apiRef = useRef(new WebhooksApi(client));
  // Destructure the stable `push` callback — the whole `useToast()` context
  // value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // List state.
  const [webhooks, setWebhooks] = useState<Webhook[]>([]);
  const [loading, setLoading] = useState(true);

  // Add/edit form state.
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Webhook | null>(null);
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [secret, setSecret] = useState('');
  const [selectedEvents, setSelectedEvents] = useState<Set<string>>(new Set());
  const [showSecret, setShowSecret] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  // Delete confirm state.
  const [deleting, setDeleting] = useState<Webhook | null>(null);

  // Test result modal state.
  const [testTarget, setTestTarget] = useState<Webhook | null>(null);
  const [testResult, setTestResult] = useState<{ success: boolean; message: string } | null>(null);
  const [testing, setTesting] = useState(false);

  const loadWebhooks = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const rows = await apiRef.current.list();
      setWebhooks(rows);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to load webhooks.';
      pushToast(message, 'error');
    } finally {
      setLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadWebhooks();
  }, [loadWebhooks]);

  const openAdd = (): void => {
    setEditing(null);
    setName('');
    setUrl('');
    setSecret('');
    setSelectedEvents(new Set());
    setShowSecret(false);
    setFormError('');
    setFormOpen(true);
  };

  const openEdit = (wh: Webhook): void => {
    setEditing(wh);
    setName(wh.name);
    setUrl(wh.url);
    setSecret(''); // write-only — never pre-filled
    setSelectedEvents(new Set(wh.events));
    setShowSecret(false);
    setFormError('');
    setFormOpen(true);
  };

  const closeForm = (): void => {
    setFormOpen(false);
    setEditing(null);
  };

  const toggleEvent = (eventId: string): void => {
    setSelectedEvents((prev) => {
      const next = new Set(prev);
      if (next.has(eventId)) {
        next.delete(eventId);
      } else {
        next.add(eventId);
      }
      return next;
    });
  };

  const submitForm = async (): Promise<void> => {
    setFormError('');

    // Client-side validation before submitting
    if (!name.trim()) {
      setFormError('Name is required.');
      return;
    }
    if (!url.trim()) {
      setFormError('URL is required.');
      return;
    }
    if (!isValidUrl(url)) {
      setFormError('URL must be a valid http:// or https:// URL.');
      return;
    }
    if (!editing && !secret.trim()) {
      setFormError('Secret is required when creating a webhook.');
      return;
    }
    if (selectedEvents.size === 0) {
      setFormError('Select at least one event.');
      return;
    }

    setSubmitting(true);
    try {
      if (editing) {
        const updateInput: Parameters<typeof apiRef.current.update>[1] = {
          name: name.trim(),
          url: url.trim(),
          events: Array.from(selectedEvents),
        };
        if (secret.trim()) {
          updateInput.secret = secret;
        }
        await apiRef.current.update(editing.id, updateInput);
      } else {
        await apiRef.current.create({
          name: name.trim(),
          url: url.trim(),
          secret: secret,
          events: Array.from(selectedEvents),
        });
      }

      pushToast(editing ? 'Webhook updated.' : 'Webhook created.', 'success');
      closeForm();
      await loadWebhooks();
    } catch (err) {
      if (err instanceof ApiError) {
        setFormError(err.message);
      } else {
        pushToast('Failed to save webhook.', 'error');
      }
    } finally {
      setSubmitting(false);
    }
  };

  const confirmDelete = async (): Promise<void> => {
    if (!deleting) return;
    try {
      await apiRef.current.remove(deleting.id);
      pushToast('Webhook deleted.', 'success');
      setDeleting(null);
      await loadWebhooks();
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to delete webhook.';
      pushToast(message, 'error');
      setDeleting(null);
    }
  };

  const triggerTest = async (wh: Webhook): Promise<void> => {
    setTestTarget(wh);
    setTestResult(null);
    setTesting(true);
    try {
      const result = await apiRef.current.test(wh.id);
      // Construct human-readable message from the dispatch result.
      const message = result.failure_count === 0
        ? `Delivered successfully (${result.success_count}/${result.success_count} webhooks)`
        : `Delivery failed — ${result.failure_count} of ${result.success_count + result.failure_count} webhook(s) failed`;
      setTestResult({ success: result.success, message });
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to test webhook.';
      setTestResult({ success: false, message });
    } finally {
      setTesting(false);
    }
  };

  const closeTestModal = (): void => {
    setTestTarget(null);
    setTestResult(null);
  };

  const columns: Array<Column<Webhook>> = [
    { id: 'name', header: 'Name', key: 'name' },
    { id: 'url', header: 'URL', key: 'url' },
    {
      id: 'events',
      header: 'Events',
      render: (wh) => (
        <span className="webhook-events-badge">{wh.events.length}</span>
      ),
    },
    {
      id: 'actions',
      header: 'Actions',
      render: (wh) => (
        <div className="webhook-actions">
          <button
            type="button"
            onClick={() => openEdit(wh)}
            aria-label={`Edit ${wh.name}`}
          >
            Edit
          </button>
          <button
            type="button"
            onClick={() => triggerTest(wh)}
            aria-label={`Test ${wh.name}`}
          >
            Test
          </button>
          <button
            type="button"
            onClick={() => setDeleting(wh)}
            aria-label={`Delete ${wh.name}`}
          >
            Delete
          </button>
        </div>
      ),
    },
  ];

  return (
    <section className="page page--webhooks" aria-labelledby="webhooks-heading">
      <header className="page__header">
        <h1 id="webhooks-heading">Webhooks</h1>
        <button type="button" onClick={openAdd}>
          Add webhook
        </button>
      </header>

      {loading ? (
        <p role="status" aria-live="polite">Loading…</p>
      ) : (
        <DataTable
          columns={columns}
          rows={webhooks}
          rowKey={(wh) => wh.id}
          emptyMessage="No webhooks configured. Add one to get started."
          caption="Webhooks"
        />
      )}

      {/* Add / Edit modal */}
      <Modal
        open={formOpen}
        title={editing ? 'Edit webhook' : 'Add webhook'}
        onClose={closeForm}
      >
        <Form onSubmit={submitForm} busy={submitting}>
          <Field
            id="webhook-name"
            label="Name"
            value={name}
            onChange={setName}
            required
          />
          <Field
            id="webhook-url"
            label="URL"
            value={url}
            onChange={setUrl}
            type="url"
            placeholder="https://example.com/webhook"
            required
          />
          <div className="form__field">
            <label className="form__label" htmlFor="webhook-secret">
              Secret
              {!editing && <span aria-hidden="true"> *</span>}
            </label>
            {editing ? (
              <p className="form__hint">
                Leave blank to keep the current secret.
              </p>
            ) : null}
            <div className="webhook-secret-row">
              <input
                id="webhook-secret"
                className="form__input"
                type={showSecret ? 'text' : 'password'}
                value={secret}
                onChange={(e) => setSecret(e.target.value)}
                placeholder={editing ? '(unchanged)' : 'Shared secret for HMAC signing'}
                autoComplete="new-password"
              />
              <button
                type="button"
                className="password-toggle"
                onClick={() => setShowSecret((v) => !v)}
              >
                {showSecret ? 'Hide' : 'Show'}
              </button>
            </div>
          </div>

          {/* Event category checkboxes */}
          <fieldset className="webhook-eventsfieldset">
            <legend className="form__label">
              Events<span aria-hidden="true"> *</span>
            </legend>
            {WEBHOOK_EVENT_CATEGORIES.map((category) => (
              <div key={category.label} className="webhook-events-category">
                <span className="webhook-events-category__label">
                  {category.label}
                </span>
                {category.events.map((event) => (
                  <label key={event.id} className="form__label form__label--checkbox">
                    <input
                      type="checkbox"
                      className="form__checkbox"
                      checked={selectedEvents.has(event.id)}
                      onChange={() => toggleEvent(event.id)}
                    />
                    {event.label}
                    <span className="webhook-event-id">{event.id}</span>
                  </label>
                ))}
              </div>
            ))}
          </fieldset>

          {formError ? (
            <p className="form__error" role="alert">
              {formError}
            </p>
          ) : null}

          <div className="form__actions">
            <button type="submit">
              {editing ? 'Save' : 'Create'}
            </button>
            <button type="button" onClick={closeForm}>
              Cancel
            </button>
          </div>
        </Form>
      </Modal>

      {/* Delete confirm modal */}
      <Modal
        open={deleting !== null}
        title="Delete webhook"
        onClose={() => setDeleting(null)}
      >
        <p>
          Delete webhook <strong>{deleting?.name}</strong>? This cannot be undone.
        </p>
        <div className="form__actions">
          <button
            type="button"
            className="btn--danger"
            onClick={() => void confirmDelete()}
          >
            Delete
          </button>
          <button type="button" onClick={() => setDeleting(null)}>
            Cancel
          </button>
        </div>
      </Modal>

      {/* Test result modal */}
      <Modal
        open={testTarget !== null}
        title={testTarget ? `Test — ${testTarget.name}` : 'Test webhook'}
        onClose={closeTestModal}
      >
        {testing ? (
          <p role="status" aria-live="polite">Sending test payload…</p>
        ) : testResult ? (
          <div
            className={`webhook-test-result webhook-test-result--${testResult.success ? 'ok' : 'fail'}`}
          >
            <div className="webhook-test-result__icon" aria-hidden="true">
              {testResult.success ? '✓' : '✗'}
            </div>
            <div className="webhook-test-result__body">
              <p className="webhook-test-result__status">
                {testResult.success ? 'Delivery succeeded' : 'Delivery failed'}
              </p>
              <p className="webhook-test-result__message">{testResult.message}</p>
            </div>
          </div>
        ) : null}
        {!testing && (
          <div className="form__actions">
            <button type="button" onClick={closeTestModal}>
              Close
            </button>
          </div>
        )}
      </Modal>
    </section>
  );
}
