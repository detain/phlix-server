{* Reusable music card partial for displaying tracks/albums *}

{if isset($track)}
<div class="music-card track-card" data-track-id="{$track.id}" data-track-path="{$track.path}">
    <div class="card-art">
        <svg viewBox="0 0 100 100" class="music-icon">
            <rect x="10" y="10" width="80" height="80" rx="5" fill="#3b2d5c"/>
            <path d="M40 35 L40 65 L60 55 Z" fill="#6b4d8a"/>
        </svg>
        <button class="card-play-btn" title="Play">▶</button>
    </div>
    <div class="card-info">
        <h4 class="card-title">{$track.name}</h4>
        <p class="card-subtitle">{$track.artist|default:$track.metadata.artist|default:'Unknown Artist'}</p>
        {if $track.album|default:$track.metadata.album}
        <p class="card-meta">{$track.album|default:$track.metadata.album}</p>
        {/if}
    </div>
    {if $track.duration_secs|default:$track.metadata.duration_secs}
    <span class="card-duration">{$track.duration_secs|default:$track.metadata.duration_secs}</span>
    {/if}
</div>

{elseif isset($album)}
<div class="music-card album-card" data-album-name="{$album.name|escape:'html'}">
    <div class="card-art">
        <svg viewBox="0 0 100 100" class="music-icon">
            <rect x="10" y="10" width="80" height="80" rx="5" fill="#3b2d5c"/>
            <rect x="25" y="25" width="50" height="50" rx="3" fill="#6b4d8a"/>
        </svg>
        <button class="card-play-btn" title="Play">▶</button>
    </div>
    <div class="card-info">
        <h4 class="card-title">{$album.name}</h4>
        <p class="card-subtitle">{$album.artist|default:'Unknown Artist'}</p>
        {if $album.year}
        <p class="card-meta">{$album.year}</p>
        {/if}
    </div>
    <span class="card-track-count">{$album.track_count|default:0} tracks</span>
</div>

{elseif isset($artist)}
<div class="music-card artist-card" data-artist-name="{$artist.name|escape:'html'}">
    <div class="card-art artist-art">
        <svg viewBox="0 0 100 100" class="music-icon">
            <circle cx="50" cy="50" r="45" fill="#3b2d5c"/>
            <circle cx="50" cy="35" r="15" fill="#6b4d8a"/>
            <ellipse cx="50" cy="75" rx="25" ry="15" fill="#6b4d8a"/>
        </svg>
    </div>
    <div class="card-info">
        <h4 class="card-title">{$artist.name}</h4>
        <p class="card-subtitle">{$artist.album_count|default:0} albums</p>
        <p class="card-meta">{$artist.track_count|default:0} tracks</p>
    </div>
</div>
{/if}
