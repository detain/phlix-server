{**
 * Chapter row partial template
 *
 * @since 0.18.0
 *}
<div class="chapter-row" data-chapter-index="{$index}">
    <span class="chapter-number">{$index + 1}</span>
    <span class="chapter-title">{$chapter.title|default:"Chapter `$index + 1`"}</span>
    <span class="chapter-duration">
        {if !empty($chapter.duration_ms)}
        {($chapter.duration_ms / 1000)|date_format:"%H:%M:%S"}
        {elseif !empty($chapter.end_ms) && !empty($chapter.start_ms)}
        {(($chapter.end_ms - $chapter.start_ms) / 1000)|date_format:"%H:%M:%S"}
        {/if}
    </span>
</div>
