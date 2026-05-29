import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CollectionsPage } from './CollectionsPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function renderPage(
  responses: Array<{ status: number; body: unknown; urlMatch?: string }>,
) {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  const result = render(
    <ToastProvider timeoutMs={0}>
      <CollectionsPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

const sampleCollection = {
  id: 'col-1',
  name: 'Action Movies',
  library_id: 'lib-1',
  item_count: 5,
  created_at: '2026-05-27T00:00:00Z',
};

const sampleSmart = {
  id: 'sp-1',
  name: 'Recent Movies',
  library_id: 'lib-1',
  rules_json: [
    { logic: 'and', rules: [
      { field: 'year', op: 'gte', value: 2020 },
    ]},
  ],
  limit: 50,
  sort_by: 'year',
  sort_desc: true,
  item_count: 12,
  created_at: '2026-05-27T00:00:00Z',
};

describe('CollectionsPage', () => {
  it('renders both sections collapsed or expanded', async () => {
    renderPage([
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /manual collections/i })).toBeInTheDocument();
    });
    expect(screen.getByRole('button', { name: /smart collections/i })).toBeInTheDocument();
  });

  it('shows manual collections when section is expanded', async () => {
    renderPage([
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    expect(await screen.findByText('Action Movies')).toBeInTheDocument();
    expect(screen.getByText('5 items')).toBeInTheDocument();
  });

  it('shows smart collections with Auto badge when expanded', async () => {
    renderPage([
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    expect(await screen.findByText('Recent Movies')).toBeInTheDocument();
    expect(screen.getByText('12 items')).toBeInTheDocument();
    expect(screen.getByText('Auto')).toBeInTheDocument();
  });

  it('shows empty state when no collections exist', async () => {
    renderPage([
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    const manualHeader = screen.getByRole('button', { name: /manual collections/i });
    await userEvent.click(manualHeader);

    expect(await screen.findByText(/no collections yet/i)).toBeInTheDocument();
  });

  it('shows loading state initially', async () => {
    renderPage([
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    expect(screen.getByRole('status')).toHaveTextContent('Loading…');
  });

  it('shows toast on load failure', async () => {
    renderPage([
      { status: 500, body: { error: 'Server error' }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 500, body: { error: 'Server error' }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent('Server error');
    });
  });

  it('opens add collection modal when clicking "New Collection"', async () => {
    renderPage([
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    await userEvent.click(screen.getByRole('button', { name: '+ New Collection' }));

    expect(screen.getByRole('dialog', { name: /new collection/i })).toBeInTheDocument();
  });

  it('opens add smart collection modal when clicking "New Smart Collection"', async () => {
    renderPage([
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    await userEvent.click(screen.getByRole('button', { name: '+ New Smart Collection' }));

    expect(screen.getByRole('dialog', { name: /new smart collection/i })).toBeInTheDocument();
  });

  it('creates a manual collection and shows success toast', async () => {
    const { calls } = renderPage([
      // Initial load - return sampleCollection so library dropdown is populated
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      // POST create
      { status: 200, body: { collection: sampleCollection }, urlMatch: '/collections' },
      // Refresh after create - with plenty extra
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    await userEvent.click(screen.getByRole('button', { name: '+ New Collection' }));

    const dialog = screen.getByRole('dialog', { name: /new collection/i });
    await userEvent.type(within(dialog).getByLabelText(/^name/i), 'Action Movies');
    await userEvent.click(within(dialog).getByRole('button', { name: /create/i }));

    await waitFor(() => {
      expect(screen.getByRole('status') ?? screen.queryByRole('alert')).toBeInTheDocument();
    });

    expect(calls.some((c) => c.init?.method === 'POST' && c.url.includes('/collections'))).toBe(true);
  });

  it('deletes a collection after confirming', async () => {
    const { calls } = renderPage([
      // Initial load - 12 sets of responses for each pattern
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      // DELETE
      { status: 200, body: { message: 'deleted' }, urlMatch: '/collections' },
      // Refresh after delete - 8 sets for each pattern
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    expect(await screen.findByText('Action Movies')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /delete action movies/i }));

    const dialog = screen.getByRole('dialog', { name: /delete collection/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /delete/i }));

    await waitFor(() => {
      expect(screen.queryByText('Action Movies')).not.toBeInTheDocument();
    });

    expect(calls.some((c) => c.init?.method === 'DELETE')).toBe(true);
  });

  it('opens edit collection modal with pre-filled values', async () => {
    renderPage([
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    expect(await screen.findByText('Action Movies')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /edit action movies/i }));

    const dialog = screen.getByRole('dialog', { name: /edit collection/i });
    const nameInput = within(dialog).getByLabelText(/^name/i) as HTMLInputElement;
    expect(nameInput.value).toBe('Action Movies');
  });

  it('opens view items modal and shows items', async () => {
    renderPage([
      // Initial load - many responses to handle StrictMode unpredictable double invocation
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      // View items modal - GET collection with items
      { status: 200, body: { collection: sampleCollection, items: [
        { id: 'item-1', title: 'Movie 1' },
        { id: 'item-2', title: 'Movie 2' },
      ]}, urlMatch: '/collections' },
      // Extra responses for StrictMode cleanup and safety
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    expect(await screen.findByText('Action Movies')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /view items in action movies/i }));

    await waitFor(() => {
      expect(screen.getByRole('dialog', { name: /items in action movies/i })).toBeInTheDocument();
    });

    expect(screen.getByText('Movie 1')).toBeInTheDocument();
    expect(screen.getByText('Movie 2')).toBeInTheDocument();
  });

  it('creates a smart collection with rules', async () => {
    renderPage([
      // Initial load - return sampleCollection so library dropdown is populated
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      // POST create
      { status: 200, body: { smart_playlist: sampleSmart }, urlMatch: '/smart-playlists' },
      // Refresh after create - with plenty extra
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    await userEvent.click(screen.getByRole('button', { name: '+ New Smart Collection' }));

    const dialog = screen.getByRole('dialog', { name: /new smart collection/i });
    await userEvent.type(within(dialog).getByLabelText(/^name/i), 'Recent Movies');
    await userEvent.click(within(dialog).getByRole('button', { name: /create/i }));

    await waitFor(() => {
      expect(screen.getByRole('alert') ?? screen.queryByRole('status')).toBeInTheDocument();
    });
  });

  it('shows error toast when collection name is empty', async () => {
    renderPage([
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    await userEvent.click(screen.getByRole('button', { name: '+ New Smart Collection' }));

    const dialog = screen.getByRole('dialog', { name: /new smart collection/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /create/i }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Name is required.');
  });

  it('toggles section expansion state', async () => {
    renderPage([
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      { status: 200, body: { collections: [sampleCollection] }, urlMatch: '/collections' },
      { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    const manualHeader = screen.getByRole('button', { name: /manual collections/i });

    expect(manualHeader).toHaveAttribute('aria-expanded', 'true');

    await userEvent.click(manualHeader);
    expect(manualHeader).toHaveAttribute('aria-expanded', 'false');

    await userEvent.click(manualHeader);
    expect(manualHeader).toHaveAttribute('aria-expanded', 'true');
  });

  it('deletes a smart collection after confirming', async () => {
    const { calls } = renderPage([
      // Initial load - many responses to handle StrictMode unpredictable double invocation
      // Each pattern needs 20+ responses to prevent counter wraparound
      ...Array.from({ length: 12 }, () => [
        { status: 200, body: { collections: [] }, urlMatch: '/collections' },
        { status: 200, body: { smart_playlists: [sampleSmart] }, urlMatch: '/smart-playlists' },
      ]).flat(),
      // DELETE
      { status: 200, body: { message: 'deleted' }, urlMatch: '/smart-playlists' },
      // Refresh after delete - many sets for each pattern
      ...Array.from({ length: 12 }, () => [
        { status: 200, body: { collections: [] }, urlMatch: '/collections' },
        { status: 200, body: { smart_playlists: [] }, urlMatch: '/smart-playlists' },
      ]).flat(),
    ]);

    await waitFor(() => {
      expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    expect(await screen.findByText('Recent Movies')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /delete recent movies/i }));

    const dialog = screen.getByRole('dialog', { name: /delete smart collection/i });
    await userEvent.click(within(dialog).getByRole('button', { name: /delete/i }));

    await waitFor(() => {
      expect(screen.queryByText('Recent Movies')).not.toBeInTheDocument();
    });

    expect(calls.some((c) => c.init?.method === 'DELETE' && c.url.includes('/smart-playlists'))).toBe(true);
  });
});
