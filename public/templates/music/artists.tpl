{extends file="layouts/main.tpl"}

{block name="title"}Artists - Phlex Music{/block}

{block name="main"}
<div class="music-page artists-page">
    <section class="page-header">
        <h1>Artists</h1>
        <p class="page-description">Browse your music collection by artist</p>
    </section>

    {if $artists}
    <section class="artists-grid">
        {foreach $artists as $artist}
        <a href="/music/artists/{$artist.name|escape:'url'}" class="artist-card">
            <div class="artist-art">
                <svg viewBox="0 0 100 100" class="artist-icon">
                    <circle cx="50" cy="50" r="45" fill="#3b2d5c"/>
                    <circle cx="50" cy="35" r="15" fill="#6b4d8a"/>
                    <ellipse cx="50" cy="75" rx="25" ry="15" fill="#6b4d8a"/>
                </svg>
            </div>
            <div class="artist-info">
                <h3 class="artist-name">{$artist.name}</h3>
                <span class="artist-meta">{$artist.album_count} albums · {$artist.track_count} tracks</span>
            </div>
        </a>
        {/foreach}
    </section>
    {else}
    <div class="empty-state">
        <svg viewBox="0 0 100 100" class="empty-icon">
            <circle cx="50" cy="50" r="45" fill="#2a2a2a"/>
            <path d="M35 40 L35 60 L50 55 L65 60 L65 40 L50 45 Z" fill="#666"/>
        </svg>
        <h2>No Artists Found</h2>
        <p>Add music files to your library to see artists here.</p>
    </div>
    {/if}
</div>
{/block}
