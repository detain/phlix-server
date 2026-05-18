{extends file="layouts/main.tpl"}

{block name="title"}Tracks - Phlex Music{/block}

{block name="main"}
<div class="music-page tracks-page">
    <section class="page-header">
        <h1>All Tracks</h1>
        <p class="page-description">Browse all tracks in your music library</p>
        <div class="search-box">
            <input type="text" id="track-search" placeholder="Search tracks..." class="search-input">
        </div>
    </section>

    {if $tracks}
    <section class="tracks-list">
        <div class="tracks-header">
            <span class="col-number">#</span>
            <span class="col-title">Title</span>
            <span class="col-artist">Artist</span>
            <span class="col-album">Album</span>
            <span class="col-duration">Duration</span>
        </div>
        {foreach $tracks as $track}
        <div class="track-row" data-track-id="{$track.id}" data-track-path="{$track.path}">
            <span class="track-number">{$track.track_number|default:'#'}</span>
            <div class="track-info">
                <span class="track-name">{$track.name}</span>
            </div>
            <span class="track-artist">{$track.artist|default:'Unknown Artist'}</span>
            <span class="track-album">{$track.album|default:'Unknown Album'}</span>
            <span class="track-duration">
                {if $track.duration_secs}
                    {math equation="floor(duration / 60)"}:{(duration % 60)|sprintf:"%02d"}
                {/if}
            </span>
            <button class="play-track-btn" title="Play">▶</button>
        </div>
        {/foreach}
    </section>

    {if $total > $limit}
    <div class="pagination">
        {if $offset > 0}
            <a href="?offset={$offset - $limit}&limit={$limit}" class="pagination-prev">Previous</a>
        {/if}
        <span class="pagination-info">Showing {$offset + 1} - {min($offset + $limit, $total)} of {$total}</span>
        {if $offset + $limit < $total}
            <a href="?offset={$offset + $limit}&limit={$limit}" class="pagination-next">Next</a>
        {/if}
    </div>
    {/if}
    {else}
    <div class="empty-state">
        <svg viewBox="0 0 100 100" class="empty-icon">
            <circle cx="50" cy="50" r="45" fill="#2a2a2a"/>
            <path d="M35 40 L35 60 L50 55 L65 60 L65 40 L50 45 Z" fill="#666"/>
        </svg>
        <h2>No Tracks Found</h2>
        <p>Add music files to your library to see tracks here.</p>
    </div>
    {/if}
</div>
{/block}
