import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UsersPage } from './UsersPage';
import { ApiClient } from '../api/client';
import { ToastProvider } from '../components/Toast';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

/**
 * Drive the page with a real ApiClient + a real ToastProvider against ordered,
 * real-shaped responses (the 0.4 fabricated-mock lesson).
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
      <UsersPage client={client} />
    </ToastProvider>,
  );
  return { calls, unmount: result.unmount };
}

const adminUser = {
  id: 1,
  username: 'alice',
  email: 'alice@example.com',
  is_admin: 1 as const,
  created_at: '2026-05-27T00:00:00Z',
  updated_at: '2026-05-27T00:00:00Z',
};

const regularUser = {
  id: 2,
  username: 'bob',
  email: 'bob@example.com',
  is_admin: 0 as const,
  created_at: '2026-05-27T00:00:00Z',
  updated_at: '2026-05-27T00:00:00Z',
};

afterEach(() => {
  vi.useRealTimers();
});

describe('UsersPage', () => {
  it('renders the list with username, email, role badge, and actions', async () => {
    renderPage([
      { status: 200, body: { users: [adminUser, regularUser] } },
    ]);

    expect(await screen.findByText('alice')).toBeInTheDocument();
    expect(screen.getByText('alice@example.com')).toBeInTheDocument();
    expect(screen.getByText('Admin')).toBeInTheDocument();
    expect(screen.getByText('bob')).toBeInTheDocument();
    expect(screen.getByText('bob@example.com')).toBeInTheDocument();
    expect(screen.getByText('User')).toBeInTheDocument();
  });

  it('shows an empty-state message when there are no users', async () => {
    renderPage([{ status: 200, body: { users: [] } }]);
    expect(
      await screen.findByText(/no users yet/i),
    ).toBeInTheDocument();
  });

  it('shows a toast when the list fails to load', async () => {
    renderPage([{ status: 500, body: { error: 'DB down' } }]);
    expect(await screen.findByRole('alert')).toHaveTextContent('DB down');
  });

  it('opens add-user modal and creates a user (201) → success toast → refresh', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [] } },
      { status: 201, body: { user_id: 9, message: 'User created.' } },
      { status: 200, body: { users: [adminUser] } },
    ]);

    await screen.findByText(/no users yet/i);
    await user.click(screen.getByRole('button', { name: 'Add user' }));

    expect(screen.getByRole('dialog', { name: /add user/i })).toBeInTheDocument();

    await user.type(screen.getByLabelText(/^Username/), 'charlie');
    await user.type(screen.getByLabelText(/^Email/), 'charlie@example.com');
    await user.type(screen.getByLabelText(/^Password/), 'secret123');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    expect(await screen.findByText('alice')).toBeInTheDocument();
  });

  it('shows field errors on 400 when adding a user', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [] } },
      { status: 400, body: { error: 'Validation failed', field_errors: { username: 'taken' } } },
    ]);

    await screen.findByText(/no users yet/i);
    await user.click(screen.getByRole('button', { name: 'Add user' }));

    await user.type(screen.getByLabelText(/^Username/), 'taken');
    await user.type(screen.getByLabelText(/^Email/), 'bad@example.com');
    await user.type(screen.getByLabelText(/^Password/), 'secret123');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Validation failed');
  });

  it('opens edit-user modal with pre-filled form', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Edit alice' }));

    const nameInput = screen.getByLabelText(/^Username/) as HTMLInputElement;
    expect(nameInput.value).toBe('alice');
    const emailInput = screen.getByLabelText(/^Email/) as HTMLInputElement;
    expect(emailInput.value).toBe('alice@example.com');
  });

  it('edit pre-fills is_admin and PUTs without password when unchanged', async () => {
    const user = userEvent.setup();
    const { calls } = renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { message: 'updated' } },
      { status: 200, body: { users: [adminUser] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Edit alice' }));

    const nameInput = screen.getByLabelText(/^Username/) as HTMLInputElement;
    await user.clear(nameInput);
    await user.type(nameInput, 'alice2');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => {
      const put = calls.find((c) => c.init?.method === 'PUT');
      expect(put).toBeDefined();
    });
    const put = calls.find((c) => c.init?.method === 'PUT')!;
    const body = JSON.parse(put.init!.body as string) as Record<string, unknown>;
    expect(body).toHaveProperty('username', 'alice2');
    expect(body).not.toHaveProperty('password');
  });

  it('delete confirm → success → refreshes list', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser, regularUser] } },
      { status: 200, body: { message: 'deleted' } },
      { status: 200, body: { users: [adminUser] } },
    ]);

    await screen.findByText('bob');
    await user.click(screen.getByRole('button', { name: 'Delete bob' }));

    const dialog = screen.getByRole('dialog', { name: 'Delete user' });
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    await waitFor(() => {
      expect(screen.queryByText('bob')).not.toBeInTheDocument();
    });
  });

  it('shows toast error when delete fails with last-admin message', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 400, body: { error: 'Cannot delete the last admin' } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Delete alice' }));

    const dialog = screen.getByRole('dialog', { name: 'Delete user' });
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Cannot delete the last admin');
  });

  it('shows toast error when delete fails with self-delete message', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 400, body: { error: 'Cannot delete your own account' } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Delete alice' }));

    const dialog = screen.getByRole('dialog', { name: 'Delete user' });
    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Cannot delete your own account');
  });

  it('set-admin toggle → POST success → list refreshed', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [regularUser] } },
      { status: 200, body: { message: 'admin updated' } },
      { status: 200, body: { users: [{ ...regularUser, is_admin: 1 as const }] } },
    ]);

    await screen.findByText('bob');
    await user.click(screen.getByRole('button', { name: 'Promote bob' }));

    await waitFor(() => {
      expect(screen.getByText('Admin')).toBeInTheDocument();
    });
  });

  it('reset-password → confirm modal shows returned plaintext password', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { message: 'Password reset.', new_password: 'TempPass99' } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Reset password for alice' }));

    await waitFor(() => {
      const input = screen.getByDisplayValue('TempPass99');
      expect(input).toBeInTheDocument();
    });
    expect(screen.getByLabelText(/^New password/)).toBeInTheDocument();
  });

  it('opens profiles modal and lists profiles', async () => {
    const user = userEvent.setup();
    const profiles = [
      { id: 1, user_id: 1, name: 'Adult', pin_hash: null, rating: 3, created_at: '2026-05-27T00:00:00Z' },
      { id: 2, user_id: 1, name: 'Kids', pin_hash: null, rating: 0, created_at: '2026-05-27T00:00:00Z' },
    ];
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles — alice/i });
    expect(within(dialog).getByText('Adult')).toBeInTheDocument();
    expect(within(dialog).getByText('Kids')).toBeInTheDocument();
  });

  it('profiles modal: add profile → POST → list refreshes', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles: [] } },
      { status: 201, body: { profile_id: 5, message: 'Profile created.' } },
      { status: 200, body: { profiles: [{ id: 5, user_id: 1, name: 'New', pin_hash: null, rating: 0, created_at: '2026-05-27T00:00:00Z' }] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    await user.click(within(dialog).getByRole('button', { name: /add profile/i }));

    await user.type(screen.getByLabelText(/^Name/), 'New');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    await waitFor(() => {
      expect(within(dialog).getByText('New')).toBeInTheDocument();
    });
  });

  it('profiles modal: edit profile → PUT → list refreshes', async () => {
    const user = userEvent.setup();
    const profile = { id: 1, user_id: 1, name: 'Adult', pin_hash: null, rating: 3, created_at: '2026-05-27T00:00:00Z' };
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles: [profile] } },
      { status: 200, body: { message: 'updated' } },
      { status: 200, body: { profiles: [{ ...profile, name: 'Adult Restricted' }] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    await user.click(within(dialog).getByRole('button', { name: 'Edit profile Adult' }));

    await user.clear(within(dialog).getByLabelText(/^Name/));
    await user.type(within(dialog).getByLabelText(/^Name/), 'Adult Restricted');
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));

    await waitFor(() => {
      expect(within(dialog).getByText('Adult Restricted')).toBeInTheDocument();
    });
  });

  it('profiles modal: delete profile → DELETE → list refreshes', async () => {
    const user = userEvent.setup();
    const profile = { id: 1, user_id: 1, name: 'Adult', pin_hash: null, rating: 3, created_at: '2026-05-27T00:00:00Z' };
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles: [profile] } },
      { status: 200, body: { message: 'deleted' } },
      { status: 200, body: { profiles: [] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    await user.click(within(dialog).getByRole('button', { name: 'Delete profile Adult' }));

    await user.click(within(dialog).getByRole('button', { name: 'Delete' }));

    await waitFor(() => {
      expect(within(dialog).queryByText('Adult')).not.toBeInTheDocument();
    });
  });

  it('profiles modal: max 5 guard shows error toast when adding 6th', async () => {
    const user = userEvent.setup();
    const fiveProfiles = Array.from({ length: 4 }, (_, i) => ({
      id: i + 1,
      user_id: 1,
      name: `Profile ${i + 1}`,
      pin_hash: null,
      rating: 0,
      created_at: '2026-05-27T00:00:00Z',
    }));
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles: fiveProfiles } },
      { status: 400, body: { error: 'Maximum 5 profiles allowed' } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    await user.click(within(dialog).getByRole('button', { name: /add profile/i }));

    await waitFor(() => {
      expect(screen.getByLabelText(/^Name/)).toBeInTheDocument();
    });
    await user.type(screen.getByLabelText(/^Name/), 'TooMany');
    await user.click(screen.getByRole('button', { name: 'Create' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Maximum 5 profiles allowed');
  });

  it('profiles modal: set PIN (4-digit) → POST /profiles/{id}/pin', async () => {
    const user = userEvent.setup();
    const profile = { id: 1, user_id: 1, name: 'Adult', pin_hash: null, rating: 3, created_at: '2026-05-27T00:00:00Z' };
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles: [profile] } },
      { status: 200, body: { message: 'PIN set.' } },
      { status: 200, body: { profiles: [{ ...profile, pin_hash: 'hash' }] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    await user.click(within(dialog).getByRole('button', { name: 'Set PIN for Adult' }));

    await user.type(screen.getByLabelText(/^PIN/), '1234');
    await user.click(screen.getByRole('button', { name: 'Set PIN' }));

    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: /set pin/i })).not.toBeInTheDocument();
    });
  });

  it('profiles modal: clear PIN → DELETE /profiles/{id}/pin', async () => {
    const user = userEvent.setup();
    const profileWithPin = { id: 1, user_id: 1, name: 'Adult', pin_hash: 'somehash', rating: 3, created_at: '2026-05-27T00:00:00Z' };
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles: [profileWithPin] } },
      { status: 200, body: { message: 'PIN cleared.' } },
      { status: 200, body: { profiles: [{ ...profileWithPin, pin_hash: null }] } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    await user.click(within(dialog).getByRole('button', { name: 'Clear PIN for Adult' }));

    await waitFor(() => {
      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
  });

  it('shows rating badge color for each rating value', async () => {
    const user = userEvent.setup();
    const profiles = [
      { id: 1, user_id: 1, name: 'G Rated', pin_hash: null, rating: 0, created_at: '2026-05-27T00:00:00Z' },
      { id: 2, user_id: 1, name: 'X Rated', pin_hash: null, rating: 5, created_at: '2026-05-27T00:00:00Z' },
    ];
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { profiles } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Manage profiles for alice' }));

    const dialog = await screen.findByRole('dialog', { name: /profiles/i });
    // Rating 0 (G) should render with rating-badge class
    expect(within(dialog).getByText('0')).toBeInTheDocument();
    expect(within(dialog).getByText('5')).toBeInTheDocument();
  });

  it('password reset confirm modal has a copy button', async () => {
    const user = userEvent.setup();
    renderPage([
      { status: 200, body: { users: [adminUser] } },
      { status: 200, body: { message: 'Password reset.', new_password: 'Secret42' } },
    ]);

    await screen.findByText('alice');
    await user.click(screen.getByRole('button', { name: 'Reset password for alice' }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Copy' })).toBeInTheDocument();
    });
  });
});
