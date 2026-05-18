{extends file="admin/layout.tpl"}

{block name="title"}Dashboard - Admin{/block}

{block name="main"}
<section class="dashboard-page" data-api-base="/api/v1/admin/dashboard">
    <header class="page-header">
        <h1>Admin Dashboard</h1>
        <p class="page-subtitle">Real-time overview of your media server activity</p>
        <div class="dashboard-refresh">
            <span class="refresh-indicator" id="refresh-indicator"></span>
            <span class="last-updated" id="last-updated">Loading...</span>
        </div>
    </header>

    <div class="dashboard-grid">
        {* Now Playing Section *}
        <section class="dashboard-section now-playing-section" id="now-playing-section">
            <h2>
                <span class="section-icon">&#9654;</span>
                Now Playing
                <span class="badge" id="now-playing-count">0</span>
            </h2>
            <div class="now-playing-grid" id="now-playing-grid">
                <p class="empty-state">No active playback sessions</p>
            </div>
        </section>

        {* Top Users Section *}
        <section class="dashboard-section top-users-section" id="top-users-section">
            <h2>
                <span class="section-icon">&#9733;</span>
                Top Users
            </h2>
            <div class="dashboard-controls">
                <label for="top-users-days">Days:</label>
                <select id="top-users-days" class="period-select">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="90">90 days</option>
                    <option value="365">1 year</option>
                </select>
                <label for="top-users-limit">Limit:</label>
                <select id="top-users-limit" class="limit-select">
                    <option value="5">Top 5</option>
                    <option value="10" selected>Top 10</option>
                    <option value="25">Top 25</option>
                </select>
            </div>
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th class="col-rank">#</th>
                        <th class="col-user">User</th>
                        <th class="col-watch-time">Watch Time</th>
                        <th class="col-sessions">Sessions</th>
                    </tr>
                </thead>
                <tbody id="top-users-tbody">
                    <tr><td colspan="4" class="loading">Loading...</td></tr>
                </tbody>
            </table>
        </section>

        {* Top Media Section *}
        <section class="dashboard-section top-media-section" id="top-media-section">
            <h2>
                <span class="section-icon">&#9733;</span>
                Top Media
            </h2>
            <div class="dashboard-controls">
                <label for="top-media-days">Days:</label>
                <select id="top-media-days" class="period-select">
                    <option value="7">7 days</option>
                    <option value="30" selected>30 days</option>
                    <option value="90">90 days</option>
                    <option value="365">1 year</option>
                </select>
                <label for="top-media-limit">Limit:</label>
                <select id="top-media-limit" class="limit-select">
                    <option value="5">Top 5</option>
                    <option value="10" selected>Top 10</option>
                    <option value="25">Top 25</option>
                </select>
            </div>
            <table class="dashboard-table">
                <thead>
                    <tr>
                        <th class="col-rank">#</th>
                        <th class="col-media">Title</th>
                        <th class="col-type">Type</th>
                        <th class="col-plays">Plays</th>
                    </tr>
                </thead>
                <tbody id="top-media-tbody">
                    <tr><td colspan="4" class="loading">Loading...</td></tr>
                </tbody>
            </table>
        </section>

        {* Storage Section *}
        <section class="dashboard-section storage-section" id="storage-section">
            <h2>
                <span class="section-icon">&#128190;</span>
                Storage Usage
            </h2>
            <div class="storage-grid" id="storage-grid">
                <p class="empty-state">No storage data available</p>
            </div>
        </section>

        {* Recent Activity Section *}
        <section class="dashboard-section activity-section" id="activity-section">
            <h2>
                <span class="section-icon">&#128240;</span>
                Recent Activity
            </h2>
            <div class="dashboard-controls">
                <label for="activity-limit">Show:</label>
                <select id="activity-limit" class="limit-select">
                    <option value="10">10 events</option>
                    <option value="20" selected>20 events</option>
                    <option value="50">50 events</option>
                </select>
            </div>
            <div class="activity-feed" id="activity-feed">
                <p class="empty-state">No recent activity</p>
            </div>
        </section>
    </div>
</section>
{/block}

