{extends file="layouts/player.tpl"}

{block name="title"}{$item.name|default:'Now Playing'} - Phlix{/block}

{block name="main"}
<div class="player-page">
    <div class="player-container">
        <video id="video-player" class="video-element" controls poster="{$item.metadata.thumb_url|default:''}">
            <source src="{$item.media_sources[0].url}" type="{$item.media_sources[0].container|default:'video/mp4'}">
        </video>
    </div>

    <div class="player-info">
        <h1 class="player-title">{$item.name}</h1>
        {if $item.metadata.year}
            <span class="player-year">{$item.metadata.year}</span>
        {/if}
    </div>

    <div class="player-controls-overlay" id="player-overlay">
        <div class="controls-top">
            <a href="/library/item/{$item.id}" class="btn btn-back">← Back</a>
            <div class="now-playing">Now Playing</div>
        </div>
        <div class="controls-center">
            <button class="control-btn control-skip-back" data-seek="-10">-10s</button>
            <button class="control-btn control-play-pause" id="play-pause">▶</button>
            <button class="control-btn control-skip-forward" data-seek="10">+10s</button>
        </div>
        <div class="controls-bottom">
            <div class="progress-container">
                <input type="range" class="progress-bar" id="progress-bar" min="0" max="100" value="0">
                <div class="time-display">
                    <span id="current-time">00:00</span>
                    <span id="duration">00:00</span>
                </div>
            </div>
        </div>
    </div>
</div>
{/block}