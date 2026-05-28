/**
 * Admin navigation definition.
 *
 * The single source of truth for the admin nav. Phase-1 steps add their
 * feature routes here (libraries, users, settings, …). For the 0.4
 * scaffold only the Dashboard route exists — the rest are intentionally
 * absent (no feature pages yet, per the step spec).
 */
export interface NavItem {
  /** Router path (relative to the `/admin` base). */
  path: string;
  /** Sidebar label. */
  label: string;
}

export const NAV_ITEMS: readonly NavItem[] = [
  { path: '/', label: 'Dashboard' },
  { path: '/libraries', label: 'Libraries' },
  { path: '/users', label: 'Users' },
] as const;
