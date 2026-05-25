{**
 * Audiobooks library index page
 *
 * Displays a grid of all audiobooks across all audiobook libraries
 *
 * @since 0.18.0
 *}
{extends file="layouts/main.tpl"}

{block name="main"}
<div class="audiobooks-page">
    <header class="audiobooks-header">
        <h1>Audiobooks</h1>
        <p class="audiobooks-subtitle">Your audiobook library</p>
    </header>

    {if empty($audiobooks)}
    <div class="audiobooks-empty">
        <p>No audiobooks found in your library.</p>
        <p>Add an audiobook library to start browsing your collection.</p>
    </div>
    {else}
    <div class="audiobooks-grid">
        {foreach from=$audiobooks item=$audiobook}
            {include file="audiobooks/partials/audiobook_card.tpl" audiobook=$audiobook}
        {/foreach}
    </div>
    {/if}
</div>
{/block}
