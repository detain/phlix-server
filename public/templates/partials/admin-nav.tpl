{*
    Admin sidebar nav (Step A.5). Currently lists only Plugins; subsequent
    admin pages (users, libraries, settings) plug in here.
*}
<nav class="sidebar-nav admin-nav">
    <a href="/admin/plugins"
       class="nav-item {if $current_page == 'admin_plugins'}active{/if}">
        <span class="icon">&#x1F9E9;</span>
        <span>Plugins</span>
    </a>
</nav>
