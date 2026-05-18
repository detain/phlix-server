{**
 * Library header partial with theme media player
 *
 * Displays theme media player with audio toggle and backdrop video.
 * Theme audio (theme.mp3) and video (backdrop.mp4) are discovered at
 * the library root level during library scanning.
 *
 * @since 0.14.0
 *}

{if $themeMedia}
<div class="theme-media-player"
     data-audio="{if $themeMedia.audio}{$themeMedia.audio.url}{/if}"
     data-video="{if $themeMedia.video}{$themeMedia.video.url}{/if}"
     data-has-audio="{if $themeMedia.audio}true{else}false{/if}"
     data-has-video="{if $themeMedia.video}true{else}false{/if}">
    <button class="theme-media-toggle" title="Toggle theme music" aria-label="Toggle theme music">
        <span class="theme-media-icon">♫</span>
        <span class="theme-media-label">Theme Music</span>
    </button>
    <div class="theme-media-autoplay-overlay" style="display: none;">
        <span>Tap to enable theme music</span>
    </div>
</div>
{/if}
