{**
 * Books library index page
 *
 * Displays a grid of all books across all book libraries
 *
 * @since 0.17.0
 *}
{extends file="layouts/main.tpl"}

{block name="content"}
<div class="books-page">
    <header class="books-header">
        <h1>Books</h1>
        <p class="books-subtitle">Your book library</p>
    </header>

    {if empty($books)}
    <div class="books-empty">
        <p>No books found in your library.</p>
        <p>Add a book library to start browsing your collection.</p>
    </div>
    {else}
    <div class="books-grid">
        {foreach from=$books item=$book}
            {include file="books/partials/book_card.tpl" book=$book}
        {/foreach}
    </div>
    {/if}
</div>
{/block}
