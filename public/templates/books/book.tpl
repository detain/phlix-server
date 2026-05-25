{**
 * Book detail page
 *
 * Displays detailed information about a book including
 * cover, metadata, and reading options
 *
 * @since 0.17.0
 *}
{extends file="layouts/main.tpl"}

{block name="main"}
<div class="book-detail">
    <div class="book-detail-container">
        <div class="book-cover-section">
            {if !empty($book.metadata.cover_path)}
            <img src="/books/{$book.id}/cover" alt="{$book.name}" class="book-cover-large">
            {else}
            <div class="book-cover-placeholder">
                <span class="book-icon">📖</span>
            </div>
            {/if}
        </div>

        <div class="book-info-section">
            <h1 class="book-title">{$book.name}</h1>

            {if !empty($book.metadata.author)}
            <p class="book-author">by {$book.metadata.author}</p>
            {/if}

            <div class="book-metadata">
                {if !empty($book.metadata.publisher)}
                <div class="metadata-item">
                    <span class="metadata-label">Publisher:</span>
                    <span class="metadata-value">{$book.metadata.publisher}</span>
                </div>
                {/if}

                {if !empty($book.metadata.language)}
                <div class="metadata-item">
                    <span class="metadata-label">Language:</span>
                    <span class="metadata-value">{$book.metadata.language}</span>
                </div>
                {/if}

                {if !empty($book.metadata.pub_date)}
                <div class="metadata-item">
                    <span class="metadata-label">Published:</span>
                    <span class="metadata-value">{$book.metadata.pub_date}</span>
                </div>
                {/if}

                {if !empty($book.metadata.page_count)}
                <div class="metadata-item">
                    <span class="metadata-label">Pages:</span>
                    <span class="metadata-value">{$book.metadata.page_count}</span>
                </div>
                {/if}

                {if !empty($book.metadata.isbn)}
                <div class="metadata-item">
                    <span class="metadata-label">ISBN:</span>
                    <span class="metadata-value">{$book.metadata.isbn}</span>
                </div>
                {/if}
            </div>

            {if !empty($book.metadata.description)}
            <div class="book-description">
                <h3>Description</h3>
                <p>{$book.metadata.description}</p>
            </div>
            {/if}

            <div class="book-actions">
                <a href="/books/{$book.id}/read" class="btn btn-primary btn-read">
                    Read Book
                </a>
                <a href="/books/{$book.id}/download" class="btn btn-secondary">
                    Download
                </a>
            </div>
        </div>
    </div>
</div>
{/block}
