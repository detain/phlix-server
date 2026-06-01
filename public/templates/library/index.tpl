{extends file="layouts/main.tpl"}

{block name="title"}Library - Phlix{/block}

{block name="main"}
<div class="library-page">
    <header class="library-header">
        <h1>{$library.name|default:'My Library'}</h1>
        <div class="library-filters">
            <input type="text" name="search" class="filter-input" placeholder="Search..." value="{$smarty.get.search|default:''}">
            <select name="genres" class="filter-select">
                <option value="">All Genres</option>
                <option value="Action" {if $smarty.get.genres === 'Action'}selected{/if}>Action</option>
                <option value="Drama" {if $smarty.get.genres === 'Drama'}selected{/if}>Drama</option>
                <option value="Comedy" {if $smarty.get.genres === 'Comedy'}selected{/if}>Comedy</option>
                <option value="Horror" {if $smarty.get.genres === 'Horror'}selected{/if}>Horror</option>
                <option value="Sci-Fi" {if $smarty.get.genres === 'Sci-Fi'}selected{/if}>Sci-Fi</option>
                <option value="Thriller" {if $smarty.get.genres === 'Thriller'}selected{/if}>Thriller</option>
                <option value="Romance" {if $smarty.get.genres === 'Romance'}selected{/if}>Romance</option>
                <option value="Animation" {if $smarty.get.genres === 'Animation'}selected{/if}>Animation</option>
                <option value="Documentary" {if $smarty.get.genres === 'Documentary'}selected{/if}>Documentary</option>
            </select>
            <select name="yearFrom" class="filter-select">
                <option value="">From Year</option>
                {for $y=2026 downto 1970}
                <option value="{$y}" {if $smarty.get.yearFrom == $y}selected{/if}>{$y}</option>
                {/for}
            </select>
            <select name="yearTo" class="filter-select">
                <option value="">To Year</option>
                {for $y=2026 downto 1970}
                <option value="{$y}" {if $smarty.get.yearTo == $y}selected{/if}>{$y}</option>
                {/for}
            </select>
            <select name="ratings" class="filter-select">
                <option value="">All Ratings</option>
                <option value="G" {if $smarty.get.ratings === 'G'}selected{/if}>G</option>
                <option value="PG" {if $smarty.get.ratings === 'PG'}selected{/if}>PG</option>
                <option value="PG-13" {if $smarty.get.ratings === 'PG-13'}selected{/if}>PG-13</option>
                <option value="R" {if $smarty.get.ratings === 'R'}selected{/if}>R</option>
            </select>
            <select name="sort" class="filter-select" id="sort-select">
                <option value="name" {if $smarty.get.sort === 'name' || !$smarty.get.sort}selected{/if}>Name</option>
                <option value="year" {if $smarty.get.sort === 'year'}selected{/if}>Year</option>
                <option value="rating" {if $smarty.get.sort === 'rating'}selected{/if}>Rating</option>
                <option value="date_added" {if $smarty.get.sort === 'date_added'}selected{/if}>Date Added</option>
            </select>
            <select name="order" class="filter-select" id="order-select">
                <option value="asc" {if $smarty.get.order !== 'desc'}selected{/if}>Asc</option>
                <option value="desc" {if $smarty.get.order === 'desc'}selected{/if}>Desc</option>
            </select>
        </div>
    </header>

    <div class="media-grid" id="media-grid">
        {foreach $items as $item}
            {include file="partials/media_card.tpl" item=$item}
        {/foreach}
    </div>

    <div class="load-more" id="load-more-container" style="display: none;">
        <button class="btn btn-secondary" id="load-more-btn">Load More</button>
    </div>
</div>

