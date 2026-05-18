{extends file="layouts/main.tpl"}

{block name="title"}Requests - Phlex{/block}

{block name="main"}
<div class="requests-page">
    <section class="page-header">
        <h1>Media Requests</h1>
        <p>Search for movies and TV series to add to your library</p>
    </section>

    <section class="search-section">
        <div class="search-tabs">
            <button class="tab-btn active" data-type="movie">Movies</button>
            <button class="tab-btn" data-type="series">TV Series</button>
        </div>
        <div class="search-box">
            <input type="text" id="tmdb-search" placeholder="Search TMDB for movies or TV series..." autocomplete="off">
            <button type="button" id="search-btn" class="btn btn-primary">Search</button>
        </div>
    </section>

    <section class="search-results" id="search-results" style="display: none;">
        <h2 class="section-title">Search Results</h2>
        <div class="media-grid" id="results-grid">
            <!-- Results populated via JS -->
        </div>
    </section>

    <section class="my-requests">
        <h2 class="section-title">My Requests</h2>
        {if $requests}
        <div class="requests-list">
            {foreach $requests as $req}
            <div class="request-card" data-id="{$req.id}">
                <div class="request-poster">
                    {if $req.poster_url}
                        <img src="{$req.poster_url}" alt="{$req.title}">
                    {else}
                        <div class="poster-placeholder">
                            <span class="icon">🎬</span>
                        </div>
                    {/if}
                </div>
                <div class="request-info">
                    <h3 class="request-title">{$req.title}</h3>
                    <span class="request-type">{$req.type|upper}</span>
                    {if $req.season}
                        <span class="request-season">Season {$req.season}{if $req.episode}, Episode {$req.episode}{/if}</span>
                    {/if}
                    <span class="request-status status-{$req.status}">{$req.status}</span>
                </div>
                <div class="request-actions">
                    <a href="/requests/{$req.id}" class="btn btn-secondary">View</a>
                </div>
            </div>
            {/foreach}
        </div>
        {else}
        <p class="empty-message">You haven't made any requests yet. Search above to request media.</p>
        {/if}
    </section>

    <section class="pending-requests" id="pending-requests-section">
        <h2 class="section-title">Pending Requests</h2>
        <div class="requests-list" id="pending-requests-list">
            <!-- Populated via API -->
        </div>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tmdb-search');
    const searchBtn = document.getElementById('search-btn');
    const resultsSection = document.getElementById('search-results');
    const resultsGrid = document.getElementById('results-grid');
    const tabBtns = document.querySelectorAll('.tab-btn');

    let currentType = 'movie';

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            tabBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentType = this.dataset.type;
            searchInput.placeholder = 'Search TMDB for ' + (currentType === 'movie' ? 'movies' : 'TV series') + '...';
        });
    });

    searchBtn.addEventListener('click', performSearch);
    searchInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') performSearch();
    });

    async function performSearch() {
        const query = searchInput.value.trim();
        if (!query) return;

        const endpoint = currentType === 'movie'
            ? '/api/v1/tmdb/search/movie'
            : '/api/v1/tmdb/search/tv';

        try {
            const response = await fetch(endpoint + '?query=' + encodeURIComponent(query));
            const data = await response.json();

            resultsGrid.innerHTML = '';
            if (data.results && data.results.length > 0) {
                data.results.forEach(item => {
                    const card = createResultCard(item, currentType);
                    resultsGrid.appendChild(card);
                });
                resultsSection.style.display = 'block';
            } else {
                resultsGrid.innerHTML = '<p class="empty-message">No results found.</p>';
                resultsSection.style.display = 'block';
            }
        } catch (err) {
            console.error('Search failed:', err);
            resultsGrid.innerHTML = '<p class="error-message">Search failed. Please try again.</p>';
            resultsSection.style.display = 'block';
        }
    }

    function createResultCard(item, type) {
        const card = document.createElement('div');
        card.className = 'media-card';
        card.innerHTML = `
            <div class="card-poster">
                ${item.poster_path
                    ? '<img src="https://image.tmdb.org/t/p/w342' + item.poster_path + '" alt="' + item.title + '">'
                    : '<div class="poster-placeholder"><span class="icon">🎬</span></div>'
                }
            </div>
            <div class="card-info">
                <h4>${item.title || item.name}</h4>
                <span class="card-year">${(item.release_date || item.first_air_date || '').substring(0, 4)}</span>
            </div>
            <button class="btn btn-primary btn-request" data-tmdb-id="${item.id}" data-title="${item.title || item.name}" data-poster="${item.poster_path ? 'https://image.tmdb.org/t/p/w342' + item.poster_path : ''}" data-type="${type}">
                Request
            </button>
        `;

        card.querySelector('.btn-request').addEventListener('click', function() {
            submitRequest(
                this.dataset.tmdbId,
                this.dataset.title,
                this.dataset.poster,
                this.dataset.type
            );
        });

        return card;
    }

    async function submitRequest(tmdbId, title, posterUrl, type) {
        try {
            const response = await fetch('/api/v1/requests', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: type,
                    tmdb_id: parseInt(tmdbId),
                    title: title,
                    poster_url: posterUrl
                })
            });

            if (response.ok) {
                alert('Request submitted successfully!');
                window.location.reload();
            } else {
                const error = await response.json();
                alert('Failed to submit request: ' + (error.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Request submission failed:', err);
            alert('Failed to submit request. Please try again.');
        }
    }

    // Load pending requests for admin view
    async function loadPendingRequests() {
        try {
            const response = await fetch('/api/v1/requests/pending');
            const data = await response.json();

            const pendingList = document.getElementById('pending-requests-list');
            if (data.requests && data.requests.length > 0) {
                pendingList.innerHTML = data.requests.map(req => `
                    <div class="request-card admin" data-id="${req.id}">
                        <div class="request-poster">
                            ${req.poster_url
                                ? '<img src="' + req.poster_url + '" alt="' + req.title + '">'
                                : '<div class="poster-placeholder"><span class="icon">🎬</span></div>'
                            }
                        </div>
                        <div class="request-info">
                            <h3 class="request-title">${req.title}</h3>
                            <span class="request-type">${req.type.toUpperCase()}</span>
                            <span class="request-status status-${req.status}">${req.status}</span>
                            <span class="request-user">User: ${req.user_id}</span>
                        </div>
                        <div class="request-actions">
                            <button class="btn btn-approve" data-id="${req.id}">Approve</button>
                            <button class="btn btn-reject" data-id="${req.id}">Reject</button>
                        </div>
                    </div>
                `).join('');

                pendingList.querySelectorAll('.btn-approve').forEach(btn => {
                    btn.addEventListener('click', function() {
                        approveRequest(this.dataset.id);
                    });
                });

                pendingList.querySelectorAll('.btn-reject').forEach(btn => {
                    btn.addEventListener('click', function() {
                        const reason = prompt('Rejection reason (optional):');
                        rejectRequest(this.dataset.id, reason || '');
                    });
                });
            } else {
                pendingList.innerHTML = '<p class="empty-message">No pending requests.</p>';
            }
        } catch (err) {
            console.error('Failed to load pending requests:', err);
        }
    }

    async function approveRequest(requestId) {
        try {
            const response = await fetch('/api/v1/requests/' + requestId + '/approve', {
                method: 'PUT'
            });

            if (response.ok) {
                alert('Request approved!');
                loadPendingRequests();
            } else {
                alert('Failed to approve request.');
            }
        } catch (err) {
            console.error('Approve failed:', err);
            alert('Failed to approve request.');
        }
    }

    async function rejectRequest(requestId, reason) {
        try {
            const response = await fetch('/api/v1/requests/' + requestId + '/reject', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reason: reason })
            });

            if (response.ok) {
                alert('Request rejected.');
                loadPendingRequests();
            } else {
                alert('Failed to reject request.');
            }
        } catch (err) {
            console.error('Reject failed:', err);
            alert('Failed to reject request.');
        }
    }

    loadPendingRequests();
});
</script>
{/block}
