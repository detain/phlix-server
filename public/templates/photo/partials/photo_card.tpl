<div class="photo-card">
    <a href="/photo/photo/{$photo.id}">
        <div class="photo-thumbnail">
            <img src="/photo/photos/{$photo.id}/thumbnail?w=300&h=300&fit=cover"
                 alt="{$photo.name}"
                 loading="lazy">
        </div>
        <div class="photo-info">
            <h4 class="photo-name">{$photo.name}</h4>
            {if !empty($photo.metadata.date_taken_unix)}
            <span class="photo-date">{$photo.metadata.date_taken_unix|date_format:"%b %d, %Y"}</span>
            {/if}
        </div>
    </a>
</div>
