# Step 5.1: Web Portal Setup

**Phase:** 5 - Centralized Web Portal  
**Plan File:** step-5.1-web-portal-setup.md  
**Objective:** Set up the web portal structure with templates and static assets

---

## Overview

This step sets up the centralized web portal with Smarty templates, CSS framework, and basic page structure.

**Prerequisites:** Phase 4 must be completed first.

---

## Tasks

### 5.1.1 Create Web Portal Directory Structure

Create `public/templates/` directory structure:
```
public/
├── templates/
│   ├── layouts/
│   │   ├── base.tpl
│   │   ├── main.tpl
│   │   └── player.tpl
│   ├── partials/
│   │   ├── header.tpl
│   │   ├── footer.tpl
│   │   ├── sidebar.tpl
│   │   └── media_card.tpl
│   ├── auth/
│   │   ├── login.tpl
│   │   └── register.tpl
│   ├── home/
│   │   └── index.tpl
│   ├── library/
│   │   ├── index.tpl
│   │   └── detail.tpl
│   └── player/
│       └── index.tpl
├── assets/
│   ├── css/
│   │   ├── main.css
│   │   ├── components.css
│   │   └── player.css
│   ├── js/
│   │   ├── app.js
│   │   ├── api-client.js
│   │   └── player.js
│   └── images/
│       └── logo.svg
└── index.php (web entry)
```

### 5.1.2 Create Base Template

Create `public/templates/layouts/base.tpl`:
```smarty
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{block name="title"}Phlex{/block} - Media Server</title>
    <link rel="stylesheet" href="/assets/css/main.css">
    {block name="styles"}{/block}
</head>
<body>
    {block name="body"}
    <div class="app-container">
        {block name="content"}{/block}
    </div>
    {/block}
    
    <script src="/assets/js/app.js"></script>
    {block name="scripts"}{/block}
</body>
</html>
```

### 5.1.3 Create Main Layout with Navigation

Create `public/templates/layouts/main.tpl`:
```smarty
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
```

### 5.1.4 Create CSS Styles

Create `public/assets/css/main.css`:
```css
:root {
    --color-bg-primary: #0f0f1a;
    --color-bg-secondary: #1a1a2e;
    --color-bg-tertiary: #16213e;
    --color-text-primary: #ffffff;
    --color-text-secondary: #a0a0a0;
    --color-accent: #0066cc;
    --color-accent-hover: #0055aa;
    --color-border: #2d2d44;
    --color-success: #28a745;
    --color-warning: #ffc107;
    --color-error: #dc3545;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background-color: var(--color-bg-primary);
    color: var(--color-text-primary);
    line-height: 1.6;
}

.app-layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: 240px;
    background-color: var(--color-bg-secondary);
    border-right: 1px solid var(--color-border);
    display: flex;
    flex-direction: column;
    position: fixed;
    height: 100vh;
    z-index: 100;
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid var(--color-border);
}

.logo img {
    height: 32px;
}

.sidebar-nav {
    flex: 1;
    padding: 16px 0;
}

.nav-item {
    display: flex;
    align-items: center;
    padding: 12px 20px;
    color: var(--color-text-secondary);
    text-decoration: none;
    transition: all 0.2s;
}

.nav-item:hover, .nav-item.active {
    background-color: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.nav-item .icon {
    margin-right: 12px;
    font-size: 18px;
}

.main-content {
    flex: 1;
    margin-left: 240px;
    padding: 20px;
}

.btn {
    display: inline-block;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: background-color 0.2s;
}

.btn-primary {
    background-color: var(--color-accent);
    color: white;
}

.btn-primary:hover {
    background-color: var(--color-accent-hover);
}

.card {
    background-color: var(--color-bg-secondary);
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--color-border);
}

.card-poster {
    aspect-ratio: 2/3;
    background-color: var(--color-bg-tertiary);
}

.media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 20px;
}
```

### 5.1.5 Create JavaScript API Client

