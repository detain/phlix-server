{extends file="layouts/main.tpl"}

{block name="main"}
<div class="photo-page album-page">
    <div class="page-header">
        <a href="/photo/albums" class="back-link">← Back to Albums</a>
        <h1>{$album.date}</h1>
        <span class="photo-count">{$album.photo_count} photos</span>
    </div>

    {if !empty($album.photos)}
    <div class="photo-grid">
        {foreach from=$album.photos item=$photo}
        <a href="/photo/photo/{$photo.id}" class="photo-card" data-photo-id="{$photo.id}">
            <img src="/photo/photos/{$photo.id}/thumbnail?w=300&h=300&fit=cover"
                 alt="{$photo.name}"
                 loading="lazy">
            <div class="photo-overlay">
                <span class="photo-name">{$photo.name}</span>
            </div>
        </a>
        {/foreach}
    </div>
    {else}
    <div class="empty-state">
        <p>No photos in this album.</p>
    </div>
    {/if}
</div>
{/block}
