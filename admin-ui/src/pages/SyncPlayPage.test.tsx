import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SyncPlayPage } from './SyncPlayPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

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
      <SyncPlayPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('SyncPlayPage', () => {
  it('shows heading and loading state', async () => {
    renderPage([{ status: 200, body: { groups: [] } }]);
    expect(screen.getByRole('heading', { name: 'SyncPlay' })).toBeInTheDocument();
    expect(screen.getByText('Loading groups…')).toBeInTheDocument();
  });

  it('shows empty state when no groups exist', async () => {
    renderPage([{ status: 200, body: { groups: [] } }]);
    await waitFor(() => {
      expect(screen.getByText(/No groups yet/)).toBeInTheDocument();
    });
  });

  it('renders group cards when groups exist', async () => {
    renderPage([
      {
        status: 200,
        body: {
          groups: [
            {
              id: 'sp_abc123',
              name: 'Movie Night',
              member_count: 3,
              has_password: false,
              current_media: null,
              is_playing: false,
            },
          ],
        },
      },
    ]);
    await waitFor(() => {
      expect(screen.getByText('Movie Night')).toBeInTheDocument();
      expect(screen.getByText('3 members')).toBeInTheDocument();
    });
  });

  it('shows Create Group button', async () => {
    renderPage([{ status: 200, body: { groups: [] } }]);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: '+ Create Group' })).toBeInTheDocument();
    });
  });

  it('opens create modal when clicking Create Group button', async () => {
    const user = userEvent.setup();
    renderPage([{ status: 200, body: { groups: [] } }]);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: '+ Create Group' })).toBeInTheDocument();
    });
    const createBtn = screen.getByRole('button', { name: '+ Create Group' });
    await user.click(createBtn);
    expect(screen.getByText('Create SyncPlay Group')).toBeInTheDocument();
  });

  it('renders password-protected group with badge', async () => {
    renderPage([
      {
        status: 200,
        body: {
          groups: [
            {
              id: 'sp_abc123',
              name: 'Private Party',
              member_count: 2,
              has_password: true,
              current_media: null,
              is_playing: false,
            },
          ],
        },
      },
    ]);
    await waitFor(() => {
      expect(screen.getByText('Private Party')).toBeInTheDocument();
      expect(screen.getByText('Password')).toBeInTheDocument();
    });
  });

  it('opens join modal when clicking Join button', async () => {
    const user = userEvent.setup();
    renderPage([
      {
        status: 200,
        body: {
          groups: [
            {
              id: 'sp_abc123',
              name: 'Movie Night',
              member_count: 3,
              has_password: false,
              current_media: null,
              is_playing: false,
            },
          ],
        },
      },
    ]);
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Join/i })).toBeInTheDocument();
    });
    const joinBtn = screen.getByRole('button', { name: /Join/i });
    await user.click(joinBtn);
    expect(screen.getByText('Join SyncPlay Group')).toBeInTheDocument();
  });

  it('shows playing badge when group is playing', async () => {
    renderPage([
      {
        status: 200,
        body: {
          groups: [
            {
              id: 'sp_abc123',
              name: 'Movie Night',
              member_count: 3,
              has_password: false,
              current_media: 'media-1',
              is_playing: true,
            },
          ],
        },
      },
    ]);
    await waitFor(() => {
      expect(screen.getByText('Movie Night')).toBeInTheDocument();
      expect(screen.getByText('Playing')).toBeInTheDocument();
    });
  });
});
