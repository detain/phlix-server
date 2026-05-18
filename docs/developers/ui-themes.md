# UI Theme Plugin Guide

This guide explains how to create a custom `ui-theme` plugin for the Phlex web portal.

## Overview

A `ui-theme` plugin is a declarative plugin type that provides custom CSS and optional JavaScript to restyle the Phlex web portal. Unlike other plugin types, ui-theme plugins do **not** subscribe to runtime events — they are purely asset bundles with a manifest.

## Manifest Structure

A ui-theme plugin requires a `plugin.json` manifest with the `ui-theme` type and a `theme` key containing theme metadata:

```json
{
    "name": "phlex-plugin-my-theme",
    "version": "1.0.0",
    "phlex_min_server_version": "0.14.0",
    "type": "ui-theme",
    "entry": "Phlex\\Themes\\MyTheme\\Plugin",
    "theme": {
        "id": "my-custom-theme",
        "name": "My Custom Theme",
        "css": "dist/theme.css",
        "js": "dist/theme.js",
        "thumbnail": "screenshots/preview.png",
        "dark": true
    }
}
```

### Theme Manifest Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | string | Yes | Unique theme identifier (e.g., `my-custom-theme`) |
| `name` | string | Yes | Human-readable theme name displayed in the admin UI |
| `css` | string | Yes | Path to the theme's CSS file (relative to plugin root) |
| `js` | string | No | Path to optional JavaScript bundle |
| `thumbnail` | string | No | Path to preview image (1:2 aspect ratio recommended) |
| `dark` | boolean | No | Whether this is a dark theme (UI hint for client apps) |
| `version` | string | No | Theme version (defaults to `1.0.0`) |

## Entry Class

The entry class must implement `Phlex\Theming\ThemePluginInterface`:

```php
<?php

declare(strict_types=1);

namespace Phlex\Themes\MyTheme;

use Phlex\Theming\ThemePluginInterface;

class Plugin implements ThemePluginInterface
{
}
```

The entry class itself is not instantiated during normal operation — the ThemeRegistry reads the theme data directly from the manifest. Implementing the interface simply marks the plugin as discoverable.

## CSS Structure

Your theme's CSS should define CSS custom properties (variables) to customize the Phlex appearance. The built-in themes use these variables:

### Core Variables

```css
:root {
    /* Background Colors */
    --bg-primary: #121212;
    --bg-secondary: #1e1e1e;
    --bg-tertiary: #2d2d2d;
    --bg-elevated: #333333;

    /* Text Colors */
    --text-primary: #e8e8e8;
    --text-secondary: #a0a0a0;
    --text-muted: #6b6b6b;

    /* Accent Colors */
    --accent-primary: #6366f1;
    --accent-secondary: #818cf8;
    --accent-hover: #4f46e5;

    /* Borders */
    --border-color: #3d3d3d;
    --border-light: #4a4a4a;

    /* Buttons */
    --btn-primary-bg: #6366f1;
    --btn-primary-color: #ffffff;
    --btn-secondary-bg: #3d3d3d;
    --btn-secondary-color: #e8e8e8;

    /* Cards */
    --card-bg: #1e1e1e;
    --card-border: #3d3d3d;
    --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.4);

    /* Form Inputs */
    --input-bg: #1e1e1e;
    --input-border: #3d3d3d;
    --input-focus: #6366f1;

    /* Progress Bar */
    --progress-bg: #2d2d2d;
    --progress-fill: #6366f1;

    /* Border Radius */
    --radius-sm: 4px;
    --radius-md: 8px;
    --radius-lg: 12px;

    /* Shadows */
    --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
}
```

### Selector Overrides

Beyond CSS variables, you can override specific selectors:

```css
/* Typography */
h1, h2, h3 { color: var(--text-primary); }

/* Media Cards */
.media-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

/* Scrollbar */
::-webkit-scrollbar-thumb { background: var(--scrollbar-thumb); }
```

## Directory Structure

```
phlex-plugin-my-theme/
├── composer.json
├── plugin.json
├── src/
│   └── Plugin.php          # Entry class
├── dist/
│   ├── theme.css           # Compiled CSS
│   └── theme.js            # Optional JS bundle
└── screenshots/
    └── preview.png         # Theme preview (1:2 aspect ratio)
```

## Packaging

Run `composer install --no-dev` to install dependencies into the plugin's `vendor/` directory, then zip the entire plugin folder for distribution.

## Installation

1. Upload the plugin through **Admin → Plugins → Add New**
2. Activate the theme in **Admin → Appearance → Themes**
3. Preview themes using the live preview iframe
4. Click "Activate" to apply the theme

## Per-User Theme Selection

Users can select their preferred theme in their profile settings. The theme is stored per-profile in `user_profiles.active_theme_id`.

## See Also

- [Developer Guide](../plugins/developer-guide.md) — Full plugin development guide
- [Built-in Themes](../../public/assets/css/themes/) — Reference implementations
