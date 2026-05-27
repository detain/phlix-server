/**
 * AdminNav — the sidebar navigation for the admin shell.
 *
 * Renders {@link NAV_ITEMS} as router links and marks the active one. A
 * logout button clears tokens and returns the user to the SSR login page.
 */
import { NavLink } from 'react-router-dom';
import { NAV_ITEMS } from './navItems';
import type { ApiClient, AuthUser } from '../api/client';

export interface AdminNavProps {
  client: ApiClient;
  user: AuthUser | null;
}

export function AdminNav({ client, user }: AdminNavProps): JSX.Element {
  const displayName =
    user?.name ?? user?.username ?? user?.email ?? 'Administrator';

  return (
    <nav className="admin-nav" aria-label="Admin navigation">
      <div className="admin-nav__brand">Phlix Admin</div>
      <ul className="admin-nav__list">
        {NAV_ITEMS.map((item) => (
          <li key={item.path} className="admin-nav__item">
            <NavLink
              to={item.path}
              end={item.path === '/'}
              className={({ isActive }) =>
                isActive ? 'admin-nav__link is-active' : 'admin-nav__link'
              }
            >
              {item.label}
            </NavLink>
          </li>
        ))}
      </ul>
      <div className="admin-nav__footer">
        <span className="admin-nav__user" data-testid="nav-user">
          {displayName}
        </span>
        <button
          type="button"
          className="admin-nav__logout"
          onClick={() => client.logout()}
        >
          Log out
        </button>
      </div>
    </nav>
  );
}
