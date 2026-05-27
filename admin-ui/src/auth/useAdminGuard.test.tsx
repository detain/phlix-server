import { describe, expect, it, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useAdminGuard, isAdminUser } from './useAdminGuard';
import { ApiClient } from '../api/client';
import { MemoryTokenStore, makeFetch } from '../test/memoryTokenStore';

function clientWith(
  responses: Array<{ status: number; body: unknown }>,
  tokenInit: { access?: string; refresh?: string } = {},
): ApiClient {
  return new ApiClient({
    baseUrl: '',
    tokenStore: new MemoryTokenStore(tokenInit),
    fetchImpl: makeFetch(responses).fetch,
  });
}

describe('isAdminUser', () => {
  it('is true only for an authenticated user flagged is_admin', () => {
    expect(isAdminUser({ id: '1', is_admin: true })).toBe(true);
    // The server serializes is_admin as the DB TINYINT (1/0), not a JSON
    // boolean — the guard must treat 1/"1" as admin and 0/"0" as not.
    expect(isAdminUser({ id: '1', is_admin: 1 as unknown as boolean })).toBe(true);
    expect(isAdminUser({ id: '1', is_admin: '1' as unknown as boolean })).toBe(true);
    expect(isAdminUser({ id: '1', is_admin: 0 as unknown as boolean })).toBe(false);
    expect(isAdminUser({ id: '1', is_admin: false })).toBe(false);
    expect(isAdminUser({ id: '1' })).toBe(false);
    expect(isAdminUser(null)).toBe(false);
    expect(isAdminUser(undefined)).toBe(false);
  });
});

describe('useAdminGuard', () => {
  it('authorizes an admin user from auth/me', async () => {
    // Real wire shape from GET /api/v1/auth/me: { user: {...} } with is_admin
    // as the DB TINYINT (1), proving the client→guard path unwraps + normalizes.
    const client = clientWith([{ status: 200, body: { user: { id: 'u1', is_admin: 1 } } }], { access: 't' });
    const redirect = vi.fn();

    const { result } = renderHook(() => useAdminGuard(client, redirect));

    await waitFor(() => expect(result.current.status).toBe('authorized'));
    expect(result.current.user).toMatchObject({ id: 'u1', is_admin: true });
    expect(redirect).not.toHaveBeenCalled();
  });

  it('redirects to /login immediately when no token is stored (no network call)', async () => {
    const client = clientWith([{ status: 200, body: {} }]); // no token
    const redirect = vi.fn();

    const { result } = renderHook(() => useAdminGuard(client, redirect));

    await waitFor(() => expect(result.current.status).toBe('unauthorized'));
    expect(redirect).toHaveBeenCalledWith('/login');
  });

  it('redirects to /login when the user is authenticated but not an admin', async () => {
    const client = clientWith([{ status: 200, body: { user: { id: 'u2', is_admin: 0 } } }], { access: 't' });
    const redirect = vi.fn();

    const { result } = renderHook(() => useAdminGuard(client, redirect));

    await waitFor(() => expect(result.current.status).toBe('unauthorized'));
    expect(result.current.user).toBeNull();
    expect(redirect).toHaveBeenCalledWith('/login');
  });

  it('redirects to /login when auth/me returns 401/403 (no refresh token to recover)', async () => {
    const client = clientWith([{ status: 403, body: { error: 'Forbidden' } }], { access: 't' });
    const redirect = vi.fn();

    const { result } = renderHook(() => useAdminGuard(client, redirect));

    await waitFor(() => expect(result.current.status).toBe('unauthorized'));
    expect(redirect).toHaveBeenCalledWith('/login');
  });

  it('starts in the loading state', () => {
    const client = clientWith([{ status: 200, body: { user: { id: 'u', is_admin: 1 } } }], { access: 't' });
    const { result } = renderHook(() => useAdminGuard(client, vi.fn()));
    expect(result.current.status).toBe('loading');
  });

  it('does not set state or redirect after unmount when auth/me resolves (success race)', async () => {
    const client = clientWith([{ status: 200, body: { user: { id: 'u', is_admin: 1 } } }], { access: 't' });
    const redirect = vi.fn();
    const { result, unmount } = renderHook(() => useAdminGuard(client, redirect));
    // Unmount before the in-flight getCurrentUser promise resolves: the
    // effect cleanup flips `cancelled`, so the resolve handler must no-op.
    unmount();
    await new Promise((r) => setTimeout(r, 0));
    expect(result.current.status).toBe('loading');
    expect(redirect).not.toHaveBeenCalled();
  });

  it('does not set state or redirect after unmount when auth/me rejects (error race)', async () => {
    const client = clientWith([{ status: 403, body: { error: 'Forbidden' } }], { access: 't' });
    const redirect = vi.fn();
    const { result, unmount } = renderHook(() => useAdminGuard(client, redirect));
    // Same race on the rejection path: the catch handler must no-op once
    // the hook has been torn down.
    unmount();
    await new Promise((r) => setTimeout(r, 0));
    expect(result.current.status).toBe('loading');
    expect(redirect).not.toHaveBeenCalled();
  });
});
