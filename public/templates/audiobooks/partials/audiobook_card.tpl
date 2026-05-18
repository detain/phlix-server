{**
 * Audiobook card partial template
 *
 * @since 0.18.0
 *}
<div class="audiobook-card">
    <a href="/audiobooks/{$audiobook.id}" class="audiobook-card-link">
        <div class="audiobook-card-cover">
            {if !empty($audiobook.metadata.cover_path)}
            <img src="/audiobooks/{$audiobook.id}/cover" alt="{$audiobook.name}">
            {else}
            <div class="audiobook-card-placeholder">
                <span>📖</span>
            </div>
            {/if}
        </div>
        <div class="audiobook-card-info">
            <h3 class="audiobook-card-title">{$audiobook.name}</h3>
            {if !empty($audiobook.metadata.author)}
            <p class="audiobook-card-author">{$audiobook.metadata.author}</p>
            {/if}
            {if !empty($audiobook.metadata.duration_ms)}
            <p class="audiobook-card-duration">{($audiobook.metadata.duration_ms / 1000)|date_format:"%H:%M:%S"}</p>
            {/if}
        </div>
    </a>
</div>
