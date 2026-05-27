import { describe, expect, it } from 'vitest';
import {
  ACCESS_TOKEN_KEY,
  LocalStorageTokenStore,
  REFRESH_TOKEN_KEY,
  USER_KEY,
} from './tokenStore';

describe('LocalStorageTokenStore', () => {
  it('uses the exact legacy localStorage keys', () => {
    expect(ACCESS_TOKEN_KEY).toBe('access_token');
    expect(REFRESH_TOKEN_KEY).toBe('refresh_token');
    expect(USER_KEY).toBe('user');
  });

  it('reads and writes the access + refresh tokens', () => {
    const store = new LocalStorageTokenStore();
    expect(store.getAccessToken()).toBeNull();
    store.setAccessToken('a');
    store.setRefreshToken('r');
    expect(store.getAccessToken()).toBe('a');
    expect(store.getRefreshToken()).toBe('r');
    // Confirm the raw legacy key is what is persisted (cross-compat).
    expect(localStorage.getItem('access_token')).toBe('a');
    expect(localStorage.getItem('refresh_token')).toBe('r');
  });

  it('round-trips the user object as JSON', () => {
    const store = new LocalStorageTokenStore();
    store.setUser({ id: 'u1', is_admin: true });
    expect(store.getUser()).toEqual({ id: 'u1', is_admin: true });
    expect(localStorage.getItem('user')).toBe(JSON.stringify({ id: 'u1', is_admin: true }));
  });

  it('returns null for a corrupt user value rather than throwing', () => {
    localStorage.setItem('user', '{not-json');
    const store = new LocalStorageTokenStore();
    expect(store.getUser()).toBeNull();
  });

  it('clear() removes all three keys', () => {
    const store = new LocalStorageTokenStore();
    store.setAccessToken('a');
    store.setRefreshToken('r');
    store.setUser({ id: 1 });
    store.clear();
    expect(localStorage.getItem('access_token')).toBeNull();
    expect(localStorage.getItem('refresh_token')).toBeNull();
    expect(localStorage.getItem('user')).toBeNull();
  });
});
