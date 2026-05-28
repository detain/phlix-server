/**
 * UsersPage — the admin "Users" feature page.
 *
 * Lists server users (username, email, admin badge, actions) in the shared
 * {@link DataTable}; adds/edits/deletes through a {@link Modal} + {@link Form};
 * set-admin and reset-password actions; and a per-user nested "Profiles" modal
 * that lists/creates/edits/deletes profiles and manages per-profile PINs.
 *
 * Security:
 *  - Every server/API string is rendered as a React text child — no
 *    `dangerouslySetInnerHTML`.
 *  - No tokens in URLs. Admin gate is handled server-side + `useAdminGuard`
 *    client-side (in the App shell).
 *
 * Async/resident rules:
 *  - No polling needed for this page (scan is the only async operation in
 *    this phase). Intervals are not used here.
 *
 * @since 1.2c
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  UsersApi,
  type User,
  type CreateUserInput,
  type UpdateUserInput,
} from '../api/users';
import {
  ProfilesApi,
  RATING_OPTIONS,
  type Profile,
  type CreateProfileInput,
  type UpdateProfileInput,
} from '../api/profiles';
import { DataTable, type Column } from '../components/DataTable';
import { Modal } from '../components/Modal';
import { Form, Field } from '../components/Form';
import { useToast } from '../components/Toast';

export interface UsersPageProps {
  client: ApiClient;
}

/** Rating badge colors (copied from common media rating context). */
const RATING_COLORS: Record<number, string> = {
  0: '#3ecf8e', // G — green
  1: '#4a8cff', // PG — blue
  2: '#f5a623', // PG-13 — amber
  3: '#ff5d5d', // R — red
  4: '#b94a4a', // NC-17 — dark red
  5: '#8b0000', // X — dark red
  6: '#8a93a3', // UNRATED — muted
};

