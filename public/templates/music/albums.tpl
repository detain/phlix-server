{extends file="layouts/main.tpl"}

{block name="title"}Albums - Phlex Music{/block}

{block name="main"}
<div class="music-page albums-page">
    <section class="page-header">
        <h1>Albums</h1>
        <p class="page-description">Browse your music collection by album</p>
    </section>

    {if $albums}
    <section class="albums-grid">
        {foreach $albums as $album}
        <a href="/music/albums/{$album.name|escape:'url'}" class="album-card">
            <div class="album-art">
                <svg viewBox="0 0 100 100" class="album-icon">
                    <rect x="10" y="10" width="80" height="80" rx="5" fill="#3b2d5c"/>
                    <rect x="25" y="25" width="50" height="50" rx="3" fill="#6b4d8a"/>
                </svg>
            </div>
            <div class="album-info">
                <h3 class="album-name">{$album.name}</h3>
                <span class="album-artist">{$album.artist}</span>
                <span class="album-year">{$album.year|default:''}</span>
            </div>
            <span class="album-track-count">{$album.track_count} tracks</span>
        </a>
        {/foreach}
    </section>
    {else}
    <div class="empty-state">
        <svg viewBox="0 0 100 100" class="empty-icon">
            <rect x="10" y="10" width="80" height="80" rx="5" fill="#2a2a2a"/>
            <rect x="25" y="25" width="50" height="50" rx="3" fill="#666"/>
        </svg>
        <h2>No Albums Found</h2>
        <p>Add music files to your library to see albums here.</p>
    </div>
    {/if}
</div>
{/block}
