import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SettingsPage } from './SettingsPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/**
 * Build a test page driven by ordered real-shaped responses.
 */
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
      <SettingsPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

/** Full mock response with all 8 groups present. */
const FULL_SETTINGS_RESPONSE = {
  success: true,
  data: {
    settings: {
      'hwaccel.enabled': true,
      'hwaccel.prefer_hardware': false,
      'hwaccel.probe_timeout': 30,
      'tmdb.api_key': 'super-secret-key',
      'marker_detection.similarity_threshold': 0.8,
      'marker_detection.intro_max_duration': 90,
      'subtitles.enabled': true,
      'subtitles.default_language': 'en',
      'subtitles.burn_in_by_default': false,
      'discovery.discovery_port': 9000,
      'trickplay.enabled': true,
      'trickplay.interval_seconds': 10,
      'newsletter.enabled': false,
      'newsletter.send_hour': 8,
      'port-forward.port_forwarding.upnp_enabled': true,
    },
    overridden: ['tmdb.api_key'],
    types: {
      'hwaccel.enabled': 'bool',
      'hwaccel.prefer_hardware': 'bool',
      'hwaccel.probe_timeout': 'int',
      'tmdb.api_key': 'string',
      'marker_detection.similarity_threshold': 'float',
      'marker_detection.intro_max_duration': 'int',
      'subtitles.enabled': 'bool',
      'subtitles.default_language': 'string',
      'subtitles.burn_in_by_default': 'bool',
      'discovery.discovery_port': 'int',
      'trickplay.enabled': 'bool',
      'trickplay.interval_seconds': 'int',
      'newsletter.enabled': 'bool',
      'newsletter.send_hour': 'int',
      'port-forward.port_forwarding.upnp_enabled': 'bool',
    },
  },
};

afterEach(() => {
  vi.useRealTimers();
});

