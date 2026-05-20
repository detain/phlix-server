{extends file="layouts/main.tpl"}

{block name="title"}{$item.name|default:'Item Details'} - Phlix{/block}

{block name="main"}
<div class="detail-page">
    <div class="detail-backdrop">
        {if $item.metadata.backdrop_url}
            <img src="{$item.metadata.backdrop_url}" alt="{$item.name}">
        {/if}
        <div class="backdrop-overlay"></div>
    </div>

    <div class="detail-content">
        <div class="detail-poster">
            {if $item.metadata.poster_url}
                <img src="{$item.metadata.poster_url}" alt="{$item.name}">
            {else}
                <div class="poster-placeholder">
                    <span class="icon">🎬</span>
                </div>
            {/if}
        </div>

        <div class="detail-info">
            <h1 class="detail-title">{$item.name}</h1>

            {if $item.metadata.year}
                <span class="detail-year">{$item.metadata.year}</span>
            {/if}

            {if $item.metadata.genres}
                <div class="detail-genres">
                    {foreach $item.metadata.genres as $genre}
                        <span class="genre-tag">{$genre}</span>
                    {/foreach}
                </div>
            {/if}

            <p class="detail-overview">{$item.metadata.overview|default:''}</p>

            <div class="detail-actions">
                <a href="/player/{$item.id}" class="btn btn-primary btn-play">
                    ▶ Play
                </a>
                {if $item.user_data.resume_position_ticks > 0}
                    <button class="btn btn-secondary btn-resume" data-id="{$item.id}">
                        Resume ({$item.user_data.resume_position_ticks|count_ticks_to_time})
                    </button>
                {/if}
            </div>
        </div>
    </div>

    {if $item.metadata.actors}
    <section class="detail-section">
        <h2 class="section-title">Cast</h2>
        <div class="actors-list">
            {foreach $item.metadata.actors as $actor}
                <div class="actor-card">
                    {if $actor.image_url}
                        <img src="{$actor.image_url}" alt="{$actor.name}">
                    {/if}
                    <span class="actor-name">{$actor.name}</span>
                    <span class="actor-role">{$actor.role}</span>
                </div>
            {/foreach}
        </div>
    </section>
    {/if}
</div>
{/block}