export function UsersPage({ client }: UsersPageProps): JSX.Element {
  const usersApiRef = useRef(new UsersApi(client));
  const profilesApiRef = useRef(new ProfilesApi(client));
  // Destructure the stable `push` callback — the whole `useToast()` context
  // value is a fresh object reference on every toast queue change, so depending
  // on it inside `loadUsers` would re-fire the load effect on every toast.
  const { push: pushToast } = useToast();

  // ── User list state ────────────────────────────────────────────────────
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);

  // ── Add/edit user form state ──────────────────────────────────────────
  const [userFormOpen, setUserFormOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<User | null>(null);
  const [username, setUsername] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isAdmin, setIsAdmin] = useState(false);
  const [userSubmitting, setUserSubmitting] = useState(false);

  // ── Delete confirm state ────────────────────────────────────────────────
  const [deletingUser, setDeletingUser] = useState<User | null>(null);

  // ── Reset-password confirm state ──────────────────────────────────────
  const [resettingPassword, setResettingPassword] = useState<User | null>(null);
  const [resetResult, setResetResult] = useState<{ message: string; new_password: string } | null>(null);

  // ── Profiles modal state ───────────────────────────────────────────────
  const [profilesUser, setProfilesUser] = useState<User | null>(null);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [profilesLoading, setProfilesLoading] = useState(false);
  // Add/edit profile form state
  const [profileFormOpen, setProfileFormOpen] = useState(false);
  const [editingProfile, setEditingProfile] = useState<Profile | null>(null);
  const [profileName, setProfileName] = useState('');
  const [profileRating, setProfileRating] = useState(0);
  const [profileSubmitting, setProfileSubmitting] = useState(false);
  // Delete profile confirm
  const [deletingProfile, setDeletingProfile] = useState<Profile | null>(null);
  // Set PIN state
  const [settingPin, setSettingPin] = useState<Profile | null>(null);
  const [pinValue, setPinValue] = useState('');
  const [pinSubmitting, setPinSubmitting] = useState(false);

  const loadUsers = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const rows = await usersApiRef.current.list();
      setUsers(rows);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to load users.';
      pushToast(message, 'error');
    } finally {
      setLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadUsers();
  }, [loadUsers]);

  // ── User form helpers ───────────────────────────────────────────────────
  const openAddUser = (): void => {
    setEditingUser(null);
    setUsername('');
    setEmail('');
    setPassword('');
    setIsAdmin(false);
    setUserFormOpen(true);
  };

  const openEditUser = (user: User): void => {
    setEditingUser(user);
    setUsername(user.username);
    setEmail(user.email);
    setPassword('');
    setIsAdmin(user.is_admin === 1);
    setUserFormOpen(true);
  };

  const closeUserForm = (): void => {
    setUserFormOpen(false);
    setEditingUser(null);
  };

  const submitUserForm = async (): Promise<void> => {
    if (!username.trim() || !email.trim()) {
      pushToast('Username and email are required.', 'error');
      return;
    }
    if (!editingUser && !password) {
      pushToast('Password is required for new users.', 'error');
      return;
    }
    if (!editingUser && password.length < 8) {
      pushToast('Password must be at least 8 characters.', 'error');
      return;
    }
    setUserSubmitting(true);
    try {
      if (editingUser) {
        const input: UpdateUserInput = { username, email };
        if (password) {
          input.password = password;
        }
        await usersApiRef.current.update(editingUser.id, input);
        // If the admin flag changed, call setAdmin separately
        const targetAdmin = isAdmin ? 1 : 0;
        if (editingUser.is_admin !== targetAdmin) {
          await usersApiRef.current.setAdmin(editingUser.id, isAdmin);
        }
        pushToast('User updated.', 'success');
      } else {
        const input: CreateUserInput = { username, email, password, is_admin: isAdmin };
        await usersApiRef.current.create(input);
        pushToast('User created.', 'success');
      }
      closeUserForm();
      await loadUsers();
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to save user.';
      pushToast(message, 'error');
    } finally {
      setUserSubmitting(false);
    }
  };

  // ── Delete user ─────────────────────────────────────────────────────────
  const confirmDeleteUser = async (): Promise<void> => {
    if (!deletingUser) return;
    try {
      await usersApiRef.current.remove(deletingUser.id);
      pushToast('User deleted.', 'success');
      setDeletingUser(null);
      await loadUsers();
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to delete user.';
      pushToast(message, 'error');
      setDeletingUser(null);
    }
  };

  // ── Set admin ──────────────────────────────────────────────────────────
  const handleSetAdmin = async (user: User, makeAdmin: boolean): Promise<void> => {
    try {
      await usersApiRef.current.setAdmin(user.id, makeAdmin);
      pushToast(makeAdmin ? 'User promoted to admin.' : 'Admin status removed.', 'success');
      await loadUsers();
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to update admin status.';
      pushToast(message, 'error');
    }
  };

  // ── Reset password ────────────────────────────────────────────────────
  const handleResetPassword = async (user: User): Promise<void> => {
    setResettingPassword(user);
    setResetResult(null);
    try {
      const result = await usersApiRef.current.resetPassword(user.id);
      setResetResult(result);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to reset password.';
      pushToast(message, 'error');
      setResettingPassword(null);
    }
  };

  // ── Profiles modal ─────────────────────────────────────────────────────
  const loadProfiles = useCallback(async (userId: number): Promise<void> => {
    setProfilesLoading(true);
    try {
      const rows = await profilesApiRef.current.listForUser(userId);
      setProfiles(rows);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to load profiles.';
      pushToast(message, 'error');
    } finally {
      setProfilesLoading(false);
    }
  }, [pushToast]);

  const openProfilesModal = async (user: User): Promise<void> => {
    setProfilesUser(user);
    await loadProfiles(user.id);
  };

  const closeProfilesModal = (): void => {
    setProfilesUser(null);
    setProfiles([]);
    setProfileFormOpen(false);
    setEditingProfile(null);
    setProfileName('');
    setProfileRating(0);
    setDeletingProfile(null);
    setSettingPin(null);
    setPinValue('');
  };

  // ── Profile form helpers ───────────────────────────────────────────────
  const openAddProfile = (): void => {
    setEditingProfile(null);
    setProfileName('');
    setProfileRating(0);
    setProfileFormOpen(true);
  };

  const openEditProfile = (profile: Profile): void => {
    setEditingProfile(profile);
    setProfileName(profile.name);
    setProfileRating(profile.rating);
    setProfileFormOpen(true);
  };

  const closeProfileForm = (): void => {
    setProfileFormOpen(false);
    setEditingProfile(null);
  };

  const submitProfileForm = async (): Promise<void> => {
    if (!profileName.trim()) {
      pushToast('Profile name is required.', 'error');
      return;
    }
    if (!profilesUser) return;
    setProfileSubmitting(true);
    try {
      if (editingProfile) {
        const input: UpdateProfileInput = { name: profileName, rating: profileRating };
        await profilesApiRef.current.update(editingProfile.id, input);
        pushToast('Profile updated.', 'success');
      } else {
        if (profiles.length >= 5) {
          pushToast('Maximum 5 profiles allowed.', 'error');
          return;
        }
        const input: CreateProfileInput = { name: profileName, rating: profileRating };
        await profilesApiRef.current.createForUser(profilesUser.id, input);
        pushToast('Profile created.', 'success');
      }
      closeProfileForm();
      await loadProfiles(profilesUser.id);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to save profile.';
      pushToast(message, 'error');
    } finally {
      setProfileSubmitting(false);
    }
  };

  // ── Delete profile ────────────────────────────────────────────────────
  const confirmDeleteProfile = async (): Promise<void> => {
    if (!deletingProfile || !profilesUser) return;
    try {
      await profilesApiRef.current.remove(deletingProfile.id);
      pushToast('Profile deleted.', 'success');
      setDeletingProfile(null);
      await loadProfiles(profilesUser.id);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to delete profile.';
      pushToast(message, 'error');
      setDeletingProfile(null);
    }
  };

  // ── PIN management ─────────────────────────────────────────────────────
  const submitSetPin = async (): Promise<void> => {
    if (!settingPin || !profilesUser) return;
    if (!/^\d{4}$/.test(pinValue) && !/^\d{6}$/.test(pinValue)) {
      pushToast('PIN must be 4 or 6 digits.', 'error');
      return;
    }
    setPinSubmitting(true);
    try {
      await profilesApiRef.current.setPin(settingPin.id, pinValue);
      pushToast('PIN set.', 'success');
      setSettingPin(null);
      setPinValue('');
      await loadProfiles(profilesUser.id);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to set PIN.';
      pushToast(message, 'error');
    } finally {
      setPinSubmitting(false);
    }
  };

  const handleClearPin = async (profile: Profile): Promise<void> => {
    if (!profilesUser) return;
    try {
      await profilesApiRef.current.deletePin(profile.id);
      pushToast('PIN cleared.', 'success');
      await loadProfiles(profilesUser.id);
    } catch (err) {
      const message =
        err instanceof ApiError ? err.message : 'Failed to clear PIN.';
      pushToast(message, 'error');
    }
  };

  // ── Table columns ─────────────────────────────────────────────────────
  const columns: Array<Column<User>> = [
    { id: 'username', header: 'Username', key: 'username' },
    { id: 'email', header: 'Email', key: 'email' },
    {
      id: 'is_admin',
      header: 'Role',
      render: (user) => (
        <span className={`admin-badge admin-badge--${user.is_admin ? 'admin' : 'user'}`}>
          {user.is_admin ? 'Admin' : 'User'}
        </span>
      ),
    },
    { id: 'created_at', header: 'Created', render: (u) => u.created_at.slice(0, 10) },
    {
      id: 'actions',
      header: 'Actions',
      render: (user) => (
        <div className="user-actions">
          <button type="button" onClick={() => openEditUser(user)} aria-label={`Edit ${user.username}`}>
            Edit
          </button>
          <button type="button" onClick={() => setDeletingUser(user)} aria-label={`Delete ${user.username}`}>
            Delete
          </button>
          <button
            type="button"
            onClick={() => void handleSetAdmin(user, user.is_admin !== 1)}
            aria-label={`${user.is_admin ? 'Demote' : 'Promote'} ${user.username}`}
          >
            {user.is_admin ? 'Demote' : 'Set Admin'}
          </button>
          <button
            type="button"
            onClick={() => void handleResetPassword(user)}
            aria-label={`Reset password for ${user.username}`}
          >
            Reset Password
          </button>
          <button
            type="button"
            onClick={() => void openProfilesModal(user)}
            aria-label={`Manage profiles for ${user.username}`}
          >
            Profiles
          </button>
        </div>
      ),
    },
  ];

  const profileColumns: Array<Column<Profile>> = [
    { id: 'name', header: 'Name', key: 'name' },
    {
      id: 'rating',
      header: 'Rating',
      render: (p) => (
        <span
          className="rating-badge"
          style={{ color: RATING_COLORS[p.rating] ?? RATING_COLORS[6] }}
        >
          {p.rating}
        </span>
      ),
    },
    { id: 'created_at', header: 'Created', render: (p) => p.created_at.slice(0, 10) },
    {
      id: 'pin',
      header: 'PIN',
      render: (p) => (
        <span className="pin-indicator">{p.pin_hash !== null ? 'Has PIN' : 'No PIN'}</span>
      ),
    },
    {
      id: 'actions',
      header: 'Actions',
      render: (profile) => (
        <div className="profile-actions">
          <button
            type="button"
            onClick={() => openEditProfile(profile)}
            aria-label={`Edit profile ${profile.name}`}
          >
            Edit
          </button>
          <button
            type="button"
            onClick={() => setSettingPin(profile)}
            aria-label={`Set PIN for ${profile.name}`}
          >
            Set PIN
          </button>
          {profile.pin_hash !== null && (
            <button
              type="button"
              onClick={() => void handleClearPin(profile)}
              aria-label={`Clear PIN for ${profile.name}`}
            >
              Clear PIN
            </button>
          )}
          <button
            type="button"
            onClick={() => setDeletingProfile(profile)}
            aria-label={`Delete profile ${profile.name}`}
          >
            Delete
          </button>
        </div>
      ),
    },
  ];

  // ─────────────────────────────────────────────────────────────────────
  return (
    <section className="page page--users" aria-labelledby="users-heading">
      <header className="page__header">
        <h1 id="users-heading">Users</h1>
        <button type="button" onClick={openAddUser}>
          Add user
        </button>
      </header>

      {loading ? (
        <p role="status" aria-live="polite">
          Loading…
        </p>
      ) : (
        <DataTable
          columns={columns}
          rows={users}
          rowKey={(u) => String(u.id)}
          emptyMessage="No users yet."
          caption="Users"
        />
      )}

      {/* ── Add / Edit user modal ── */}
      <Modal
        open={userFormOpen}
        title={editingUser ? `Edit user — ${editingUser.username}` : 'Add user'}
        onClose={closeUserForm}
      >
        <Form onSubmit={submitUserForm} busy={userSubmitting}>
          <Field
            id="user-username"
            label="Username"
            value={username}
            onChange={setUsername}
            required
          />
          <Field
            id="user-email"
            label="Email"
            type="email"
            value={email}
            onChange={setEmail}
            required
          />
          <Field
            id="user-password"
            label={editingUser ? 'Password (leave blank to keep current)' : 'Password'}
            type="password"
            value={password}
            onChange={setPassword}
            placeholder={editingUser ? '(unchanged)' : undefined}
            required={!editingUser}
          />
          <div className="form__field">
            <label className="form__label" htmlFor="user-is-admin">
              <input
                id="user-is-admin"
                type="checkbox"
                checked={isAdmin}
                onChange={(e) => setIsAdmin(e.target.checked)}
                className="form__checkbox"
              />
              {' '}Admin
            </label>
          </div>
          <div className="form__actions">
            <button type="submit">{editingUser ? 'Save' : 'Create'}</button>
            <button type="button" onClick={closeUserForm}>
              Cancel
            </button>
          </div>
        </Form>
      </Modal>

      {/* ── Delete user confirm modal ── */}
      <Modal
        open={deletingUser !== null}
        title="Delete user"
        onClose={() => setDeletingUser(null)}
      >
        <p>
          Delete user <strong>{deletingUser?.username}</strong>? This cannot be undone.
        </p>
        <div className="form__actions">
          <button type="button" onClick={() => void confirmDeleteUser()}>
            Delete
          </button>
          <button type="button" onClick={() => setDeletingUser(null)}>
            Cancel
          </button>
        </div>
      </Modal>

      {/* ── Reset password confirm modal ── */}
      <Modal
        open={resettingPassword !== null}
        title={resettingPassword ? `Reset password — ${resettingPassword.username}` : 'Reset password'}
        onClose={() => { setResettingPassword(null); setResetResult(null); }}
      >
        {resetResult ? (
          <div>
            <p>{resetResult.message}</p>
            <div className="form__field">
              <label className="form__label" htmlFor="new-password-display">
                New password
              </label>
              <div className="password-reset-display">
                <input
                  id="new-password-display"
                  type="text"
                  className="form__input"
                  value={resetResult.new_password}
                  readOnly
                  aria-readonly="true"
                />
                <button
                  type="button"
                  className="copy-btn"
                  onClick={() => {
                    void navigator.clipboard.writeText(resetResult.new_password);
                    pushToast('Password copied to clipboard.', 'success');
                  }}
                >
                  Copy
                </button>
              </div>
            </div>
            <div className="form__actions">
              <button type="button" onClick={() => { setResettingPassword(null); setResetResult(null); }}>
                Close
              </button>
            </div>
          </div>
        ) : (
          <p>Resetting password for <strong>{resettingPassword?.username}</strong>…</p>
        )}
      </Modal>

      {/* ── Profiles modal ── */}
      <Modal
        open={profilesUser !== null}
        title={profilesUser ? `Profiles — ${profilesUser.username}` : 'Profiles'}
        onClose={closeProfilesModal}
      >
        {profilesLoading ? (
          <p role="status" aria-live="polite">Loading…</p>
        ) : (
          <>
            <div className="profiles-toolbar">
              <button
                type="button"
                onClick={openAddProfile}
                disabled={profiles.length >= 5}
                aria-label="Add profile"
              >
                Add profile{profiles.length >= 5 ? ' (max 5)' : ''}
              </button>
            </div>
            <DataTable
              columns={profileColumns}
              rows={profiles}
              rowKey={(p) => String(p.id)}
              emptyMessage="No profiles yet."
              caption="Profiles"
            />
          </>
        )}

        {/* Add/edit profile form */}
        {profileFormOpen && (
          <div className="profile-form-inline">
            <h3>{editingProfile ? 'Edit profile' : 'Add profile'}</h3>
            <Form onSubmit={submitProfileForm} busy={profileSubmitting}>
              <Field
                id="profile-name"
                label="Name"
                value={profileName}
                onChange={setProfileName}
                required
              />
              <div className="form__field">
                <label className="form__label" htmlFor="profile-rating">
                  Rating<span aria-hidden="true"> *</span>
                </label>
                <select
                  id="profile-rating"
                  className="form__input"
                  value={profileRating}
                  onChange={(e) => setProfileRating(Number(e.target.value))}
                >
                  {RATING_OPTIONS.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </select>
              </div>
              <div className="form__actions">
                <button type="submit">{editingProfile ? 'Save' : 'Create'}</button>
                <button type="button" onClick={closeProfileForm}>
                  Cancel
                </button>
              </div>
            </Form>
          </div>
        )}

        {/* Delete profile confirm */}
        {deletingProfile && (
          <div className="profile-form-inline">
            <p>
              Delete profile <strong>{deletingProfile.name}</strong>? This cannot be undone.
            </p>
            <div className="form__actions">
              <button type="button" onClick={() => void confirmDeleteProfile()}>
                Delete
              </button>
              <button type="button" onClick={() => setDeletingProfile(null)}>
                Cancel
              </button>
            </div>
          </div>
        )}

        {/* Set PIN form */}
        {settingPin && (
          <div className="profile-form-inline">
            <h3>Set PIN — {settingPin.name}</h3>
            <Form onSubmit={submitSetPin} busy={pinSubmitting}>
              <Field
                id="profile-pin"
                label="PIN (4 or 6 digits)"
                type="password"
                value={pinValue}
                onChange={setPinValue}
                placeholder="1234 or 123456"
                required
              />
              <div className="form__actions">
                <button type="submit">Set PIN</button>
                <button type="button" onClick={() => { setSettingPin(null); setPinValue(''); }}>
                  Cancel
                </button>
              </div>
            </Form>
          </div>
        )}
      </Modal>
    </section>
  );
}
