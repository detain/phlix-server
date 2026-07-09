{extends file="admin/layout.tpl"}

{block name="title"}Music Library - Admin{/block}

{block name="main"}
<section class="admin-music-page" data-api-base="/api/v1/music">
    <header class="page-header">
        <h1>Music Library</h1>
        <p class="page-subtitle">
            Manage your music library. Scan directories to index artists, albums, and tracks.
        </p>
    </header>

    {* Library Statistics *}
    <section class="music-stats" id="music-stats">
        <div class="stat-card">
            <span class="stat-value" id="artist-count">—</span>
            <span class="stat-label">Artists</span>
        </div>
        <div class="stat-card">
            <span class="stat-value" id="album-count">—</span>
            <span class="stat-label">Albums</span>
        </div>
        <div class="stat-card">
            <span class="stat-value" id="track-count">—</span>
            <span class="stat-label">Tracks</span>
        </div>
    </section>

    {* Scan Form *}
    <section class="scan-section">
        <h2>Scan Music Directory</h2>
        <form id="scan-form" class="scan-form">
            <div class="form-group">
                <label for="scan-path">Directory Path</label>
                <input type="text"
                       id="scan-path"
                       name="path"
                       required
                       placeholder="/music"
                       class="form-input">
                <p class="form-hint">Enter the absolute path to your music directory</p>
            </div>
            <button type="submit" class="btn btn-primary" id="scan-btn">
                <span class="btn-text">Scan Directory</span>
                <span class="btn-loading" style="display: none;">Scanning...</span>
            </button>
        </form>
        <div id="scan-result" class="scan-result" style="display: none;"></div>
    </section>

    {* Artists Section *}
    <section class="artists-section">
        <h2>Artists</h2>
        <div id="artists-loading" class="loading-indicator">Loading artists...</div>
        <div id="artists-list" class="artists-grid"></div>
    </section>

    {* Recently Added Albums *}
    <section class="albums-section">
        <h2>Recent Albums</h2>
        <div id="albums-loading" class="loading-indicator">Loading albums...</div>
        <div id="albums-list" class="albums-grid"></div>
    </section>

    {* Recent Tracks *}
    <section class="tracks-section">
        <h2>Recent Tracks</h2>
        <div id="tracks-loading" class="loading-indicator">Loading tracks...</div>
        <table id="tracks-table" class="tracks-table" style="display: none;">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Album</th>
                    <th>Artist</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tracks-tbody"></tbody>
        </table>
    </section>
</section>
{/block}

