{extends file="admin/layout.tpl"}

{block name="title"}Install plugin - Admin{/block}

{block name="main"}
<section class="admin-plugin-install">
    <header class="page-header">
        <a class="breadcrumb" href="/admin/plugins">&larr; All plugins</a>
        <h1>Install plugin from URL</h1>
        <p class="page-subtitle">
            Paste a public <code>plugin.json</code> URL. The server will
            download, validate, and store the plugin's source under
            <code>var/plugins/</code>.
        </p>
    </header>

    {* Standalone install form (JS-off fallback). The index page also
       embeds the same form inline; both POST to the same JSON API. *}
    <form id="plugin-install-form"
          action="/api/v1/admin/plugins/install"
          method="post"
          class="install-form">
        <label for="plugin-url">URL</label>
        <input id="plugin-url"
               name="url"
               type="url"
               required
               placeholder="https://example.com/plugin.json">
        <button type="submit">Install</button>
    </form>

    <h2>Security notes</h2>
    <ul class="security-notes">
        <li>Only <code>https://</code> and <code>file://</code> URLs are accepted.</li>
        <li>The installer verifies the manifest's <code>sha256:</code>
            signature against the local trusted-key allowlist when one
            is present. Unsigned plugins install with a warning.</li>
        <li>This form requires an authenticated admin session
            (<code>users.is_admin = 1</code>).</li>
    </ul>
</section>
{/block}
