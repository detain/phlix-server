import {
    createPhlixApp,
    buildAdminRoutes,
    LibraryScanPage,
    BooksPage,
    BookDetailPage,
    BookReaderPage,
    AudiobooksPage,
    AudiobookDetailPage,
    AudiobookPlayerPage,
    PhotoAlbumsPage,
    PhotoAlbumPage,
    PhotoViewPage,
    PhotoSlideshowPage,
    SearchPage,
    MusicArtistsPage,
    MusicArtistPage,
    MusicAlbumPage,
    MusicTracksPage,
    MusicPlayerPage,
} from '@phlix/ui';
import '@phlix/ui/style.css';
import '@phlix/ui/fonts.css';

const app = createPhlixApp({
    // Top-bar nav. The shell replaces its default Browse/Settings links once a
    // `menu` is supplied, so they're restated here alongside the admin entry.
    // "Admin" is `requiresAdmin`, so the shell shows it only for an admin
    // (`useAuthStore().isAdmin`); the admin API is gated server-side regardless.
    menu: [
        // `libraryLinks` expands Browse into one nav link per library (Movies, TV,
        // Anime, …), fetched from /api/v1/libraries — so each library is reachable
        // straight from the nav, matching the per-library Browse sections.
        { id: 'browse', label: 'Browse', to: '/app', libraryLinks: true },
        // `requiresLibraryType` hides each media-type entry unless a library of
        // that server `type` (singular DB ENUM value) exists — fail-closed while
        // the library list is still loading. Filtering lives in @phlix/ui (S25).
        { id: 'music', label: 'Music', to: '/app/music', requiresLibraryType: 'music' },
        { id: 'books', label: 'Books', to: '/app/books', requiresLibraryType: 'book' },
        { id: 'audiobooks', label: 'Audiobooks', to: '/app/audiobooks', requiresLibraryType: 'audiobook' },
        { id: 'photos', label: 'Photos', to: '/app/photo/albums', requiresLibraryType: 'photo' },
        { id: 'search', label: 'Search', to: '/app/search' },
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
        // Media reader/browser pages ported to Vue in @phlix/ui but previously
        // unrouted (unreachable). Names/paths are hard-coded inside the page
        // components, so they must match exactly. Every path carries the /app
        // prefix (the router history base is '/').
        { path: '/app/books', name: 'books', component: BooksPage },
        { path: '/app/books/:id', name: 'book-detail', component: BookDetailPage },
        { path: '/app/books/:id/read', name: 'book-reader', component: BookReaderPage },
        { path: '/app/audiobooks', name: 'audiobooks', component: AudiobooksPage },
        { path: '/app/audiobooks/:id', name: 'audiobook-detail', component: AudiobookDetailPage },
        { path: '/app/audiobooks/:id/play', name: 'audiobook-player', component: AudiobookPlayerPage },
        { path: '/app/photo/albums', name: 'photo-albums', component: PhotoAlbumsPage },
        { path: '/app/photo/album/:id', name: 'photo-album', component: PhotoAlbumPage },
        { path: '/app/photo/photo/:id', name: 'photo-view', component: PhotoViewPage },
        { path: '/app/photo/slideshow', name: 'photo-slideshow', component: PhotoSlideshowPage },
        { path: '/app/search', name: 'search', component: SearchPage },
        { path: '/app/music/artists', name: 'music-artists', component: MusicArtistsPage },
        { path: '/app/music/artist/:name', name: 'music-artist', component: MusicArtistPage, props: true },
        { path: '/app/music/album/:name', name: 'music-album', component: MusicAlbumPage, props: true },
        { path: '/app/music/tracks', name: 'music-tracks', component: MusicTracksPage },
        { path: '/app/music/player', name: 'music-player', component: MusicPlayerPage },
    ],
});
app.mount('#phlix-app');
