{extends file="layouts/main.tpl"}

{block name="title"}Library - Phlix{/block}

{block name="main"}
<div class="library-page">
    <header class="library-header">
        <h1>{$library.name|default:'My Library'}</h1>
        <div class="library-filters">
            <select name="sort" class="filter-select">
                <option value="name">Name</option>
                <option value="date_added">Date Added</option>
                <option value="year">Year</option>
            </select>
        </div>
    </header>

    <div class="media-grid">
        {foreach $items as $item}
            {include file="partials/media_card.tpl" item=$item}
        {/foreach}
    </div>

    {if $pagination.has_more}
    <div class="load-more">
        <button class="btn btn-secondary" data-offset="{$pagination.next_offset}">Load More</button>
    </div>
    {/if}
</div>
{/block}