# Blocks Package

Gutenberg blocks, Elementor widgets, and a shared React component library for Smash Balloon feed plugins.

## Structure

```
Packages/Blocks/
  src/
    shared/           Reusable React components (npm package source)
      feed-blocks/    FeedCTA, FeedToolbar, utils, elementor
      faux-blocks/    Faux block modals for uninstalled plugins
      icons.js        Platform icons
      styles.css      Shared styles
      index.js        NPM package entry (exports everything)

    feed-blocks/      Gutenberg feed block registration (unified)
      index.js        Single entry — registers all active feed blocks
      create-edit.js  Factory function for block edit components
      */block.json    Per-feed block metadata

    elementor/        Elementor feed widgets (unified)
      index.js        Single entry — inits all active elementor widgets

    recommended/      Recommended blocks for uninstalled plugins
      index.js        Entry point
      blocks.js       Block definitions
      edit.js         Edit component
      preview.js      Preview component

  dist/               Webpack output (3 bundles)
  lib/                Microbundle output (npm package)
  css/                Shared CSS
  js/                 Shared JS handlers
  *.php               PHP classes
```

## Setup

```bash
npm install
```

## Build

```bash
# Webpack — produces 3 bundles in dist/
npm run build

# Microbundle — produces npm package in lib/
npm run build:lib

# Both
npm run build:all
```

## Development

```bash
# Watch mode for webpack
npm start

# Watch mode for microbundle
npm run start:lib
```

## Feed Blocks

Feed blocks are registered via `SB_Feed_Blocks_Registry`. Each feed plugin registers its block config, and a single unified script (`dist/sb-feed-blocks.js`) handles all of them.

The registry is driven by `window.sbFeedBlocksRegistry`, localized by PHP. Each plugin's block class overrides `get_feed_block_id()` and `get_init_function()` in `SB_Feed_Block` to opt in.

## Elementor Widgets

Same pattern — `SB_Feed_Blocks_Registry::register_elementor_widget()` collects configs, and `dist/sb-elementor-editor.js` initializes all widgets from `window.sbElementorRegistry`.

## Recommended Blocks

Recommended blocks suggest installing other Smash Balloon plugins from within the Gutenberg editor. They are defined in `src/recommended/blocks.js`.

### Usage

```php
use Smashballoon\Framework\Packages\Blocks\RecommendedBlocks;

$recommended_blocks = new RecommendedBlocks();
$recommended_blocks->setup();
```

### Adding a new recommended block

Add a block object to the `recommendedBlocks` array in `src/recommended/blocks.js`:

```js
{
    name: 'instagram-feed',
    title: __('Instagram Feed', 'smashballoon'),
    description: __('Display your Instagram feeds.', 'smashballoon'),
    pluginPath: 'instagram-feed/instagram-feed.php',
    proPluginPath: 'instagram-feed-pro/instagram-feed.php',
    pluginDescription: __('...', 'smashballoon'),
    keywords: [
        __('Instagram', 'smashballoon'),
        __('Photos', 'smashballoon'),
        __('Social Media', 'smashballoon'),
    ],
}
```

## NPM Package

The shared React components are published as `@awesomemotive/blocks` via GitHub Packages. The `lib/` directory is the npm distribution.
