{**
 * Book card partial
 *
 * Displays a single book in a grid format
 *
 * @since 0.17.0
 *}
<div class="book-card">
    <a href="/books/{$book.id}" class="book-card-link">
        <div class="book-card-cover">
            {if !empty($book.metadata.cover_path)}
            <img src="/books/{$book.id}/cover" alt="{$book.name}" loading="lazy">
            {else}
            <div class="book-card-placeholder">
                <span class="book-icon">📖</span>
            </div>
            {/if}
        </div>
        <div class="book-card-info">
            <h3 class="book-card-title">{$book.name}</h3>
            {if !empty($book.metadata.author)}
            <p class="book-card-author">{$book.metadata.author}</p>
            {/if}
            {if !empty($book.metadata.page_count)}
            <p class="book-card-pages">{$book.metadata.page_count} pages</p>
            {/if}
        </div>
    </a>
</div>
