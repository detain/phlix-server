/**
 * SyncPlayPage — admin "SyncPlay" page for managing group watching sessions.
 *
 * Features:
 *  - List all available SyncPlay groups
 *  - Create a new group with optional password
 *  - Join an existing group
 *  - Leave a group
 *
 * Security:
 *  - No `dangerouslySetInnerHTML` — all server/API strings as text.
 *  - Admin gate handled server-side + `useAdminGuard` in App shell.
 *
 * @since 3.5
 */
import { useCallback, useEffect, useRef, useState } from 'react';
import type { ApiClient } from '../api/client';
import { ApiError } from '../api/client';
import {
  SyncPlayApi,
  type SyncPlayGroup,
} from '../api/syncPlay';
import { Modal } from '../components/Modal';
import { Form, Field } from '../components/Form';
import { useToast } from '../components/Toast';

export interface SyncPlayPageProps {
  client: ApiClient;
}

export function SyncPlayPage({ client }: SyncPlayPageProps): JSX.Element {
  const syncPlayApiRef = useRef(new SyncPlayApi(client));

  // Destructure the stable `push` callback — the whole `useToast()`
  // context value is a fresh object reference on every toast queue change.
  const { push: pushToast } = useToast();

  // ─── Groups list state ───────────────────────────────────────────────────────
  const [groups, setGroups] = useState<SyncPlayGroup[]>([]);
  const [loading, setLoading] = useState(true);

  const loadGroups = useCallback(async (): Promise<void> => {
    setLoading(true);
    try {
      const list = await syncPlayApiRef.current.listGroups();
      setGroups(list);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to load groups.';
      pushToast(msg, 'error');
    } finally {
      setLoading(false);
    }
  }, [pushToast]);

  useEffect(() => {
    void loadGroups();
  }, [loadGroups]);

  // ─── Create group modal state ─────────────────────────────────────────────
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [createName, setCreateName] = useState('');
  const [createPassword, setCreatePassword] = useState('');
  const [submittingCreate, setSubmittingCreate] = useState(false);

  const openCreateModal = (): void => {
    setCreateName('');
    setCreatePassword('');
    setCreateModalOpen(true);
  };

  const closeCreateModal = (): void => {
    setCreateModalOpen(false);
  };

  const submitCreateForm = async (): Promise<void> => {
    if (!createName.trim()) {
      pushToast('Group name is required.', 'error');
      return;
    }
    setSubmittingCreate(true);
    try {
      const input: { name: string; password?: string } = { name: createName.trim() };
      if (createPassword.trim()) {
        input.password = createPassword.trim();
      }
      await syncPlayApiRef.current.createGroup(input);
      pushToast('Group created.', 'success');
      closeCreateModal();
      await loadGroups();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to create group.';
      pushToast(msg, 'error');
    } finally {
      setSubmittingCreate(false);
    }
  };

  // ─── Join group modal state ────────────────────────────────────────────────
  const [joinModalOpen, setJoinModalOpen] = useState(false);
  const [joinGroupId, setJoinGroupId] = useState('');
  const [joinPassword, setJoinPassword] = useState('');
  const [submittingJoin, setSubmittingJoin] = useState(false);

  const openJoinModal = (groupId?: string): void => {
    setJoinGroupId(groupId ?? '');
    setJoinPassword('');
    setJoinModalOpen(true);
  };

  const closeJoinModal = (): void => {
    setJoinModalOpen(false);
  };

  const submitJoinForm = async (): Promise<void> => {
    if (!joinGroupId.trim()) {
      pushToast('Group ID is required.', 'error');
      return;
    }
    setSubmittingJoin(true);
    try {
      const input: { password?: string } = {};
      if (joinPassword.trim()) {
        input.password = joinPassword.trim();
      }
      await syncPlayApiRef.current.joinGroup(joinGroupId.trim(), input);
      pushToast('Joined group.', 'success');
      closeJoinModal();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Failed to join group.';
      pushToast(msg, 'error');
    } finally {
      setSubmittingJoin(false);
    }
  };

  // ─── Render helpers ──────────────────────────────────────────────────────
  const renderGroupCard = (group: SyncPlayGroup) => (
    <div key={group.id} className="syncplay-card">
      <div className="syncplay-card__info">
        <span className="syncplay-card__name">{group.name}</span>
        {group.has_password && (
          <span className="badge badge--muted">Password</span>
        )}
        <span className="badge badge--muted">
          {group.member_count} member{group.member_count !== 1 ? 's' : ''}
        </span>
        {group.is_playing && (
          <span className="badge badge--accent">Playing</span>
        )}
        {group.current_media && (
          <span className="badge badge--muted">Media: {group.current_media}</span>
        )}
      </div>
      <div className="syncplay-card__actions">
        <button
          type="button"
          onClick={() => openJoinModal(group.id)}
          aria-label={`Join ${group.name}`}
        >
          Join
        </button>
      </div>
    </div>
  );

  return (
    <section className="page page--syncplay" aria-labelledby="syncplay-heading">
      <header className="page__header">
        <h1 id="syncplay-heading">SyncPlay</h1>
        <p>Watch together with synchronized playback for multiple viewers.</p>
      </header>

      {loading ? (
        <p role="status" aria-live="polite">Loading groups…</p>
      ) : (
        <>
          <div className="page__actions">
            <button type="button" className="btn--primary" onClick={openCreateModal}>
              + Create Group
            </button>
          </div>

          {groups.length === 0 ? (
            <p className="syncplay-empty">
              No groups yet. Create one to start watching together!
            </p>
          ) : (
            <div className="syncplay-groups">
              {groups.map(renderGroupCard)}
            </div>
          )}
        </>
      )}

      {/* Create group modal */}
      <Modal
        open={createModalOpen}
        title="Create SyncPlay Group"
        onClose={closeCreateModal}
      >
        <Form onSubmit={submitCreateForm} busy={submittingCreate}>
          <Field
            id="create-name"
            label="Group Name"
            value={createName}
            onChange={setCreateName}
            placeholder="Movie Night"
            required
          />
          <Field
            id="create-password"
            label="Password (optional)"
            value={createPassword}
            onChange={setCreatePassword}
            type="password"
            placeholder="Leave empty for open group"
          />
          <div className="form__actions">
            <button type="submit" disabled={submittingCreate}>
              {submittingCreate ? 'Creating…' : 'Create Group'}
            </button>
            <button type="button" onClick={closeCreateModal}>
              Cancel
            </button>
          </div>
        </Form>
      </Modal>

      {/* Join group modal */}
      <Modal
        open={joinModalOpen}
        title="Join SyncPlay Group"
        onClose={closeJoinModal}
      >
        <Form onSubmit={submitJoinForm} busy={submittingJoin}>
          <Field
            id="join-group-id"
            label="Group ID"
            value={joinGroupId}
            onChange={setJoinGroupId}
            placeholder="sp_abc123def456"
            required
          />
          <Field
            id="join-password"
            label="Password (if required)"
            value={joinPassword}
            onChange={setJoinPassword}
            type="password"
            placeholder="Leave empty if no password"
          />
          <div className="form__actions">
            <button type="submit" disabled={submittingJoin}>
              {submittingJoin ? 'Joining…' : 'Join Group'}
            </button>
            <button type="button" onClick={closeJoinModal}>
              Cancel
            </button>
          </div>
        </Form>
      </Modal>
    </section>
  );
}
