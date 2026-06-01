import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LogsPage, ALL_LOGS } from './LogsPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function renderPage(responses: Array<{ status: number; body: unknown; urlMatch?: string }>) {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  render(
    <ToastProvider timeoutMs={0}>
      <LogsPage client={client} />
    </ToastProvider>,
  );
  return { calls };
}

describe('LogsPage', () => {
  it('lists log files and tails the first one on mount', async () => {
    renderPage([
      {
        urlMatch: '/api/v1/admin/logs/tail',
        status: 200,
        body: { file: 'app.log', lines: ['line one', 'line two'], truncated: false },
      },
      {
        urlMatch: '/api/v1/admin/logs',
        status: 200,
        body: { files: [{ name: 'app.log', size: 10, modified_at: '2026-05-31T00:00:00Z' }] },
      },
    ]);

    // The file appears in the selector and its tail is shown.
    expect(await screen.findByRole('option', { name: 'app.log' })).toBeInTheDocument();
    await waitFor(() =>
      expect(screen.getByTestId('logs-output')).toHaveTextContent('line one'),
    );
    expect(screen.getByTestId('logs-output')).toHaveTextContent('line two');
  });

  it('tails all logs combined when "All logs" is selected', async () => {
    // NOTE: makeFetch matches urlMatch as a substring, and '/logs/tail-all'
    // contains '/logs/tail' and '/logs' — so the most specific matcher must
    // come first.
    const { calls } = renderPage([
      {
        urlMatch: '/api/v1/admin/logs/tail-all',
        status: 200,
        body: {
          files: ['app.log', 'error.log'],
          lines: ['app.log               hello', 'error.log             oops'],
          truncated: false,
        },
      },
      {
        urlMatch: '/api/v1/admin/logs/tail',
        status: 200,
        body: { file: 'app.log', lines: ['single'], truncated: false },
      },
      {
        urlMatch: '/api/v1/admin/logs',
        status: 200,
        body: { files: [{ name: 'app.log', size: 1, modified_at: '2026-05-31T00:00:00Z' }] },
      },
    ]);

    // The combined option is offered once files load.
    const combined = await screen.findByRole('option', { name: /all logs/i });
    expect(combined).toBeInTheDocument();

    await userEvent.selectOptions(screen.getByRole('combobox', { name: /log file/i }), ALL_LOGS);

    await waitFor(() => expect(screen.getByTestId('logs-output')).toHaveTextContent('hello'));
    expect(screen.getByTestId('logs-output')).toHaveTextContent('oops');
    expect(calls.some((c) => c.url.includes('/logs/tail-all'))).toBe(true);
  });

  it('re-tails when Refresh is clicked', async () => {
    const { calls } = renderPage([
      {
        urlMatch: '/api/v1/admin/logs/tail',
        status: 200,
        body: { file: 'app.log', lines: ['x'], truncated: false },
      },
      {
        urlMatch: '/api/v1/admin/logs',
        status: 200,
        body: { files: [{ name: 'app.log', size: 1, modified_at: '2026-05-31T00:00:00Z' }] },
      },
    ]);

    await screen.findByRole('option', { name: 'app.log' });
    await waitFor(() => expect(screen.getByTestId('logs-output')).toHaveTextContent('x'));

    const before = calls.filter((c) => c.url.includes('/logs/tail')).length;
    await userEvent.click(screen.getByRole('button', { name: /refresh/i }));
    await waitFor(() =>
      expect(calls.filter((c) => c.url.includes('/logs/tail')).length).toBeGreaterThan(before),
    );
  });
});
