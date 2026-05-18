{extends file="layouts/main.tpl"}

{block name="body"}
<div class="photo-page">
    <div class="page-header">
        <h1>Photo Albums</h1>
        <p class="subtitle">Browse your photo collection by date</p>
    </div>

    {if !empty($albums)}
    <div class="albums-grid">
        {foreach from=$albums item=$album}
        <a href="/photo/album/{$album.id}?library_id={$album.photos[0].library_id}" class="album-card">
            <div class="album-cover">
                {if !empty($album.cover_photo)}
                <img src="/photo/photos/{$album.cover_photo.id}/thumbnail?w=400&h=300&fit=cover"
                     alt="{$album.date}"
                     loading="lazy">
                {else}
                <div class="album-placeholder">
                    <span>📷</span>
                </div>
                {/if}
            </div>
            <div class="album-info">
                <h3 class="album-date">{$album.date}</h3>
                <span class="album-count">{$album.photo_count} photos</span>
            </div>
        </a>
        {/foreach}
    </div>
    {else}
    <div class="empty-state">
        <div class="empty-icon">📷</div>
        <h2>No photo albums yet</h2>
        <p>Add a photo library to start browsing your photos.</p>
        <a href="/library" class="btn btn-primary">Go to Library</a>
    </div>
    {/if}
</div>
{/block}
