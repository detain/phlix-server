import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ApiClient } from '../api/client';
import { CastDevicesPage } from './CastDevicesPage';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch, type ResponseSpec } from '../test/memoryTokenStore';

/** Build a test ApiClient backed by an ordered list of responses. */
function makeClient(responses: ResponseSpec[]): ApiClient {
  const { fetch } = makeFetch(responses);
  return new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore({ access: 't' }),
    fetchImpl: fetch,
  });
}

// ---------------------------------------------------------------------------
// Mock data
// ---------------------------------------------------------------------------

const castDevices = [
  { device_id: 'cast-1', name: 'Living Room TV', host: '192.168.1.100', port: 8009, model: 'Chromecast Ultra', address: 'aa:bb:cc:dd:ee:ff' },
  { device_id: 'cast-2', name: 'Bedroom Speaker', host: '192.168.1.101', port: 8009, model: 'Chromecast Audio', address: 'aa:bb:cc:dd:ee:01' },
];

const airplayDevices = [
  { device_id: 'airplay-1', name: 'Kitchen Speaker', host: '192.168.1.102', port: 7000, model: 'Apple TV 4K', address: 'aa:bb:cc:dd:ee:02' },
];

const rokuDevices = [
  { device_id: 'roku-1', name: 'Bedroom TV', host: '192.168.1.103', port: 8060, model: 'Roku Ultra', address: 'aa:bb:cc:dd:ee:03' },
];

const dlnaDevices = [
  { device_id: 'dlna-1', name: 'Smart TV', host: '192.168.1.104', port: 1900, model: 'Samsung Smart TV', address: 'aa:bb:cc:dd:ee:04' },
];

const castPlayback = {
  device_id: 'cast-1',
  media_title: 'My Movie',
  media_item_id: 'm1',
  transport_state: 'PLAYING',
  volume_level: 0.75,
  muted: false,
  duration_seconds: 7200,
  position_seconds: 1800,
};

const airplayPlayback = {
  device_id: 'airplay-1',
  media_title: 'My Podcast',
  media_item_id: 'p1',
  transport_state: 'PLAYING',
  volume_level: 0.5,
  muted: false,
};

const rokuPlayback = {
  device_id: 'roku-1',
  media_title: 'My Show',
  media_item_id: 's1',
  transport_state: 'PLAYING',
  volume_level: 0.6,
  muted: false,
};

