import { createPhlixApp, LibraryScanPage } from '@phlix/ui';

const app = createPhlixApp({
    extraRoutes: [
        {
            path: '/library/scan',
            name: 'library-scan',
            component: LibraryScanPage,
        },
    ],
});
app.mount('#phlix-app');
