{extends file="admin/layout.tpl"}

{block name="title"}Plugins - Admin{/block}

{block name="main"}
<section class="admin-plugins-page">
    <header class="page-header">
        <h1>Installed plugins</h1>
        <p class="page-subtitle">
            Manage plugins installed on this server. Install new plugins
            from a <code>plugin.json</code> URL using the form below.
        </p>
    </header>

    {* Install form — falls back to the JSON API directly when JS is off. *}
    <form id="plugin-install-form"
          action="/api/v1/admin/plugins/install"
          method="post"
          class="install-form">
        <label for="plugin-url">Install from URL</label>
        <input id="plugin-url"
               name="url"
               type="url"
               required
               placeholder="https://example.com/plugin.json">
        <button type="submit">Install</button>
        <p class="install-form-warning">
            Only install plugins from sources you trust. Signed plugins
            are verified against the trusted-key allowlist; unsigned
            plugins install with a warning in the server log.
        </p>
    </form>

    {* Plugin list table. *}
    {if $plugins|@count > 0}
    <table class="plugin-table" data-role="plugin-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Version</th>
                <th>Type</th>
                <th>Installed</th>
                <th>Signed</th>
                <th>Status</th>
                <th class="actions">Actions</th>
            </tr>
        </thead>
        <tbody>
        {foreach from=$plugins item=plugin}
            <tr data-plugin-name="{$plugin.name|escape:'html'}">
                <td>
                    <a href="/admin/plugins/{$plugin.name|escape:'html'}">{$plugin.name|escape:'html'}</a>
                </td>
                <td>{$plugin.version|escape:'html'}</td>
                <td>{$plugin.type|escape:'html'}</td>
                <td>{$plugin.installed_at|escape:'html'}</td>
                <td>{if $plugin.signed}signed{else}unsigned{/if}</td>
                <td>
                    {if $plugin.enabled}
                        <span class="status status-enabled">enabled</span>
                    {else}
                        <span class="status status-disabled">disabled</span>
                    {/if}
                </td>
                <td class="actions">
                    {if $plugin.enabled}
                        <button class="action-btn"
                                data-action="disable"
                                data-plugin="{$plugin.name|escape:'html'}">Disable</button>
                    {else}
                        <button class="action-btn"
                                data-action="enable"
                                data-plugin="{$plugin.name|escape:'html'}">Enable</button>
                    {/if}
                    <button class="action-btn danger"
                            data-action="uninstall"
                            data-plugin="{$plugin.name|escape:'html'}">Uninstall</button>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    {else}
    <p class="empty-state">
        No plugins installed yet. Paste a <code>plugin.json</code> URL
        in the form above to install one.
    </p>
    {/if}
</section>
{/block}
