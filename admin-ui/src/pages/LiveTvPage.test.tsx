import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LiveTvPage } from './LiveTvPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/** Build a test page driven by responses with urlMatch for correct routing. */
function renderPage(
  responses: Array<{ status: number; body: unknown; urlMatch?: string }>,
): { calls: ReturnType<typeof makeFetch>['calls']; unmount: () => void } {
  const { fetch, calls } = makeFetch(responses);
  const client = new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
  const result = render(
    <ToastProvider timeoutMs={0}>
      <LiveTvPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

afterEach(() => {
  vi.useRealTimers();
});

describe('LiveTvPage', () => {
  describe('All 4 sections render', () => {
    it('shows heading and all 4 section headings when loaded', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await waitFor(() => {
        expect(screen.getByRole('heading', { name: 'Live TV / DVR' })).toBeInTheDocument();
      });
      expect(screen.getByText('Tuners')).toBeInTheDocument();
      expect(screen.getByText('Guide / EPG')).toBeInTheDocument();
      expect(screen.getByText('Recordings')).toBeInTheDocument();
      expect(screen.getByText('Series Rules')).toBeInTheDocument();
    });
  });

  describe('Tuners section', () => {
    it('shows empty state when no tuners found', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await waitFor(() => {
        expect(screen.getByText('No tuners found')).toBeInTheDocument();
      });
    });

    it('shows Scan for Tuners button', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Scan for Tuners' })).toBeInTheDocument();
      });
    });

    it('shows tuner cards when tuners exist', async () => {
      renderPage([
        {
          status: 200,
          body: {
            success: true,
            tuners: [{
              tuner_id: 'hdhr-1',
              type: 'HDHomeRun',
              name: 'Front Room',
              host: '192.168.1.100',
              port: 5004,
              enabled: true,
              status: 'active',
            }],
          },
          urlMatch: '/livetv/tuners',
        },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await waitFor(() => {
        expect(screen.getByText('Front Room')).toBeInTheDocument();
        expect(screen.getByText('HDHomeRun')).toBeInTheDocument();
        expect(screen.getByText('192.168.1.100:5004')).toBeInTheDocument();
      });
    });
  });

  describe('Guide section', () => {
    it('shows date picker and Refresh Guide button', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      // Expand Guide section
      await userEvent.click(screen.getByText('Guide / EPG'));

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Refresh Guide' })).toBeInTheDocument();
        expect(screen.getByRole('group', { name: 'Guide date' })).toBeInTheDocument();
      });
    });

    it('shows programme cards when guide data exists', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        {
          status: 200,
          body: {
            success: true,
            programs: [{
              id: 'prog-1',
              title: 'Evening News',
              description: 'Daily national news bulletin.',
              start_time: 1700000000,
              end_time: 1700003600,
            }],
          },
          urlMatch: '/livetv/guide',
        },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await userEvent.click(screen.getByText('Guide / EPG'));

      await waitFor(() => {
        expect(screen.getByText('Evening News')).toBeInTheDocument();
      });
    });
  });

  describe('Recordings section', () => {
    it('shows All / Upcoming / By Series tabs and Schedule Recording button', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await userEvent.click(screen.getByText('Recordings'));

      await waitFor(() => {
        expect(screen.getByRole('tab', { name: 'All Recordings' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Upcoming' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'By Series' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Schedule Recording' })).toBeInTheDocument();
      });
    });

    it('shows recording cards when recordings exist', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        {
          status: 200,
          body: {
            success: true,
            recordings: [{
              id: 'rec-1',
              channel_id: 'ch-1',
              channel_name: 'BBC One',
              program_title: 'The Nine O\'Clock News',
              start_time: 1700000000,
              end_time: 1700003600,
              status: 'completed',
            }],
          },
          urlMatch: '/livetv/recordings',
        },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
      ]);

      await userEvent.click(screen.getByText('Recordings'));

      await waitFor(() => {
        expect(screen.getByText('The Nine O\'Clock News')).toBeInTheDocument();
        expect(screen.getByText('completed')).toBeInTheDocument();
      });
    });
  });

  describe('Series Rules section', () => {
    it('shows Add Rule button', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        { status: 200, body: { success: true, rules: [] }, urlMatch: '/livetv/series-rules' },
        { status: 200, body: { success: true, channels: [] }, urlMatch: '/livetv/channels' },
      ]);

      await userEvent.click(screen.getByText('Series Rules'));

      await waitFor(() => {
        expect(screen.getByRole('button', { name: 'Add Rule' })).toBeInTheDocument();
      });
    });

    it('shows rule cards when rules exist', async () => {
      renderPage([
        { status: 200, body: { success: true, tuners: [] }, urlMatch: '/livetv/tuners' },
        { status: 200, body: { success: true, programs: [] }, urlMatch: '/livetv/guide' },
        { status: 200, body: { success: true, recordings: [] }, urlMatch: '/livetv/recordings' },
        {
          status: 200,
          body: {
            success: true,
            rules: [{
              id: 'rule-1',
              title_pattern: 'News%',
              channel_id: 'ch-1',
              priority: 3,
              keep_until: 'space',
              enabled: true,
            }],
          },
          urlMatch: '/livetv/series-rules',
        },
        {
          status: 200,
          body: {
            success: true,
            channels: [{ id: 'ch-1', name: 'BBC One', number: '1', enabled: true }],
          },
          urlMatch: '/livetv/channels',
        },
      ]);

      await userEvent.click(screen.getByText('Series Rules'));

      await waitFor(() => {
        expect(screen.getByText('News%')).toBeInTheDocument();
      });
    });
  });
});