Create `public/assets/js/api-client.js`:
```javascript
class ApiClient {
    constructor(baseUrl = '') {
        this.baseUrl = baseUrl || window.location.origin;
        this.accessToken = localStorage.getItem('access_token');
    }

    async request(method, endpoint, data = null) {
        const headers = {
            'Content-Type': 'application/json',
        };

        if (this.accessToken) {
            headers['Authorization'] = `Bearer ${this.accessToken}`;
        }

        const options = {
            method,
            headers,
        };

        if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(`${this.baseUrl}${endpoint}`, options);

        if (response.status === 401) {
            // Try to refresh token
            const refreshed = await this.refreshToken();
            if (refreshed) {
                headers['Authorization'] = `Bearer ${this.accessToken}`;
                const retryResponse = await fetch(`${this.baseUrl}${endpoint}`, options);
                return this.handleResponse(retryResponse);
            }
        }

        return this.handleResponse(response);
    }

    async handleResponse(response) {
        const contentType = response.headers.get('content-type');
        const isJson = contentType && contentType.includes('application/json');
        const data = isJson ? await response.json() : await response.text();

        if (!response.ok) {
            throw new Error(data.error || data.message || 'Request failed');
        }

        return data;
    }

    async refreshToken() {
        const refreshToken = localStorage.getItem('refresh_token');
        if (!refreshToken) return false;

        try {
            const response = await fetch(`${this.baseUrl}/auth/refresh`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ refresh_token: refreshToken }),
            });

            if (response.ok) {
                const data = await response.json();
                this.accessToken = data.access_token;
                localStorage.setItem('access_token', data.access_token);
                if (data.refresh_token) {
                    localStorage.setItem('refresh_token', data.refresh_token);
                }
                return true;
            }
        } catch (e) {
            console.error('Token refresh failed:', e);
        }

        return false;
    }

    get(endpoint, params) {
        const query = params ? '?' + new URLSearchParams(params).toString() : '';
        return this.request('GET', endpoint + query);
    }

    post(endpoint, data) {
        return this.request('POST', endpoint, data);
    }

    put(endpoint, data) {
        return this.request('PUT', endpoint, data);
    }

    delete(endpoint) {
        return this.request('DELETE', endpoint);
    }
}

const api = new ApiClient();
```

### 5.1.6 Create Home Page Template

Create `public/templates/home/index.tpl`:
```smarty
{extends file="layouts/main.tpl"}

{block name="title"}Home - Phlex{/block}

{block name="main"}
<div class="home-page">
    <section class="hero">
        <h1>Welcome back, {$user.display_name|default:'User'}</h1>
        <p>What would you like to watch?</p>
    </section>

    {if $continue_watching}
    <section class="media-section">
        <h2 class="section-title">Continue Watching</h2>
        <div class="media-grid">
            {foreach $continue_watching as $item}
                {include file="partials/media_card.tpl" item=$item}
            {/foreach}
        </div>
    </section>
    {/if}

    <section class="media-section">
        <h2 class="section-title">Recently Added</h2>
        <div class="media-grid">
            {foreach $recently_added as $item}
                {include file="partials/media_card.tpl" item=$item}
            {/foreach}
        </div>
    </section>

    {foreach $libraries as $library}
    <section class="media-section">
        <h2 class="section-title">{$library.name}</h2>
        <div class="media-grid">
            {foreach $library.items as $item}
                {include file="partials/media_card.tpl" item=$item}
            {/foreach}
        </div>
        <a href="/library/{$library.id}" class="see-all">See all →</a>
    </section>
    {/foreach}
</div>
{/block}
```

### 5.1.7 Create Media Card Partial

Create `public/templates/partials/media_card.tpl`:
```smarty
<div class="media-card" data-id="{$item.id}" data-type="{$item.type}">
    <a href="/library/item/{$item.id}">
        <div class="card-poster">
            {if $item.metadata.poster_url}
                <img src="{$item.metadata.poster_url}" alt="{$item.name}" loading="lazy">
            {else}
                <div class="poster-placeholder">
                    <span class="icon">🎬</span>
                </div>
            {/if}
            
            {if $item.user_data.resume_position_ticks > 0}
            <div class="progress-bar">
                <div class="progress" style="width: {($item.user_data.resume_position_ticks / $item.metadata.runtime_ticks * 100)|round}%"></div>
            </div>
            {/if}
        </div>
        <div class="card-info">
            <h3 class="card-title">{$item.name}</h3>
            {if $item.metadata.year}
                <span class="card-year">{$item.metadata.year}</span>
            {/if}
        </div>
    </a>
</div>
```

---

## Verification

1. Verify directory structure:
```bash
ls -la /home/sites/phlex/public/templates/
ls -la /home/sites/phlex/public/assets/
```

2. Check template syntax (if Smarty is available):
```bash
cd /home/sites/phlex
find public/templates -name "*.tpl" | head -10
```

---

## Git Workflow

```bash
cd /home/sites/phlex
git checkout -b step-5.1-web-portal-setup
git add .
git commit -m "Step 5.1: Set up web portal templates and assets"
unset GITHUB_TOKEN
gh pr create --title "Step 5.1: Web Portal Setup" --body "Sets up the web portal structure with Smarty templates and CSS."
gh pr merge --squash --delete-branch
git checkout master && git pull
```

---

## Next Step

After completing and merging this step, proceed to **Step 5.2: Web API Endpoints** (`plans/phase-5/step-5.2-web-api-endpoints.md`).
