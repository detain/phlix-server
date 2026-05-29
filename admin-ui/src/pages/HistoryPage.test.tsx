import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import * as ReactRouter from 'react-router';
import { HistoryPage } from './HistoryPage';
import { ApiClient, ApiError } from '../api/client';
import { HistoryApi, type RecentlyWatchedItem } from '../api/history';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

// Mock useNavigate
const mockNavigate = vi.fn();
vi.spyOn(ReactRouter, 'useNavigate').mockReturnValue(mockNavigate);

const sampleItem: RecentlyWatchedItem = {
  id: 'wh-1',
  media_item_id: 'media-1',
  name: 'Test Movie',
  title: 'Test Movie',
  media_type: 'movie',
  type: 'movie',
  progress_percent: 45.5,
  last_watched_at: '2026-05-28T10:30:00Z',
  thumbnail_url: 'https://example.com/thumb.jpg',
  poster_url: 'https://example.com/poster.jpg',
};

describe('HistoryPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the list with items', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);

    // Set up mock BEFORE rendering - StrictMode runs effect twice
    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([sampleItem]);

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });
    expect(screen.getByText('movie')).toBeInTheDocument();
    expect(screen.getByText(/Watched/)).toBeInTheDocument();
  });

  it('shows empty state when no items', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);

    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([])
      .mockResolvedValueOnce([]);

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    expect(await screen.findByText(/no watch history yet/i)).toBeInTheDocument();
  });

  it('shows a toast when load fails', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);

    // Use ApiError so the component extracts the message properly
    vi.spyOn(api, 'getRecentlyWatched')
      .mockRejectedValueOnce(new ApiError('Server error', 500))
      .mockRejectedValueOnce(new ApiError('Server error', 500));

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    expect(await screen.findByRole('alert')).toHaveTextContent('Server error');
  });

  it('remove button calls API and refreshes list', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);
    const user = userEvent.setup();

    // Set up all mocks for getRecentlyWatched (initial load + refresh after remove)
    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem]) // StrictMode first call
      .mockResolvedValueOnce([sampleItem]) // StrictMode second call
      .mockResolvedValueOnce([]) // Refresh after remove
      .mockResolvedValueOnce([]); // StrictMode refresh call

    // Mock the remove endpoint - returns mock result without calling real API
    vi.spyOn(api, 'removeFromHistory').mockResolvedValueOnce({
      message: 'Removed from watch history',
    });

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // Click the remove button (×)
    const removeBtn = screen.getByRole('button', { name: /remove.*test movie/i });
    await user.click(removeBtn);

    // Should show success toast
    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent(/removed/i);
    });

    // Verify removeFromHistory was called
    expect(api.removeFromHistory).toHaveBeenCalledWith('media-1');
  });

  it('clear all button shows confirmation modal', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);
    const user = userEvent.setup();

    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([sampleItem]);

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // Click "Clear All" button in header (has smaller size class)
    const clearBtn = screen.getByRole('button', { name: 'Clear All' });
    await user.click(clearBtn);

    // Modal should be visible
    expect(
      screen.getByRole('dialog', { name: 'Clear Watch History' }),
    ).toBeInTheDocument();
    expect(screen.getByText(/cannot be undone/i)).toBeInTheDocument();
  });

  it('confirm clear all calls API and clears list', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);
    const user = userEvent.setup();

    // Initial load + refresh after clear
    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([]) // Refresh after clear
      .mockResolvedValueOnce([]);

    // Mock the clear endpoint
    vi.spyOn(api, 'clearHistory').mockResolvedValueOnce({
      message: 'Watch history cleared',
    });

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // Open modal by clicking Clear All in header
    await user.click(screen.getByRole('button', { name: 'Clear All' }));

    // Confirm clear by clicking the button inside the modal
    // Use within() to scope query to the modal dialog
    const modal = screen.getByRole('dialog', { name: 'Clear Watch History' });
    const modalConfirmBtn = within(modal).getByRole('button', { name: 'Clear All' });
    await user.click(modalConfirmBtn);

    // Should show success toast and list should be empty
    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent(/cleared/i);
    });

    // Verify clearHistory was called
    expect(api.clearHistory).toHaveBeenCalled();
  });

  it('shows progress bar for in-progress items', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);

    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([sampleItem]);

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // Progress bar should be visible
    const progressBar = screen.getByRole('progressbar');
    expect(progressBar).toBeInTheDocument();
    expect(progressBar).toHaveAttribute('aria-valuenow', '45.5');

    // Continue button should be visible for in-progress items
    expect(screen.getByRole('button', { name: /continue/i })).toBeInTheDocument();
  });

  it('shows completed items without progress bar or continue button', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);
    const completedItem: RecentlyWatchedItem = {
      ...sampleItem,
      progress_percent: 100,
    };

    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([completedItem])
      .mockResolvedValueOnce([completedItem]);

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // No progress bar for completed items
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    // No continue button
    expect(screen.queryByRole('button', { name: /continue/i })).not.toBeInTheDocument();
  });

  it('shows toast on remove failure', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);
    const user = userEvent.setup();

    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([sampleItem]);

    // Mock remove to fail - use ApiError to get proper message extraction
    vi.spyOn(api, 'removeFromHistory').mockRejectedValueOnce(
      new ApiError('Item not found', 404),
    );

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // Click the remove button
    await user.click(screen.getByRole('button', { name: /remove.*test movie/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Item not found');
  });

  it('shows toast on clear failure', async () => {
    const { fetch } = makeFetch([]);
    const client = new ApiClient({
      baseUrl: '',
      tokenStore: new MemoryTokenStore({ access: 't' }),
      fetchImpl: fetch,
    });
    const api = new HistoryApi(client);
    const user = userEvent.setup();

    vi.spyOn(api, 'getRecentlyWatched')
      .mockResolvedValueOnce([sampleItem])
      .mockResolvedValueOnce([sampleItem]);

    // Mock clear to fail - use ApiError to get proper message extraction
    vi.spyOn(api, 'clearHistory').mockRejectedValueOnce(
      new ApiError('Unauthorized', 401),
    );

    render(
      <ToastProvider timeoutMs={0}>
        <HistoryPage client={client} api={api} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Test Movie')).toBeInTheDocument();
    });

    // Open modal by clicking Clear All in header
    await user.click(screen.getByRole('button', { name: 'Clear All' }));

    // Confirm clear - should fail - click inside modal
    const modal = screen.getByRole('dialog', { name: 'Clear Watch History' });
    const modalConfirmBtn = within(modal).getByRole('button', { name: 'Clear All' });
    await user.click(modalConfirmBtn);

    expect(await screen.findByRole('alert')).toHaveTextContent('Unauthorized');
  });
});