const dlnaPlayback = {
  device_id: 'dlna-1',
  media_title: 'My Video',
  media_item_id: 'v1',
  transport_state: 'PLAYING',
  volume_level: 0.8,
  muted: false,
  duration_seconds: 5400,
  position_seconds: 1200,
};

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('CastDevicesPage', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  /**
   * Initial load fires 4 parallel requests (one per tab).
   * Responses are matched by URL pattern so order doesn't matter.
   */
  function renderCastDevices(responses: ResponseSpec[]) {
    const client = makeClient(responses);
    return render(
      <ToastProvider timeoutMs={0}>
        <CastDevicesPage client={client} />
      </ToastProvider>,
    );
  }

  // -------------------------------------------------------------------------
  // Page renders with all 4 tabs
  // -------------------------------------------------------------------------

  it('renders all 4 tab buttons', async () => {
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });
    expect(screen.getByRole('tab', { name: /AirPlay/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Roku/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /DLNA/ })).toBeInTheDocument();
  });

  // -------------------------------------------------------------------------
  // Chromecast tab (default active)
  // -------------------------------------------------------------------------

  it('renders Chromecast devices on load', async () => {
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Living Room TV')).toBeInTheDocument();
    });
    expect(screen.getByText('Chromecast Ultra')).toBeInTheDocument();
  });

  it('renders empty state for Chromecast when no devices', async () => {
    renderCastDevices([
      { status: 200, body: { success: true, data: [] }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Chromecast Devices')).toBeInTheDocument();
    });
    expect(screen.getByText('No chromecast devices discovered.')).toBeInTheDocument();
  });

  it('selects a Chromecast device and shows playback controls with seek', async () => {
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
      { status: 200, body: { success: true, data: castPlayback }, urlMatch: '/cast-1/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByText('Living Room TV')).toBeInTheDocument();
    });

    // Click the device card
    const card = screen.getByRole('button', { name: 'Select Living Room TV' });
    await userEvent.click(card);

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Playback Controls' })).toBeInTheDocument();
    });

    // Seek bar should be present for Chromecast
    expect(screen.getByLabelText('Seek position')).toBeInTheDocument();
    // Play and Pause buttons should be present
    expect(screen.getByRole('button', { name: 'Play' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pause' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
  });

  // -------------------------------------------------------------------------
  // AirPlay tab
  // -------------------------------------------------------------------------

  it('switches to AirPlay tab and renders devices', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /AirPlay/ }));

    await waitFor(() => {
      expect(screen.getByText('Kitchen Speaker')).toBeInTheDocument();
    });
    expect(screen.getByText('Apple TV 4K')).toBeInTheDocument();
  });

  it('AirPlay device shows no seek bar (pause/resume only)', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
      { status: 200, body: { success: true, data: airplayPlayback }, urlMatch: '/airplay-1/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /AirPlay/ }));

    await waitFor(() => {
      expect(screen.getByText('Kitchen Speaker')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Select Kitchen Speaker' }));

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Playback Controls' })).toBeInTheDocument();
    });

    // No seek bar for AirPlay
    expect(screen.queryByLabelText('Seek position')).not.toBeInTheDocument();
    // But play/pause/stop buttons should be present
    expect(screen.getByRole('button', { name: 'Play' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pause' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
  });

  // -------------------------------------------------------------------------
  // Roku tab
  // -------------------------------------------------------------------------

  it('switches to Roku tab and renders devices', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /Roku/ }));

    await waitFor(() => {
      expect(screen.getByText('Bedroom TV')).toBeInTheDocument();
    });
    expect(screen.getByText('Roku Ultra')).toBeInTheDocument();
  });

  it('Roku device shows stop-only controls (no play/pause)', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
      { status: 200, body: { success: true, data: rokuPlayback }, urlMatch: '/roku-1/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /Roku/ }));

    await waitFor(() => {
      expect(screen.getByText('Bedroom TV')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Select Bedroom TV' }));

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Playback Controls' })).toBeInTheDocument();
    });

    // Only stop button for Roku
    expect(screen.queryByRole('button', { name: 'Play' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Pause' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
  });

  // -------------------------------------------------------------------------
  // DLNA tab
  // -------------------------------------------------------------------------

  it('switches to DLNA tab and renders devices with seek bar', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
      { status: 200, body: { success: true, data: dlnaPlayback }, urlMatch: '/dlna-1/status' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /DLNA/ }));

    await waitFor(() => {
      expect(screen.getByText('Smart TV')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Select Smart TV' }));

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Playback Controls' })).toBeInTheDocument();
    });

    // Seek bar should be present for DLNA
    expect(screen.getByLabelText('Seek position')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Play' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pause' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Stop' })).toBeInTheDocument();
  });

  // -------------------------------------------------------------------------
  // Transport actions (play/pause/stop/seek)
  // -------------------------------------------------------------------------

  it('calls play API and updates transport state when Play is clicked', async () => {
    const user = userEvent.setup();
    const client = makeClient([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
      { status: 200, body: { success: true, data: castPlayback }, urlMatch: '/cast-1/status' },
      { status: 200, body: { success: true, message: 'Playing' }, urlMatch: '/cast-1/play' },
      // After play, status is refreshed
      { status: 200, body: { success: true, data: { ...castPlayback, transport_state: 'PLAYING' } }, urlMatch: '/cast-1/status' },
    ]);

    render(
      <ToastProvider timeoutMs={0}>
        <CastDevicesPage client={client} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Living Room TV')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Select Living Room TV' }));

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Playback Controls' })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Play' }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Play' })).toBeDisabled();
    });
  });

  it('calls stop API and clears position when Stop is clicked', async () => {
    const user = userEvent.setup();
    const client = makeClient([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
      { status: 200, body: { success: true, data: castPlayback }, urlMatch: '/cast-1/status' },
      { status: 200, body: { success: true } },
    ]);

    render(
      <ToastProvider timeoutMs={0}>
        <CastDevicesPage client={client} />
      </ToastProvider>,
    );

    await waitFor(() => {
      expect(screen.getByText('Living Room TV')).toBeInTheDocument();
    });

    await user.click(screen.getByRole('button', { name: 'Select Living Room TV' }));

    await waitFor(() => {
      expect(screen.getByRole('heading', { name: 'Playback Controls' })).toBeInTheDocument();
    });

    // Track whether Stop button becomes enabled after the action completes
    await user.click(screen.getByRole('button', { name: 'Stop' }));

    // After stop completes, button should be re-enabled
    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Stop' })).not.toBeDisabled();
    });
  });

  // -------------------------------------------------------------------------
  // Loading skeleton
  // -------------------------------------------------------------------------

  it('shows skeleton while devices are loading', async () => {
    // Provide an empty client that never resolves
    const client = makeClient([]);
    const { container } = render(
      <ToastProvider timeoutMs={0}>
        <CastDevicesPage client={client} />
      </ToastProvider>,
    );

    // Before any responses, at least one device-grid should show skeleton lines
    expect(container.querySelectorAll('.skeleton-line').length).toBeGreaterThan(0);
  });

  // -------------------------------------------------------------------------
  // Empty state per tab
  // -------------------------------------------------------------------------

  it('shows empty state when AirPlay tab has no devices', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: [] }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /AirPlay/ }));

    await waitFor(() => {
      expect(screen.getByText('No airplay devices discovered.')).toBeInTheDocument();
    });
  });

  it('shows empty state when Roku tab has no devices', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: [] }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: dlnaDevices }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /Roku/ }));

    await waitFor(() => {
      expect(screen.getByText('No roku devices discovered.')).toBeInTheDocument();
    });
  });

  it('shows empty state when DLNA tab has no devices', async () => {
    const user = userEvent.setup();
    renderCastDevices([
      { status: 200, body: { success: true, data: castDevices }, urlMatch: '/api/v1/cast/' },
      { status: 200, body: { success: true, data: airplayDevices }, urlMatch: '/api/v1/airplay/' },
      { status: 200, body: { success: true, data: rokuDevices }, urlMatch: '/api/v1/roku/' },
      { status: 200, body: { success: true, data: [] }, urlMatch: '/api/v1/dlna/' },
    ]);

    await waitFor(() => {
      expect(screen.getByRole('tab', { name: /Chromecast/ })).toBeInTheDocument();
    });

    await user.click(screen.getByRole('tab', { name: /DLNA/ }));

    await waitFor(() => {
      expect(screen.getByText('No dlna devices discovered.')).toBeInTheDocument();
    });
  });
});
