{extends file="layouts/main.tpl"}

{block name="main"}
<div class="photo-page photo-view">
    <div class="photo-main">
        <a href="/photo/albums" class="back-link">← Back to Album</a>

        <div class="photo-container">
            <img src="/photo/photos/{$photo.id}/full"
                 alt="{$photo.name}"
                 class="photo-full">
        </div>
    </div>

    <aside class="exif-sidebar">
        <h2>{$photo.name}</h2>

        {include file="photo/partials/exif_panel.tpl" exif=$photo.exif}

        <div class="photo-actions">
            <a href="/photo/photos/{$photo.id}/full" download class="btn btn-secondary">Download Full Size</a>
            <a href="/photo/slideshow?album_id={$album_id}&library_id={$library_id}" class="btn btn-primary">Slideshow</a>
        </div>
    </aside>
</div>
{/block}
