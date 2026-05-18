{**
 * Audiobook detail page
 *
 * Displays audiobook information, chapters, and player controls
 *
 * @since 0.18.0
 *}
{extends file="layouts/main.tpl"}

{block name="content"}
<div class="audiobook-detail">
    <div class="audiobook-detail-container">
        <div class="audiobook-cover-section">
            {if !empty($audiobook.metadata.cover_path)}
            <img src="/audiobooks/{$audiobook.id}/cover" alt="{$audiobook.name}" class="audiobook-cover-large">
            {else}
            <div class="audiobook-cover-placeholder">
                <span>📖</span>
            </div>
            {/if}
        </div>

        <div class="audiobook-info-section">
            <h1 class="audiobook-title">{$audiobook.name}</h1>

            {if !empty($audiobook.metadata.author)}
            <p class="audiobook-author">By {$audiobook.metadata.author}</p>
            {/if}

            {if !empty($audiobook.metadata.narrator)}
            <p class="audiobook-narrator">Narrated by {$audiobook.metadata.narrator}</p>
            {/if}

            {if !empty($audiobook.metadata.series)}
            <p class="audiobook-series">
                Part of: {$audiobook.metadata.series}
                {if !empty($audiobook.metadata.series_position)}
                (#{$audiobook.metadata.series_position})
                {/if}
            </p>
            {/if}

            <div class="audiobook-metadata">
                {if !empty($audiobook.metadata.duration_ms)}
                <div class="metadata-item">
                    <span class="metadata-label">Duration:</span>
                    <span class="metadata-value">{$audiobook.metadata.duration_ms / 1000|date_format:"%H:%M:%S"}</span>
                </div>
                {/if}

                {if !empty($audiobook.metadata.language)}
                <div class="metadata-item">
                    <span class="metadata-label">Language:</span>
                    <span class="metadata-value">{$audiobook.metadata.language}</span>
                </div>
                {/if}

                <div class="metadata-item">
                    <span class="metadata-label">Chapters:</span>
                    <span class="metadata-value">{count($audiobook.metadata.chapters)|default:0}</span>
                </div>
            </div>

            {if !empty($audiobook.metadata.description)}
            <div class="audiobook-description">
                <h3>Description</h3>
                <p>{$audiobook.metadata.description}</p>
            </div>
            {/if}

            <div class="audiobook-actions">
                <a href="/audiobooks/{$audiobook.id}/read" class="btn btn-primary">Play</a>
                <a href="/audiobooks/{$audiobook.id}/download" class="btn btn-secondary">Download</a>
            </div>
        </div>
    </div>

    <div class="audiobook-chapters-section">
        <h2>Chapters</h2>
        <div class="chapter-list">
            {foreach from=$audiobook.metadata.chapters item=$chapter key=$index}
            {include file="audiobooks/partials/chapter_row.tpl" chapter=$chapter index=$index}
            {/foreach}
        </div>
    </div>
</div>
{/block}
