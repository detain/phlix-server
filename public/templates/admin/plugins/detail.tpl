{extends file="admin/layout.tpl"}

{block name="title"}{$plugin.name|escape:'html'} - Plugins{/block}

{block name="main"}
<section class="admin-plugin-detail">
    <header class="page-header">
        <a class="breadcrumb" href="/admin/plugins">&larr; All plugins</a>
        <h1>{$plugin.name|escape:'html'}</h1>
        <p class="page-subtitle">
            Version {$plugin.version|escape:'html'} —
            type <code>{$plugin.type|escape:'html'}</code> —
            entry <code>{$plugin.entry|escape:'html'}</code>
        </p>
    </header>

    <dl class="plugin-meta">
        <dt>Installed at</dt>
        <dd>{$plugin.installed_at|escape:'html'}</dd>

        <dt>Status</dt>
        <dd>
            {if $plugin.enabled}
                <span class="status status-enabled">enabled</span>
            {else}
                <span class="status status-disabled">disabled</span>
            {/if}
        </dd>

        <dt>Signature</dt>
        <dd>
            {if $plugin.signed}
                <span class="status status-signed">signed (verified against trusted-key allowlist)</span>
            {else}
                <span class="status status-unsigned">unsigned (warning logged at install time)</span>
            {/if}
        </dd>
    </dl>

    <h2>Settings</h2>
    {if $settings|@count > 0}
    <table class="settings-table">
        <thead>
            <tr>
                <th>Key</th>
                <th>Type</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
        {foreach from=$settings item=row}
            <tr>
                <td><code>{$row.key|escape:'html'}</code></td>
                <td>{$row.type|escape:'html'}</td>
                <td>
                    {if $row.secret}
                        <span class="masked">{$row.value|escape:'html'}</span>
                    {else}
                        {if $row.value === null}
                            <span class="muted">(not set)</span>
                        {else}
                            {$row.value|escape:'html'}
                        {/if}
                    {/if}
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    <p class="muted">
        Editing settings is read-only in A.5. The full settings editor
        ships in a later phase with encrypted-at-rest secret storage.
    </p>
    {else}
    <p class="empty-state">This plugin declares no settings.</p>
    {/if}
</section>
{/block}
