{**
 * Audiobook player page
 *
 * @since 0.18.0
 *}
{extends file="layouts/player.tpl"}

{block name="main"}
<div class="audiobook-player" data-audiobook-id="{$audiobook.id}">
    <div class="player-layout">
        <div class="player-main">
            <div class="player-info">
                <h1 class="player-title">{$audiobook.name}</h1>
                {if !empty($audiobook.metadata.author)}
                <p class="player-author">By {$audiobook.metadata.author}</p>
                {/if}
            </div>

            <div class="player-progress-container">
                <div class="player-progress-bar">
                    <div class="player-progress-fill" id="progress-fill"></div>
                    <div class="player-chapter-progress" id="chapter-progress"></div>
                </div>
                <div class="player-time">
                    <span id="current-time">0:00</span>
                    <span id="total-time">{if !empty($audiobook.metadata.duration_ms)}{($audiobook.metadata.duration_ms / 1000)|date_format:"%H:%M:%S"}{/if}</span>
                </div>
            </div>

            <div class="player-controls">
                <button class="btn-skip" id="btn-skip-back" title="Skip back 30 seconds">-30</button>
                <button class="btn-play" id="btn-play" title="Play/Pause">▶</button>
                <button class="btn-skip" id="btn-skip-forward" title="Skip forward 30 seconds">+30</button>
            </div>

            <div class="player-chapter-info">
                <span class="current-chapter" id="current-chapter">Chapter 1</span>
            </div>
        </div>

        <div class="player-chapters">
            <h3>Chapters</h3>
            <div class="chapter-list" id="chapter-list">
                {foreach from=$audiobook.metadata.chapters item=$chapter key=$index}
                <div class="chapter-item {if $index == 0}active{/if}"
                     data-index="{$index}"
                     data-start-ms="{$chapter.start_ms|default:0}"
                     data-end-ms="{$chapter.end_ms|default:0}">
                    <span class="chapter-index">{$index + 1}</span>
                    <span class="chapter-title">{$chapter.title|default:"Chapter `$index + 1`"}</span>
                    <span class="chapter-duration">
                        {if !empty($chapter.end_ms) && !empty($chapter.start_ms)}
                        {(($chapter.end_ms - $chapter.start_ms) / 1000)|date_format:"%H:%M:%S"}
                        {/if}
                    </span>
                </div>
                {/foreach}
            </div>
        </div>
    </div>
</div>
{/block}
