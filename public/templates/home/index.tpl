{extends file="layouts/main.tpl"}

{block name="title"}Home - Phlix{/block}

{block name="main"}
<div class="home-page">
    <section class="hero">
        <h1>Welcome back, {$user.display_name|default:'User'}</h1>
        <p>What would you like to watch?</p>
    </section>

    {if $continue_watching}
    <section class="media-section">
        <h2 class="section-title">Continue Watching</h2>
        <div class="media-grid">
            {foreach $continue_watching as $item}
                {include file="partials/media_card.tpl" item=$item}
            {/foreach}
        </div>
    </section>
    {/if}

    <section class="media-section">
        <h2 class="section-title">Recently Added</h2>
        <div class="media-grid">
            {foreach $recently_added as $item}
                {include file="partials/media_card.tpl" item=$item}
            {/foreach}
        </div>
    </section>

    {foreach $libraries as $library}
    <section class="media-section">
        <h2 class="section-title">{$library.name}</h2>
        <div class="media-grid">
            {foreach $library.items as $item}
                {include file="partials/media_card.tpl" item=$item}
            {/foreach}
        </div>
        <a href="/library/{$library.id}" class="see-all">See all →</a>
    </section>
    {/foreach}
</div>
{/block}