{block name="scripts"}
<script>
(function() {
    'use strict';

    const apiBase = document.querySelector('.admin-music-page').dataset.apiBase;

    // DOM elements
    const elements = {
        artistCount: document.getElementById('artist-count'),
        albumCount: document.getElementById('album-count'),
        trackCount: document.getElementById('track-count'),
        scanForm: document.getElementById('scan-form'),
        scanPath: document.getElementById('scan-path'),
        scanBtn: document.getElementById('scan-btn'),
        scanResult: document.getElementById('scan-result'),
        artistsLoading: document.getElementById('artists-loading'),
        artistsList: document.getElementById('artists-list'),
        albumsLoading: document.getElementById('albums-loading'),
        albumsList: document.getElementById('albums-list'),
        tracksLoading: document.getElementById('tracks-loading'),
        tracksTable: document.getElementById('tracks-table'),
        tracksTbody: document.getElementById('tracks-tbody'),
    };

    // Format duration in seconds to mm:ss or hh:mm:ss
    function formatDuration(seconds) {
        if (!seconds || seconds < 0) return '0:00';
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
        }
        return minutes + ':' + String(secs).padStart(2, '0');
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // Load library statistics
    async function loadStats() {
        try {
            const response = await fetch(apiBase + '/artists');
            if (!response.ok) {
                throw new Error('Failed to load artists');
            }
            const data = await response.json();
            const artists = data.artists || [];
            const totalAlbums = artists.reduce((sum, a) => sum + (a.album_count || 0), 0);
            const totalTracks = artists.reduce((sum, a) => sum + (a.track_count || 0), 0);

            elements.artistCount.textContent = artists.length;
            elements.albumCount.textContent = totalAlbums;
            elements.trackCount.textContent = totalTracks;
        } catch (error) {
            console.error('Failed to load stats:', error);
            elements.artistCount.textContent = '—';
            elements.albumCount.textContent = '—';
            elements.trackCount.textContent = '—';
        }
    }

    // Load artists list
    async function loadArtists() {
        elements.artistsLoading.style.display = 'block';
        elements.artistsList.innerHTML = '';

        try {
            const response = await fetch(apiBase + '/artists');
            if (!response.ok) {
                throw new Error('Failed to load artists');
            }
            const data = await response.json();
            const artists = data.artists || [];

            elements.artistsLoading.style.display = 'none';

            if (artists.length === 0) {
                elements.artistsList.innerHTML = '<p class="empty-state">No artists found. Scan a music directory to add artists.</p>';
                return;
            }

            let html = '<div class="artists-grid-inner">';
            for (const artist of artists.slice(0, 12)) {
                html += `
                    <div class="artist-card">
                        <div class="artist-info">
                            <h3 class="artist-name">${escapeHtml(artist.artist?.name || 'Unknown')}</h3>
                            <p class="artist-meta">${artist.album_count || 0} albums, ${artist.track_count || 0} tracks</p>
                        </div>
                    </div>
                `;
            }
            html += '</div>';
            elements.artistsList.innerHTML = html;
        } catch (error) {
            console.error('Failed to load artists:', error);
            elements.artistsLoading.style.display = 'none';
            elements.artistsList.innerHTML = '<p class="empty-state">Failed to load artists.</p>';
        }
    }

    // Load albums
    async function loadAlbums() {
        elements.albumsLoading.style.display = 'block';
        elements.albumsList.innerHTML = '';

        try {
            // We need to fetch artists to get albums, so we'll just show placeholder
            elements.albumsLoading.style.display = 'none';
            elements.albumsList.innerHTML = '<p class="empty-state">Browse artists to see their albums.</p>';
        } catch (error) {
            console.error('Failed to load albums:', error);
            elements.albumsLoading.style.display = 'none';
            elements.albumsList.innerHTML = '<p class="empty-state">Failed to load albums.</p>';
        }
    }

    // Load recent tracks
    async function loadTracks() {
        elements.tracksLoading.style.display = 'block';
        elements.tracksTable.style.display = 'none';

        try {
            // Show placeholder - in a real implementation we'd have a /tracks endpoint
            elements.tracksLoading.style.display = 'none';
            elements.tracksTable.style.display = 'none';
        } catch (error) {
            console.error('Failed to load tracks:', error);
            elements.tracksLoading.style.display = 'none';
        }
    }

    // Handle scan form submission
    elements.scanForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        const path = elements.scanPath.value.trim();
        if (!path) {
            return;
        }

        // Show loading state
        elements.scanBtn.disabled = true;
        elements.scanBtn.querySelector('.btn-text').style.display = 'none';
        elements.scanBtn.querySelector('.btn-loading').style.display = 'inline';
        elements.scanResult.style.display = 'none';

        try {
            const response = await fetch(apiBase + '/scan', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ path: path }),
            });

            const data = await response.json();

            elements.scanResult.style.display = 'block';
            if (response.ok) {
                elements.scanResult.className = 'scan-result success';
                elements.scanResult.innerHTML = `
                    <strong>Scan complete!</strong><br>
                    Albums scanned: ${data.scanned || 0}<br>
                    Added: ${data.added || 0}<br>
                    Updated: ${data.updated || 0}<br>
                    Duration: ${data.duration_ms || 0}ms
                `;
                // Refresh stats after scan
                loadStats();
                loadArtists();
            } else {
                elements.scanResult.className = 'scan-result error';
                elements.scanResult.innerHTML = `<strong>Scan failed:</strong> ${escapeHtml(data.error || 'Unknown error')}`;
            }
        } catch (error) {
            console.error('Scan failed:', error);
            elements.scanResult.style.display = 'block';
            elements.scanResult.className = 'scan-result error';
            elements.scanResult.innerHTML = `<strong>Scan failed:</strong> ${escapeHtml(error.message)}`;
        } finally {
            elements.scanBtn.disabled = false;
            elements.scanBtn.querySelector('.btn-text').style.display = 'inline';
            elements.scanBtn.querySelector('.btn-loading').style.display = 'none';
        }
    });

    // Initialize page
    function init() {
        loadStats();
        loadArtists();
        loadAlbums();
        loadTracks();
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<style>
.admin-music-page .page-header {
    margin-bottom: 2rem;
}

.music-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
}

.stat-value {
    display: block;
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-color, #3b82f6);
}

.stat-label {
    display: block;
    font-size: 0.875rem;
    color: var(--text-muted, #6b7280);
    margin-top: 0.25rem;
}

.scan-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.scan-section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1.25rem;
}

.scan-form {
    display: flex;
    gap: 1rem;
    align-items: flex-end;
    flex-wrap: wrap;
}

.form-group {
    flex: 1;
    min-width: 250px;
}

.form-group label {
    display: block;
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 4px;
    font-size: 1rem;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--text-muted, #6b7280);
    margin-top: 0.25rem;
}

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 4px;
    font-size: 1rem;
    cursor: pointer;
    transition: background-color 0.2s;
}

.btn-primary {
    background: var(--primary-color, #3b82f6);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: var(--primary-hover, #2563eb);
}

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.scan-result {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 4px;
}

.scan-result.success {
    background: #d1fae5;
    color: #065f46;
}

.scan-result.error {
    background: #fee2e2;
    color: #991b1b;
}

.artists-section,
.albums-section,
.tracks-section {
    margin-bottom: 2rem;
}

.artists-section h2,
.albums-section h2,
.tracks-section h2 {
    margin-top: 0;
    margin-bottom: 1rem;
    font-size: 1.25rem;
}

.artists-grid-inner {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.artist-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
    padding: 1rem;
}

.artist-name {
    margin: 0;
    font-size: 1rem;
}

.artist-meta {
    margin: 0.25rem 0 0;
    font-size: 0.875rem;
    color: var(--text-muted, #6b7280);
}

.loading-indicator {
    padding: 2rem;
    text-align: center;
    color: var(--text-muted, #6b7280);
}

.empty-state {
    padding: 2rem;
    text-align: center;
    color: var(--text-muted, #6b7280);
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
}

.tracks-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 8px;
}

.tracks-table th,
.tracks-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
}

.tracks-table th {
    font-weight: 600;
    background: var(--table-header-bg, #f9fafb);
}
</style>
{/block}
