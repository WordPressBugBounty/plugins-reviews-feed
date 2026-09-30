# About Us Page Package

Shared About Us page for all Smash Balloon plugins. Renders a unified About Us admin page with team info, WPChat promotion, other SB plugins, and recommended third-party plugins.

## Quick Start

```php
use Smashballoon\Framework\Packages\AboutUs\AboutUsManager;

AboutUsManager::init([
    'plugin_slug'    => 'instagram-feed',
    'plugin_name'    => 'Smash Balloon Instagram Feed',
    'plugin_version' => SBIVER,
    'plugin_file'    => __FILE__,
    'menu_parent'    => 'sb-instagram-feed',
    'page_slug'      => 'sbi-about-us',
]);
```

## Configuration

| Key | Required | Default | Description |
|-----|----------|---------|-------------|
| `plugin_slug` | Yes | - | Plugin identifier (e.g. `instagram-feed`) |
| `plugin_name` | Yes | - | Display name |
| `plugin_version` | No | `''` | Current version (used for asset cache busting) |
| `plugin_file` | Yes | - | Main plugin file path (`__FILE__`) |
| `menu_parent` | Yes | - | Parent menu slug for submenu registration |
| `page_slug` | Yes | - | Submenu page slug (preserves existing URLs) |
| `capability` | No | `manage_options` | Required capability |
| `menu_position` | No | `4` | Menu item position |

## Architecture

- **AboutUsManager** - Static entry point; registers shared AJAX handlers and the single site-wide refresh cron once
- **AboutUsPage** - Per-plugin instance handling menu registration and rendering
- **ContentProvider** - Remote fetch with a shared cached option and bundled-file fallback
- **PluginDetector** - Centralized plugin install/activate state detection
- **AjaxHandler** - Shared install/activate AJAX endpoints

## Content API

Content is fetched from a single canonical endpoint, `https://plugin.smashballoon.com/about-us.json` (filterable via `sbc_about_us_remote_url`), and cached in one shared option (`sbc_about_us_content`) for the whole site because the content is identical across Smash Balloon plugins.

Refresh happens in two ways, mirroring the notifications feed:

- **Cron** — a single `sbc_about_us_update` event on the `twicedaily` schedule, registered once in `AboutUsManager::boot()` (lazily, no per-plugin activation hook).
- **Lazy on-load** — when the About Us page is viewed and the cached content is older than 12 hours, it refreshes synchronously. The fetch adds an hourly `?v=YmdH` query arg so a backend publish is not hidden by the Cloudflare edge cache (4 hours).

`ContentProvider::refresh()` resolves content in this order: freshly fetched remote → last-good cached option → the bundled `assets/about-us-data.json`. On a failed fetch the last-good data is kept and the timestamp is still bumped, so an unavailable endpoint is not re-hit on every page load.

### Consent gating (WP.org compliance)

The remote fetch is gated on the shared data-sharing consent flag via `ConsentManager::is_dsc_enabled()` (see the `Consent` package). When consent is **off**, `ContentProvider` serves the bundled `assets/about-us-data.json` only — it never contacts the remote endpoint or reads the cached remote copy. When consent is **on**, the remote fetch/cache/fallback flow above applies. The gate can be overridden with the `sbc_about_us_remote_allowed` filter; if the Consent package is unavailable it defaults to **not** phoning home.

### Cleanup on uninstall

The cron event and the `sbc_about_us_content` option are shared by every Smash Balloon plugin, so nothing removes them automatically. Call `ContentProvider::uninstall()` from each consumer's uninstall routine. If another Smash Balloon plugin is still active, it reschedules the cron on its next load.