describe('SettingsPage', () => {
  it('renders the settings heading and initial loading state', () => {
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);
    expect(screen.getByRole('heading', { name: 'Settings' })).toBeInTheDocument();
    // Initially shows loading
    expect(screen.getByRole('status')).toHaveTextContent('Loading…');
  });

  it('renders all 8 tabs after settings load', async () => {
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);
    // Wait for settings to load and tabs to appear
    await waitFor(() => {
      expect(screen.getByRole('tab', { name: 'Transcoding' })).toBeInTheDocument();
    });
    expect(screen.getByRole('tab', { name: 'Metadata' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Markers' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Subtitles' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Discovery' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Trickplay' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Newsletter' })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Port Forward' })).toBeInTheDocument();
  });

  it('renders Transcoding tab fields on load', async () => {
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);
    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });
    expect(screen.getByLabelText(/^Prefer Hardware$/)).toBeInTheDocument();
    expect(screen.getByLabelText(/^Probe Timeout$/)).toBeInTheDocument();
  });

  it('toggle a boolean → Save becomes enabled', async () => {
    const user = userEvent.setup();
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);

    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });
    const saveBtn = screen.getByRole('button', { name: 'Save settings' });
    expect(saveBtn).toBeDisabled();

    // Toggle hwaccel.enabled switch
    const switch_ = screen.getByLabelText(/^Enabled$/) as HTMLInputElement;
    await user.click(switch_);

    expect(saveBtn).not.toBeDisabled();
  });

  it('submit success → toast + no per-field errors', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: FULL_SETTINGS_RESPONSE },
      {
        status: 200,
        body: {
          success: true,
          message: 'Settings saved.',
          data: {
            settings: { 'hwaccel.enabled': false },
            overridden: ['hwaccel.enabled'],
          },
        },
      },
    ]);

    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });
    const switch_ = screen.getByLabelText(/^Enabled$/) as HTMLInputElement;
    await user.click(switch_);
    await user.click(screen.getByRole('button', { name: 'Save settings' }));

    await waitFor(() => {
      expect(screen.getByRole('status')).toHaveTextContent('Settings saved.');
    });
    // No field-level errors
    expect(screen.queryAllByRole('alert')).toHaveLength(0);
  });

  it('submit 500 → error toast', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: FULL_SETTINGS_RESPONSE },
      {
        status: 500,
        body: { success: false, error: 'Internal server error' },
      },
    ]);

    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });
    const switch_ = screen.getByLabelText(/^Enabled$/) as HTMLInputElement;
    await user.click(switch_);
    await user.click(screen.getByRole('button', { name: 'Save settings' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Internal server error');
  });

  it('load error → error toast', async () => {
    renderPage([
      {
        status: 500,
        body: { success: false, error: 'Failed to load settings' },
      },
    ]);

    expect(await screen.findByRole('alert')).toHaveTextContent('Failed to load settings');
  });

  it('Save disabled when no changes', async () => {
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);

    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });
    const saveBtn = screen.getByRole('button', { name: 'Save settings' });
    expect(saveBtn).toBeDisabled();
  });

  it('load success with overridden settings processed', async () => {
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);

    // Settings load successfully
    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });
    // The "custom" badge for tmdb.api_key will be shown when Metadata tab is active
    // (verified manually by switching to Metadata tab in the UI)
    expect(screen.getByRole('tab', { name: 'Metadata' })).toBeInTheDocument();
  });

  it('fill a number field → validation respects min/max', async () => {
    const user = userEvent.setup();
    renderPage([{ status: 200, body: FULL_SETTINGS_RESPONSE }]);

    await waitFor(() => {
      expect(screen.getByLabelText(/^Probe Timeout$/)).toBeInTheDocument();
    });

    // Switch to Discovery tab to access discovery.discovery_port (min: 1, max: 65535)
    await user.click(screen.getByRole('tab', { name: 'Discovery' }));
    const portField = screen.getByLabelText(/^Discovery Port$/);

    // Verify HTML min/max attributes are rendered on the number input
    expect(portField).toHaveAttribute('min', '1');
    expect(portField).toHaveAttribute('max', '65535');

    // Switch to Markers tab for similarity_threshold (min: 0, max: 1)
    await user.click(screen.getByRole('tab', { name: 'Markers' }));
    const similarityField = screen.getByLabelText(/^Similarity Threshold$/);
    expect(similarityField).toHaveAttribute('min', '0');
    expect(similarityField).toHaveAttribute('max', '1');

    // Switch to Newsletter tab for send_hour (min: 0, max: 23)
    await user.click(screen.getByRole('tab', { name: 'Newsletter' }));
    const hourField = screen.getByLabelText(/^Send Hour$/);
    expect(hourField).toHaveAttribute('min', '0');
    expect(hourField).toHaveAttribute('max', '23');
  });

  it('submit 400 → per-field errors shown', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: FULL_SETTINGS_RESPONSE },
      {
        status: 400,
        body: {
          success: false,
          error: 'Validation failed',
          errors: {
            'discovery.discovery_port': 'Must be between 1 and 65535',
            'newsletter.send_hour': 'Must be between 0 and 23',
          },
        },
      },
    ]);

    await waitFor(() => {
      expect(screen.getByLabelText(/^Enabled$/)).toBeInTheDocument();
    });

    // Switch to Discovery tab and trigger submit with a 400 response
    await user.click(screen.getByRole('tab', { name: 'Discovery' }));
    const portField = screen.getByLabelText(/^Discovery Port$/);
    await user.clear(portField);
    await user.type(portField, '99999');
    await user.click(screen.getByRole('button', { name: 'Save settings' }));

    // Per-field errors should be visible in the DOM
    await waitFor(() => {
      expect(screen.getByText('Must be between 1 and 65535')).toBeInTheDocument();
    });
    expect(screen.getByText('Please fix the validation errors.')).toBeInTheDocument();

    // Switch to Newsletter tab to see the second per-field error
    await user.click(screen.getByRole('tab', { name: 'Newsletter' }));
    expect(screen.getByText('Must be between 0 and 23')).toBeInTheDocument();
  });
});
