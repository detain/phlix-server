import { createPhlixApp, LibraryScanPage } from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';

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
