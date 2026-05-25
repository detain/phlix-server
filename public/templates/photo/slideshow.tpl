{extends file="layouts/player.tpl"}

{block name="main"}
<div class="slideshow-page" data-interval="{$interval}">
    <div class="slideshow-container">
        <img id="slideshow-image" src="" alt="Slideshow">

        <div class="slideshow-controls">
            <button class="slideshow-btn" id="prev-btn" title="Previous">◀</button>
            <span class="slideshow-counter">
                <span id="current-slide">1</span> / <span id="total-slides">{$slideshow|@count}</span>
            </span>
            <button class="slideshow-btn" id="next-btn" title="Next">▶</button>
            <button class="slideshow-btn" id="play-pause-btn" title="Play/Pause">⏸</button>
            <button class="slideshow-btn" id="exit-btn" title="Exit">✕</button>
        </div>

        <div class="slideshow-caption" id="slideshow-caption"></div>
    </div>

    <div class="slideshow-thumbnails">
        {foreach from=$slideshow item=$slide name=slides}
        <button class="thumbnail-btn {if $smarty.foreach.slides.first}active{/if}"
                data-index="{$smarty.foreach.slides.index}"
                data-src="{$slide.url}">
            <img src="{$slide.thumbnail_url}" alt="{$slide.caption}">
        </button>
        {/foreach}
    </div>
</div>
{/block}
