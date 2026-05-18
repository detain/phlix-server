{**
 * Book reader stub page
 *
 * Provides a minimal paginated HTML reader for books.
 * This is intentionally minimal - full EPUB rendering via
 * browser-based EPUB.js is a future enhancement.
 *
 * @since 0.17.0
 *}
{extends file="layouts/player.tpl"}

{block name="content"}
<div class="reader-page" data-book-id="{$book.id}">
    <div class="reader-toolbar">
        <a href="/books/{$book.id}" class="reader-back">
            ← Back to Book
        </a>
        <div class="reader-title">
            {$book.name}
        </div>
        <div class="reader-controls">
            <button class="btn-font-size" data-action="decrease" title="Decrease font size">A-</button>
            <button class="btn-font-size" data-action="increase" title="Increase font size">A+</button>
            <button class="btn-theme" data-theme="light" title="Light mode">☀</button>
            <button class="btn-theme" data-theme="sepia" title="Sepia mode">📜</button>
            <button class="btn-theme" data-theme="dark" title="Dark mode">🌙</button>
        </div>
    </div>

    <div class="reader-content reader-theme-{$theme|default:'light'}">
        <div class="reader-page-content">
            {if !empty($book.metadata.description)}
            <h2>About this Book</h2>
            <p>{$book.metadata.description}</p>
            {/if}

            <h3>Book Information</h3>
            <dl class="reader-book-info">
                <dt>Title</dt>
                <dd>{$book.name}</dd>

                {if !empty($book.metadata.author)}
                <dt>Author</dt>
                <dd>{$book.metadata.author}</dd>
                {/if}

                {if !empty($book.metadata.publisher)}
                <dt>Publisher</dt>
                <dd>{$book.metadata.publisher}</dd>
                {/if}

                {if !empty($book.metadata.page_count)}
                <dt>Pages</dt>
                <dd>{$book.metadata.page_count}</dd>
                {/if}

                {if !empty($book.metadata.isbn)}
                <dt>ISBN</dt>
                <dd>{$book.metadata.isbn}</dd>
                {/if}
            </dl>

            <div class="reader-notice">
                <p><strong>Reader Notice:</strong> This is a basic reader stub that displays
                book metadata. Full paginated EPUB rendering with text flow is planned
                for a future release.</p>
                <p>You can download the book file to read it in your preferred
                reader application.</p>
            </div>
        </div>
    </div>

    <div class="reader-pagination">
        <button class="btn btn-secondary" disabled>Previous</button>
        <span class="page-indicator">Page 1</span>
        <button class="btn btn-secondary" disabled>Next</button>
    </div>
</div>
{/block}