{block name="scripts"}
<script src="/assets/js/admin/dashboard.js" defer></script>
<script>
(function() {
    'use strict';

    // Dashboard state
    const state = {
        nowPlaying: [],
        lastUpdated: null
    };

    // API base URL
    const apiBase = document.querySelector('.dashboard-page').dataset.apiBase;

    // DOM elements
    const elements = {
        nowPlayingGrid: document.getElementById('now-playing-grid'),
        nowPlayingCount: document.getElementById('now-playing-count'),
        topUsersTbody: document.getElementById('top-users-tbody'),
        topMediaTbody: document.getElementById('top-media-tbody'),
        storageGrid: document.getElementById('storage-grid'),
        activityFeed: document.getElementById('activity-feed'),
        lastUpdated: document.getElementById('last-updated'),
        refreshIndicator: document.getElementById('refresh-indicator')
    };

    // Format seconds to human-readable duration
    function formatDuration(seconds) {
        if (!seconds || seconds < 0) return '0s';
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        if (hours > 0) {
            return hours + 'h ' + minutes + 'm';
        }
        return minutes + 'm';
    }

    // Format timestamp to relative time
    function formatRelativeTime(timestamp) {
        if (!timestamp) return '';
        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffMins = Math.floor(diffMs / 60000);
        if (diffMins < 1) return 'Just now';
        if (diffMins < 60) return diffMins + 'm ago';
        const diffHours = Math.floor(diffMins / 60);
        if (diffHours < 24) return diffHours + 'h ago';
        const diffDays = Math.floor(diffHours / 24);
        return diffDays + 'd ago';
    }

    // Update the now playing grid
    function updateNowPlaying(data) {
        state.nowPlaying = data;
        elements.nowPlayingCount.textContent = data.length;

        if (data.length === 0) {
            elements.nowPlayingGrid.innerHTML = '<p class="empty-state">No active playback sessions</p>';
            return;
        }

        let html = '';
        for (const session of data) {
            const progressPercent = session.progress_percent || 0;
            const progressWidth = Math.min(100, Math.max(0, progressPercent));
            const avatarUrl = session.avatar_url || '/assets/images/default-avatar.png';
            const posterUrl = session.poster_url || '/assets/images/default-poster.png';
            const deviceIcon = getDeviceIcon(session.device_type);

            html += `
                <div class="now-playing-card" data-stream-id="${escapeHtml(session.stream_id)}">
                    <div class="now-playing-poster">
                        <img src="${escapeHtml(posterUrl)}" alt="${escapeHtml(session.media_title || 'Unknown')}" loading="lazy">
                        <div class="now-playing-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${progressWidth}%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="now-playing-info">
                        <div class="now-playing-user">
                            <img class="user-avatar" src="${escapeHtml(avatarUrl)}" alt="${escapeHtml(session.username || 'Unknown')}" loading="lazy">
                            <span class="username">${escapeHtml(session.username || 'Unknown')}</span>
                        </div>
                        <h3 class="media-title">${escapeHtml(session.media_title || 'Unknown')}</h3>
                        <div class="media-meta">
                            ${deviceIcon}
                            <span class="device-name">${escapeHtml(session.device_name || 'Unknown device')}</span>
                            <span class="media-type">${escapeHtml(session.media_type || 'media')}</span>
                        </div>
                        <div class="playback-progress-text">
                            ${formatDuration(Math.floor(session.position_ticks / 10000000))} /
                            ${formatDuration(Math.floor(session.duration_ticks / 10000000))}
                        </div>
                    </div>
                </div>
            `;
        }
        elements.nowPlayingGrid.innerHTML = html;
    }

    // Get device icon based on device type
    function getDeviceIcon(deviceType) {
        const icons = {
            'mobile': '&#128241;',
            'desktop': '&#128187;',
            'tv': '&#128250;',
            'tablet': '&#128187;'
        };
        return '<span class="device-icon">' + (icons[deviceType] || '&#128187;') + '</span>';
    }

    // Update top users table
    function updateTopUsers(data) {
        if (data.length === 0) {
            elements.topUsersTbody.innerHTML = '<tr><td colspan="4" class="empty-state">No user data available</td></tr>';
            return;
        }

        let html = '';
        for (let i = 0; i < data.length; i++) {
            const user = data[i];
            const avatarUrl = user.avatar_url || '/assets/images/default-avatar.png';
            html += `
                <tr>
                    <td class="col-rank"><span class="rank">${i + 1}</span></td>
                    <td class="col-user">
                        <img class="user-avatar" src="${escapeHtml(avatarUrl)}" alt="" loading="lazy">
                        <span class="username">${escapeHtml(user.username || 'Unknown')}</span>
                    </td>
                    <td class="col-watch-time">${formatDuration(user.total_watch_time)}</td>
                    <td class="col-sessions">${user.play_count}</td>
                </tr>
            `;
        }
        elements.topUsersTbody.innerHTML = html;
    }

    // Update top media table
    function updateTopMedia(data) {
        if (data.length === 0) {
            elements.topMediaTbody.innerHTML = '<tr><td colspan="4" class="empty-state">No media data available</td></tr>';
            return;
        }

        let html = '';
        for (let i = 0; i < data.length; i++) {
            const media = data[i];
            const posterUrl = media.poster_url || '/assets/images/default-poster.png';
            html += `
                <tr>
                    <td class="col-rank"><span class="rank">${i + 1}</span></td>
                    <td class="col-media">
                        <img class="media-poster" src="${escapeHtml(posterUrl)}" alt="" loading="lazy">
                        <span class="media-title">${escapeHtml(media.title || 'Unknown')}</span>
                    </td>
                    <td class="col-type">${escapeHtml(media.type || 'unknown')}</td>
                    <td class="col-plays">${media.play_count}</td>
                </tr>
            `;
        }
        elements.topMediaTbody.innerHTML = html;
    }

    // Update storage grid
    function updateStorage(data) {
        if (data.length === 0) {
            elements.storageGrid.innerHTML = '<p class="empty-state">No storage data available</p>';
            return;
        }

        const totalBytes = data.reduce((sum, item) => sum + (item.total_bytes || 0), 0);
        const totalCacheBytes = data.reduce((sum, item) => sum + (item.transcode_cache_bytes || 0), 0);

        let html = `
            <div class="storage-summary">
                <div class="storage-total">
                    <span class="storage-label">Total Library Size</span>
                    <span class="storage-value">${formatBytes(totalBytes)}</span>
                </div>
                <div class="storage-cache">
                    <span class="storage-label">Transcode Cache</span>
                    <span class="storage-value">${formatBytes(totalCacheBytes)}</span>
                </div>
            </div>
            <div class="storage-breakdown">
        `;

        for (const item of data) {
            const percent = totalBytes > 0 ? Math.round((item.total_bytes / totalBytes) * 100) : 0;
            const typeColors = {
                'movie': '#e50914',
                'series': '#0064e4',
                'music': '#1db954',
                'photo': '#ff9500',
                'audiobook': '#a855f7',
                'book': '#3b82f6'
            };
            const color = typeColors[item.media_type] || '#888888';

            html += `
                <div class="storage-item">
                    <div class="storage-item-header">
                        <span class="storage-type">${escapeHtml(item.media_type || 'unknown')}</span>
                        <span class="storage-size">${escapeHtml(item.formatted_total || '0 B')}</span>
                    </div>
                    <div class="storage-bar">
                        <div class="storage-bar-fill" style="width: ${percent}%; background-color: ${color}"></div>
                    </div>
                    <div class="storage-item-meta">
                        <span>${item.item_count || 0} items</span>
                        <span>Cache: ${escapeHtml(item.formatted_cache || '0 B')}</span>
                    </div>
                </div>
            `;
        }

        html += '</div>';
        elements.storageGrid.innerHTML = html;
    }

    // Format bytes to human-readable string
    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let unitIndex = 0;
        let size = bytes;
        while (size >= 1024 && unitIndex < units.length - 1) {
            size /= 1024;
            unitIndex++;
        }
        return size.toFixed(2) + ' ' + units[unitIndex];
    }

    // Update activity feed
    function updateActivity(data) {
        if (data.length === 0) {
            elements.activityFeed.innerHTML = '<p class="empty-state">No recent activity</p>';
            return;
        }

        let html = '<ul class="activity-list">';
        for (const event of data) {
            const icon = getActivityIcon(event.category, event.event_type);
            const timeAgo = formatRelativeTime(event.occurred_at);
            const details = formatActivityDetails(event);

            html += `
                <li class="activity-item">
                    <span class="activity-icon">${icon}</span>
                    <div class="activity-content">
                        <span class="activity-user">${escapeHtml(event.username || 'System')}</span>
                        <span class="activity-action">${escapeHtml(formatActivityVerb(event.event_type))}</span>
                        ${details ? '<span class="activity-details">' + details + '</span>' : ''}
                    </div>
                    <span class="activity-time">${timeAgo}</span>
                </li>
            `;
        }
        html += '</ul>';
        elements.activityFeed.innerHTML = html;
    }

    // Get icon for activity category/type
    function getActivityIcon(category, eventType) {
        if (category === 'playback') {
            return '&#9654;'; // Play button
        } else if (category === 'library') {
            if (eventType === 'item_added') return '+';
            if (eventType === 'item_removed') return '-';
            return '&#128196;'; // Document
        } else if (category === 'auth') {
            if (eventType === 'login') return '&#128275;'; // Lock
            if (eventType === 'logout') return '&#128276;'; // Unlock
            return '&#128272;'; // Key
        }
        return '&#8226;'; // Bullet
    }

    // Format activity verb for display
    function formatActivityVerb(eventType) {
        const verbs = {
            'playback_completed': 'completed',
            'item_added': 'added',
            'item_removed': 'removed',
            'metadata_updated': 'updated metadata for',
            'login': 'logged in',
            'logout': 'logged out'
        };
        return verbs[eventType] || eventType;
    }

    // Format activity details
    function formatActivityDetails(event) {
        if (event.category === 'playback' && event.details) {
            return event.details.media_title ? '«' + event.details.media_title + '»' : '';
        }
        return '';
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // Fetch dashboard data from API
    async function fetchDashboardData() {
        elements.refreshIndicator.classList.add('refreshing');

        try {
            const [nowPlaying, topUsers, topMedia, storage, activity] = await Promise.all([
                fetch(apiBase + '/now-playing').then(r => r.json()),
                fetch(apiBase + '/top-users?' + new URLSearchParams({
                    limit: document.getElementById('top-users-limit').value,
                    days: document.getElementById('top-users-days').value
                })).then(r => r.json()),
                fetch(apiBase + '/top-media?' + new URLSearchParams({
                    limit: document.getElementById('top-media-limit').value,
                    days: document.getElementById('top-media-days').value
                })).then(r => r.json()),
                fetch(apiBase + '/storage').then(r => r.json()),
                fetch(apiBase + '/activity?' + new URLSearchParams({
                    limit: document.getElementById('activity-limit').value
                })).then(r => r.json())
            ]);

            if (nowPlaying.success) updateNowPlaying(nowPlaying.data);
            if (topUsers.success) updateTopUsers(topUsers.data);
            if (topMedia.success) updateTopMedia(topMedia.data);
            if (storage.success) updateStorage(storage.data);
            if (activity.success) updateActivity(activity.data);

            state.lastUpdated = new Date();
            elements.lastUpdated.textContent = 'Updated ' + formatRelativeTime(state.lastUpdated);
        } catch (error) {
            console.error('Failed to fetch dashboard data:', error);
            elements.lastUpdated.textContent = 'Update failed';
        } finally {
            elements.refreshIndicator.classList.remove('refreshing');
        }
    }

    // Initialize dashboard
    function init() {
        fetchDashboardData();

        // Auto-refresh every 30 seconds
        setInterval(fetchDashboardData, 30000);

        // Event listeners for controls
        document.getElementById('top-users-days').addEventListener('change', fetchDashboardData);
        document.getElementById('top-users-limit').addEventListener('change', fetchDashboardData);
        document.getElementById('top-media-days').addEventListener('change', fetchDashboardData);
        document.getElementById('top-media-limit').addEventListener('change', fetchDashboardData);
        document.getElementById('activity-limit').addEventListener('change', fetchDashboardData);
    }

    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
{/block}
