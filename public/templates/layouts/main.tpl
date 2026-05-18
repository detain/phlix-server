{extends file="layouts/base.tpl"}

{block name="body"}
<div class="app-layout">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/" class="logo">
                <img src="/assets/images/logo.svg" alt="Phlex" height="32">
            </a>
        </div>

        <nav class="sidebar-nav">
            <a href="/" class="nav-item {if $current_page == 'home'}active{/if}">
                <span class="icon">🏠</span>
                <span>Home</span>
            </a>
            <a href="/library" class="nav-item {if $current_page == 'library'}active{/if}">
                <span class="icon">📚</span>
                <span>Library</span>
            </a>
            <a href="/photo/albums" class="nav-item {if $current_page == 'photos'}active{/if}">
                <span class="icon">📷</span>
                <span>Photos</span>
            </a>
            <a href="/search" class="nav-item {if $current_page == 'search'}active{/if}">
                <span class="icon">🔍</span>
                <span>Search</span>
            </a>
            <a href="/settings" class="nav-item {if $current_page == 'settings'}active{/if}">
                <span class="icon">⚙️</span>
                <span>Settings</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <span class="user-name">{$user.display_name|default:'Guest'}</span>
            </div>
        </div>
    </aside>

    <main class="main-content">
        {block name="main"}{/block}
    </main>
</div>
{/block}