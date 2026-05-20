<header class="site-header">
    <div class="header-content">
        <a href="/" class="header-logo">
            <img src="/assets/images/logo.svg" alt="Phlix" height="32">
        </a>
        <nav class="header-nav">
            <a href="/" class="header-nav-item {if $current_page == 'home'}active{/if}">Home</a>
            <a href="/library" class="header-nav-item {if $current_page == 'library'}active{/if}">Library</a>
            <a href="/music" class="header-nav-item {if $current_page == 'music'}active{/if}">Music</a>
            <a href="/audiobooks" class="header-nav-item {if $current_page == 'audiobooks'}active{/if}">Audiobooks</a>
            <a href="/books" class="header-nav-item {if $current_page == 'books'}active{/if}">Books</a>
            <a href="/search" class="header-nav-item {if $current_page == 'search'}active{/if}">Search</a>
        </nav>
    </div>
</header>