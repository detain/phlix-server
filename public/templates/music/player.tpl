{extends file="layouts/main.tpl"}

{block name="title"}Music Player - Phlex{/block}

{block name="main"}
<div class="music-page player-page">
    <section class="player-container">
        <div class="player-artwork">
            <svg viewBox="0 0 100 100" class="album-icon" id="player-album-art">
                <rect x="10" y="10" width="80" height="80" rx="5" fill="#3b2d5c"/>
                <rect x="25" y="25" width="50" height="50" rx="3" fill="#6b4d8a"/>
            </svg>
        </div>

        <div class="player-info">
            <h2 class="player-track-name" id="player-track-name">Select a track</h2>
            <p class="player-artist-name" id="player-artist-name">-</p>
            <p class="player-album-name" id="player-album-name">-</p>
        </div>

        <div class="player-progress">
            <span class="progress-time" id="progress-current">0:00</span>
            <div class="progress-bar">
                <div class="progress-fill" id="progress-fill"></div>
                <div class="progress-handle" id="progress-handle"></div>
            </div>
            <span class="progress-time" id="progress-total">0:00</span>
        </div>

        <div class="player-controls">
            <button class="control-btn" id="btn-prev" title="Previous">⏮</button>
            <button class="control-btn control-play" id="btn-play" title="Play">▶</button>
            <button class="control-btn" id="btn-next" title="Next">⏭</button>
            <button class="control-btn" id="btn-shuffle" title="Shuffle">🔀</button>
            <button class="control-btn" id="btn-repeat" title="Repeat">🔁</button>
        </div>

        <div class="player-volume">
            <span class="volume-icon" id="volume-icon">🔊</span>
            <input type="range" class="volume-slider" id="volume-slider" min="0" max="100" value="80">
        </div>
    </section>

    <aside class="player-queue">
        <h3>Queue</h3>
        <div class="queue-list" id="queue-list">
            <!-- Queue items populated by JS -->
        </div>
    </aside>
</div>
{/block}
