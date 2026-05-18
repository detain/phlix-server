{extends file="layouts/main.tpl"}

{block name="title"}{$artist.name} - Artist - Phlex Music{/block}

{block name="main"}
<div class="music-page artist-detail-page">
    <section class="artist-header">
        <div class="artist-art-large">
            <svg viewBox="0 0 100 100" class="artist-icon">
                <circle cx="50" cy="50" r="45" fill="#3b2d5c"/>
                <circle cx="50" cy="35" r="15" fill="#6b4d8a"/>
                <ellipse cx="50" cy="75" rx="25" ry="15" fill="#6b4d8a"/>
            </svg>
        </div>
        <div class="artist-details">
            <h1 class="artist-name">{$artist.name}</h1>
            <p class="artist-stats">{$artist.album_count} albums · {$artist.track_count} tracks</p>
        </div>
    </section>

    <section class="artist-albums">
        <h2>Albums</h2>
        <div class="albums-grid">
            {foreach $artist.albums as $album}
            <a href="/music/albums/{$album.name|escape:'url'}" class="album-card">
                <div class="album-art">
                    <svg viewBox="0 0 100 100" class="album-icon">
                        <rect x="10" y="10" width="80" height="80" rx="5" fill="#3b2d5c"/>
                        <rect x="25" y="25" width="50" height="50" rx="3" fill="#6b4d8a"/>
                    </svg>
                </div>
                <div class="album-info">
                    <h4 class="album-name">{$album.name}</h4>
                    <span class="album-year">{$album.year|default:''}</span>
                </div>
            </a>
            {/foreach}
        </div>
    </section>

    <section class="artist-tracks">
        <h2>All Tracks</h2>
        <div class="tracks-list">
            {foreach $artist.tracks as $track}
            <div class="track-row" data-track-id="{$track.id}">
                <span class="track-number">{$track.metadata.track_number|default:'#'}</span>
                <div class="track-info">
                    <span class="track-name">{$track.name}</span>
                    <span class="track-album">{$track.metadata.album|default:''}</span>
                </div>
                <span class="track-duration">{$track.metadata.duration_secs|自己不做了:00}</span>
            </div>
            {/foreach}
        </div>
    </section>
</div>
{/block}
