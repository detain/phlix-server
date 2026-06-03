import { createPhlixApp, buildAdminRoutes, LibraryScanPage } from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';

const app = createPhlixApp({
    // Top-bar nav. The shell replaces its default Browse/Settings links once a
    // `menu` is supplied, so they're restated here alongside the admin entry.
    // "Admin" is `requiresAdmin`, so the shell shows it only for an admin
    // (`useAuthStore().isAdmin`); the admin API is gated server-side regardless.
    menu: [
        { id: 'browse', label: 'Browse', to: '/app' },
        { id: 'settings', label: 'Settings', to: '/app/settings' },
        { id: 'admin', label: 'Admin', to: '/app/admin/dashboard', requiresAdmin: true },
    ],
    extraRoutes: [
        // The redesigned Vue admin section (AdminLayout sidebar + the 16 ported
        // pages) at /app/admin/*. Reachable via the gated "Admin" nav entry.
        ...buildAdminRoutes(),
        {
            // Must carry the /app prefix like every other route — the router's
            // history base is '/', so the prefix lives in the path itself.
            path: '/app/library/scan',
            name: 'library-scan',
            component: LibraryScanPage,
        },
    ],
});
app.mount('#phlix-app');
