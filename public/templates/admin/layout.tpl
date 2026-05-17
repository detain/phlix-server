{extends file="layouts/base.tpl"}

{* Admin layout — sidebar with admin-nav partial, main content slot. *}
{block name="body"}
<div class="app-layout admin-layout">
    <aside class="sidebar admin-sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/" class="logo">
                <img src="/assets/images/logo.svg" alt="Phlex" height="32">
            </a>
            <span class="sidebar-mode">Admin</span>
        </div>

        {include file="partials/admin-nav.tpl"}

        <div class="sidebar-footer">
            <a href="/" class="back-link">&larr; Back to library</a>
        </div>
    </aside>

    <main class="main-content admin-main">
        {block name="main"}{/block}
    </main>
</div>
{/block}

{block name="scripts"}
<script src="/assets/js/admin/plugins.js" defer></script>
{/block}
