import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { WebhooksPage } from './WebhooksPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';
import { WEBHOOK_EVENT_CATEGORIES } from '../api/webhooks';

/** Build a test page driven by ordered real-shaped responses. */
function renderPage(
  responses: Array<{ status: number; body: unknown }>,
): { calls: ReturnType<typeof makeFetch>['calls']; unmount: () => void } {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  const result = render(
    <ToastProvider timeoutMs={0}>
      <WebhooksPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

const wh1 = {
  id: 'wh-1',
  name: 'My Webhook',
  url: 'https://example.com/webhook',
  events: ['playback.started', 'library.updated'],
  created_at: '2026-05-27T00:00:00Z',
};

const wh2 = {
  id: 'wh-2',
  name: 'Alert Hook',
  url: 'https://alerts.example.com/hook',
  events: ['alert', 'recording.started'],
  created_at: '2026-05-27T00:00:00Z',
};

afterEach(() => {
  vi.useRealTimers();
});

describe('WebhooksPage', () => {
  describe('rendering', () => {
    it('shows loading then the heading', async () => {
      renderPage([{ status: 200, body: { webhooks: [] } }]);
      expect(screen.getByRole('heading', { name: 'Webhooks' })).toBeInTheDocument();
      expect(screen.getByRole('status')).toHaveTextContent('Loading…');
    });

    it('renders empty state message when no webhooks', async () => {
      renderPage([{ status: 200, body: { webhooks: [] } }]);
      await waitFor(() => {
        expect(
          screen.getByText('No webhooks configured. Add one to get started.'),
        ).toBeInTheDocument();
      });
    });

    it('renders the table with 2 webhooks', async () => {
      renderPage([
        { status: 200, body: { webhooks: [wh1, wh2] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });
      expect(screen.getByText('Alert Hook')).toBeInTheDocument();
      expect(screen.getByText('https://example.com/webhook')).toBeInTheDocument();
      expect(screen.getByText('https://alerts.example.com/hook')).toBeInTheDocument();
    });

    it('renders event count badges', async () => {
      renderPage([{ status: 200, body: { webhooks: [wh1, wh2] } }]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });
      // wh1 has 2 events, wh2 has 2 events
      const badges = screen.getAllByText((content, el) => {
        return el !== null && el.classList.contains('webhook-events-badge') &&
          (content === '2' || content === '2');
      });
      expect(badges).toHaveLength(2);
    });

    it('renders action buttons for each row', async () => {
      renderPage([{ status: 200, body: { webhooks: [wh1] } }]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });
      // 3 buttons per row: Edit, Test, Delete
      const allButtons = screen.getAllByRole('button');
      expect(allButtons.filter((b) => b.textContent === 'Edit')).toHaveLength(1);
      expect(allButtons.filter((b) => b.textContent === 'Test')).toHaveLength(1);
      expect(allButtons.filter((b) => b.textContent === 'Delete')).toHaveLength(1);
    });
  });

  describe('add webhook', () => {
    it('opens modal when Add webhook is clicked', async () => {
      const user = userEvent.setup();
      renderPage([{ status: 200, body: { webhooks: [] } }]);
      await waitFor(() => {
        expect(screen.getByRole('heading', { name: 'Webhooks' })).toBeInTheDocument();
      });
      await user.click(screen.getByRole('button', { name: 'Add webhook' }));
      expect(screen.getByRole('dialog', { name: 'Add webhook' })).toBeInTheDocument();
    });

    it('shows all event categories in the form', async () => {
      const user = userEvent.setup();
      renderPage([{ status: 200, body: { webhooks: [] } }]);
      await waitFor(() => {});
      await user.click(screen.getByRole('button', { name: 'Add webhook' }));

      // All 5 categories should be present
      for (const cat of WEBHOOK_EVENT_CATEGORIES) {
        expect(screen.getByText(cat.label)).toBeInTheDocument();
      }
    });

    it('form submit success → modal closes + toast', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { webhooks: [] } },
        {
          status: 201,
          body: {
            webhook: { id: 'wh-new', name: 'New', url: 'https://x.com/wh', events: ['playback.started'] },
          },
        },
        { status: 200, body: { webhooks: [{ id: 'wh-new', name: 'New', url: 'https://x.com/wh', events: ['playback.started'] }] } },
      ]);
      await waitFor(() => {
        expect(screen.getByRole('heading', { name: 'Webhooks' })).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Add webhook' }));

      // Fill in name
      await user.type(screen.getByLabelText(/^Name/), 'New');
      // Fill URL
      await user.type(screen.getByLabelText(/^URL/), 'https://x.com/wh');
      // Secret — fill by placeholder (no testid on the input)
      await user.type(screen.getByPlaceholderText('Shared secret for HMAC signing'), 's3cr3t');
      // Select first event checkbox
      const checkboxes = screen.getAllByRole('checkbox');
      await user.click(checkboxes[0]!);

      await user.click(screen.getByRole('button', { name: 'Create' }));

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Webhook created.');
      });
    });

    it('form validation → shows error for missing fields', async () => {
      const user = userEvent.setup();
      renderPage([{ status: 200, body: { webhooks: [] } }]);
      await waitFor(() => {});
      await user.click(screen.getByRole('button', { name: 'Add webhook' }));

      // Try to submit without filling anything
      await user.click(screen.getByRole('button', { name: 'Create' }));

      expect(await screen.findByRole('alert')).toBeInTheDocument();
    });
  });

  describe('edit webhook', () => {
    it('opens pre-filled modal when Edit is clicked', async () => {
      const user = userEvent.setup();
      renderPage([{ status: 200, body: { webhooks: [wh1] } }]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Edit My Webhook' }));

      const dialog = screen.getByRole('dialog', { name: 'Edit webhook' });
      expect(dialog).toBeInTheDocument();

      // Name and URL should be pre-filled
      expect(screen.getByDisplayValue('My Webhook')).toBeInTheDocument();
      expect(screen.getByDisplayValue('https://example.com/webhook')).toBeInTheDocument();

      // Secret field should be empty (write-only) and show "(unchanged)" placeholder
      const secretInput = screen.getByLabelText(/^Secret/) as HTMLInputElement;
      expect(secretInput.value).toBe('');
      expect(secretInput.placeholder).toBe('(unchanged)');

      // The "leave blank" hint should be visible
      expect(screen.getByText('Leave blank to keep the current secret.')).toBeInTheDocument();
    });

    it('edit submit success → toast + refresh', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { webhooks: [wh1] } },
        {
          status: 200,
          body: {
            webhook: { id: 'wh-1', name: 'Updated', url: 'https://x.com/wh2', events: ['alert'] },
          },
        },
        { status: 200, body: { webhooks: [{ id: 'wh-1', name: 'Updated', url: 'https://x.com/wh2', events: ['alert'] }] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Edit My Webhook' }));

      const nameInput = screen.getByLabelText(/^Name/) as HTMLInputElement;
      await user.clear(nameInput);
      await user.type(nameInput, 'Updated');

      await user.click(screen.getByRole('button', { name: 'Save' }));

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Webhook updated.');
      });
    });
  });

  describe('delete webhook', () => {
    it('opens confirm modal when Delete is clicked', async () => {
      const user = userEvent.setup();
      renderPage([{ status: 200, body: { webhooks: [wh1] } }]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Delete My Webhook' }));

      expect(screen.getByRole('dialog', { name: 'Delete webhook' })).toBeInTheDocument();
      const dialog = screen.getByRole('dialog', { name: 'Delete webhook' });
      expect(within(dialog).getByText(/My Webhook/)).toBeInTheDocument();
    });

    it('confirm delete → success toast', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { webhooks: [wh1] } },
        { status: 200, body: { message: 'Webhook deleted' } },
        { status: 200, body: { webhooks: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Delete My Webhook' }));
      // Confirm delete in the modal (modal's Delete button has no aria-label)
      await user.click(screen.getByRole('button', { name: 'Delete' }));

      await waitFor(() => {
        expect(screen.getByRole('status')).toHaveTextContent('Webhook deleted.');
      });
    });
  });

  describe('test webhook', () => {
    it('shows test result modal with success state', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { webhooks: [wh1] } },
        { status: 200, body: { success: true, success_count: 1, failure_count: 0, failures: [] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Test My Webhook' }));

      await waitFor(() => {
        expect(screen.getByText('Delivered successfully (1/1 webhooks)')).toBeInTheDocument();
      });
      // Success indicator should show a checkmark
      expect(screen.getByText('✓')).toBeInTheDocument();
    });

    it('shows test result modal with failure state', async () => {
      const user = userEvent.setup();
      renderPage([
        { status: 200, body: { webhooks: [wh1] } },
        { status: 200, body: { success: false, success_count: 0, failure_count: 1, failures: ['Connection refused'] } },
      ]);
      await waitFor(() => {
        expect(screen.getByText('My Webhook')).toBeInTheDocument();
      });

      await user.click(screen.getByRole('button', { name: 'Test My Webhook' }));

      await waitFor(() => {
        expect(screen.getByText('Delivery failed — 1 of 1 webhook(s) failed')).toBeInTheDocument();
      });
      // Failure indicator should show an X
      expect(screen.getByText('✗')).toBeInTheDocument();
    });
  });
});
