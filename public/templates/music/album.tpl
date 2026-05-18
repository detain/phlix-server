{extends file="layouts/main.tpl"}

{block name="title"}{$album.name} - {$album.artist} - Phlex Music{/block}

{block name="main"}
<div class="music-page album-detail-page">
    <section class="album-header">
        <div class="album-art-large">
            <svg viewBox="0 0 100 100" class="album-icon">
                <rect x="10" y="10" width="80" height="80" rx="5" fill="#3b2d5c"/>
                <rect x="25" y="25" width="50" height="50" rx="3" fill="#6b4d8a"/>
            </svg>
        </div>
        <div class="album-details">
            <h1 class="album-name">{$album.name}</h1>
            <p class="album-artist">{$album.artist}</p>
            <p class="album-year">{$album.year|default:''}</p>
            <p class="album-track-count">{$album.track_count} tracks</p>
            <button class="play-all-btn" data-album-id="{$album.name}">Play All</button>
        </div>
    </section>

    <section class="album-tracks">
        <div class="tracks-list">
            {foreach $album.tracks as $track}
            <div class="track-row" data-track-id="{$track.id}" data-track-path="{$track.path}">
                <span class="track-number">{$track.metadata.track_number|default:'#'}</span>
                <div class="track-info">
                    <span class="track-name">{$track.name}</span>
                    {if $track.metadata.duration_secs}
                    <span class="track-duration">{$track.metadata.duration_secs}</span>
                    {/if}
                </div>
                <button class="play-track-btn" title="Play">▶</button>
            </div>
            {/foreach}
        </div>
    </section>
</div>
{/block}