<script>
(function() {
    const grid = document.getElementById('media-grid');
    const sortSelect = document.getElementById('sort-select');
    const orderSelect = document.getElementById('order-select');
    const loadMoreContainer = document.getElementById('load-more-container');
    const loadMoreBtn = document.getElementById('load-more-btn');
    const filters = document.querySelectorAll('.library-filters .filter-input, .library-filters .filter-select');

    let currentParams = {};
    let currentOffset = 0;

    function buildParams() {
        const params = {};
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput && searchInput.value.trim()) {
            params.search = searchInput.value.trim();
        }
        filters.forEach(function(filter) {
            if (filter.value && filter.value !== '') {
                if (filter.name === 'genres' || filter.name === 'ratings' || filter.name === 'actors') {
                    params[filter.name] = [filter.value];
                } else {
                    params[filter.name] = filter.value;
                }
            }
        });
        params.sort = sortSelect ? sortSelect.value : 'name';
        params.order = orderSelect ? orderSelect.value : 'asc';
        params.limit = 50;
        params.offset = currentOffset;
        return params;
    }

    function renderItems(items) {
        grid.innerHTML = '';
        items.forEach(function(item) {
            const card = document.createElement('div');
            card.className = 'media-card';
            card.dataset.id = item.id;
            card.dataset.type = item.type;

            let posterHtml;
            if (item.poster_url) {
                posterHtml = '<img src="' + item.poster_url + '" alt="' + item.name + '" loading="lazy">';
            } else {
                posterHtml = '<div class="poster-placeholder"><span class="icon">🎬</span></div>';
            }

            let yearHtml = '';
            if (item.year) {
                yearHtml = '<span class="card-year">' + item.year + '</span>';
            }

            card.innerHTML =
                '<a href="/library/item/' + item.id + '">' +
                    '<div class="card-poster">' + posterHtml + '</div>' +
                    '<div class="card-info">' +
                        '<h3 class="card-title">' + item.name + '</h3>' +
                        yearHtml +
                    '</div>' +
                '</a>';
            grid.appendChild(card);
        });
    }

    async function loadItems(resetOffset) {
        if (resetOffset) {
            currentOffset = 0;
        }
        const params = buildParams();
        if (!resetOffset) {
            params.offset = currentOffset;
        }

        try {
            const queryString = new URLSearchParams(params).toString();
            const response = await fetch('/api/v1/media?' + queryString);
            if (!response.ok) {
                throw new Error('Failed to load items');
            }
            const data = await response.json();

            if (resetOffset) {
                renderItems(data.items);
            } else {
                data.items.forEach(function(item) {
                    const card = document.createElement('div');
                    card.className = 'media-card';
                    card.dataset.id = item.id;
                    card.dataset.type = item.type;

                    let posterHtml;
                    if (item.poster_url) {
                        posterHtml = '<img src="' + item.poster_url + '" alt="' + item.name + '" loading="lazy">';
                    } else {
                        posterHtml = '<div class="poster-placeholder"><span class="icon">🎬</span></div>';
                    }

                    let yearHtml = '';
                    if (item.year) {
                        yearHtml = '<span class="card-year">' + item.year + '</span>';
                    }

                    card.innerHTML =
                        '<a href="/library/item/' + item.id + '">' +
                            '<div class="card-poster">' + posterHtml + '</div>' +
                            '<div class="card-info">' +
                                '<h3 class="card-title">' + item.name + '</h3>' +
                                yearHtml +
                            '</div>' +
                        '</a>';
                    grid.appendChild(card);
                });
            }

            currentOffset += data.items.length;
            if (currentOffset < data.total) {
                loadMoreContainer.style.display = 'block';
            } else {
                loadMoreContainer.style.display = 'none';
            }
        } catch (e) {
            console.error('Error loading items:', e);
        }
    }

    filters.forEach(function(filter) {
        filter.addEventListener('change', function() {
            loadItems(true);
        });
    });

    sortSelect.addEventListener('change', function() {
        loadItems(true);
    });

    orderSelect.addEventListener('change', function() {
        loadItems(true);
    });

    if (loadMoreBtn) {
        loadMoreBtn.addEventListener('click', function() {
            loadItems(false);
        });
    }

    {if $smarty.get.search || $smarty.get.genres || $smarty.get.yearFrom || $smarty.get.yearTo || $smarty.get.ratings}
    loadItems(true);
    {/if}
})();
</script>
{/block}
