{extends file="layouts/main.tpl"}

{block name="title"}Search - Phlix{/block}

{block name="main"}
<div class="search-page">
    <header class="search-header">
        <h1>Search</h1>
        <form method="get" action="/search" class="search-form">
            <input type="search"
                   name="q"
                   value="{$query|default:''|escape:'html'}"
                   placeholder="Search movies, shows, music, books..."
                   class="search-input"
                   autofocus>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
    </header>

    {if $query}
        {if $results}
            <p class="search-summary">{$results|count} result{if $results|count != 1}s{/if} for &ldquo;{$query|escape:'html'}&rdquo;</p>
            <div class="media-grid">
                {foreach $results as $item}
                    {include file="partials/media_card.tpl" item=$item}
                {/foreach}
            </div>
        {else}
            <p class="search-empty">No results for &ldquo;{$query|escape:'html'}&rdquo;.</p>
        {/if}
    {else}
        <p class="search-empty">Enter a query above to search your libraries.</p>
    {/if}
</div>
{/block}
