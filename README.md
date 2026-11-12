# Divi Migration

> Convert Divi Builder content to blocks.

Divi Migration turns the `[et_pb_*]` shortcodes of a Divi site into blocks, so the site can run on a block theme with Divi switched off. Each Divi module has a conversion rule. A WP-CLI command converts the whole site on the server: each block gets the markup its own `save()` writes in the editor, so blocks open valid. Divi's tablet and phone settings become per-viewport block styles.

Use it on a copy of the site during a migration, and deactivate it afterwards: converted content does not need it.

It does not build the theme. Build the block theme first, by hand or with the [agent skill](#agent-skill).

> [!WARNING]
>
> `convert` rewrites the content of every post it converts.
>
> - **Back up the database first.** Run `wp db export` before the first `convert`.
> - **Run it on a copy of the site,** not on production, until the result is checked.
> - **`reset` restores a post** from the original content that `convert` keeps in post meta. It needs the plugin active.

## Requirements

- WordPress 7.1 or higher
- PHP 8.2 or higher
- [WP-CLI](https://wp-cli.org/)
- The Divi 4.9.11 theme files on the site (Divi 4 shortcode content). Divi 5 is not supported.
- A block theme for the site, built before the migration. `site` writes the menus into the active theme, so activate it first.
- Optional block plugins for some targets: see [Target blocks](#target-blocks).

`analyze`, `convert` and `preview` warn when the installed Divi version or the newest `_builder_version` in the content differs from the tested version. The installed version is the builder version that the Divi or Extra theme bundles (`ET_BUILDER_PRODUCT_VERSION` in `includes/builder/functions.php`), read from the theme files, so an inactive theme counts.

## Installation

### Manual Installation

1. Download the latest release ZIP from the [Releases page](https://github.com/s3rgiosan/divi-migration/releases/latest).
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin area.
3. Upload the ZIP file and click Install Now.
4. Activate the plugin.

To install from the command line, pass the ZIP path or URL to WP-CLI:

```bash
wp plugin install <zip> --activate
```

### Install with Composer

To include this plugin as a dependency in your Composer-managed WordPress project:

1. Add the plugin to your project using the following command:

```bash
composer require s3rgiosan/divi-migration
```

2. Run `composer install`.
3. Activate the plugin from your WordPress admin area or using WP-CLI.

### Clone the repository

```bash
git clone https://github.com/s3rgiosan/divi-migration.git wp-content/plugins/divi-migration
```

## Quick start

Run these on a copy of the site.

1. Count the Divi modules in use:

   ```bash
   wp divi-migration analyze
   ```

2. Activate the block theme.
3. Set the logo and carry over the menus. Pass the Divi theme or its child theme:

   ```bash
   wp divi-migration site --theme=<divi-theme-or-child>
   ```

4. Check the result without saving:

   ```bash
   wp divi-migration convert --dry-run
   ```

5. Convert the posts:

   ```bash
   wp divi-migration convert
   ```

The [migration map](#migration-map) is optional. Pass it with `--map=<file>` to `convert` when the defaults do not fit.

## Commands

| Command | Does |
|---|---|
| `wp divi-migration analyze` | Counts the Divi modules in use, the posts that use them, and their settings. Also prints the installed, tested and content builder versions. |
| `wp divi-migration list` | Lists posts with Divi content, and posts converted from Divi content, with a `converted` column. Posts converted from classic content are not listed. |
| `wp divi-migration preview <id>` | Prints the block specs and the conversion report for a post. It creates no forms. |
| `wp divi-migration convert [<id>...] [--classic] [--dry-run] [--force]` | Converts posts and saves the blocks. `--dry-run` reports without saving. `--force` saves posts with missing blocks, empty results and posts edited after conversion. With a form target, run it with `--user=<admin>`. |
| `wp divi-migration classes [<id>...]` | Lists the classes the converted markup keeps that the map does not translate, with their uses and posts. It reads Divi content (default post types `post,page,product`) through the rules and classic content (`post,page`) through the HTML conversion, and warns about a post ID whose post type or status is not selected. |
| `wp divi-migration shortcodes [--post_type=<types>] [--status=<statuses>]` | Lists the shortcode tags in content and widgets (Divi `et_pb_*` modules left out), with their uses, places and the code that registers each tag on this site. An empty `registered_by` means the tag does not render on this site. Run it on the Divi site, or on the copy before the theme switch, to see the tags that Divi or its plugins register: those stop rendering without them. |
| `wp divi-migration markup <id> [--portable]` | Prints block markup without saving: a post, Theme Builder layout or Library item; `--content=<file>` for Divi content in a file; `--widget-area=<id>` for a widget area (an error when the area is unknown or has no widgets); `--specs=<file>` for a JSON list of block specs, which prints a JSON list with the markup of each spec. `--portable` drops attachment IDs from media blocks and makes upload URLs root-relative, for theme files; it applies to every source, `--specs` included. With a form target it creates forms: run it with `--user=<admin>`. |
| `wp divi-migration site [--theme=<theme>] [--force]` | Sets the Site Logo from Divi's logo and carries the menus over (see [Menus](#menus)). It keeps a logo and menu locations that are already set and reports them; `--force` replaces the logo and, with classic menus, the menus of locations already set. `--theme` is required when the active theme is not Divi: the command stops with an error without it. |
| `wp divi-migration reset [<id>...] [--all] [--force]` | Restores the original content of converted posts. It needs IDs or `--all`, which restores every post with a backup. Posts keep their status, dates and modified dates. A post edited after conversion is skipped; `--force` restores it and discards the edits. The backup stays when the restored content does not match it. |

Run `wp help divi-migration <command>` for the options.

## Converting posts

`convert` converts every post with Divi content, or the post IDs passed. Every conversion backs up the original content first. The backup stays in post meta: converting again starts from it, and `wp divi-migration reset` restores it. Each post keeps its status, its dates and its modified date: a scheduled post whose date has passed stays scheduled. Products built only from WooCommerce modules get their original description back, as classic content.

`convert --classic` converts classic content: posts without Divi modules or blocks, such as blog posts written in the classic editor, through the same HTML conversion as text modules. A post with an empty content is not classic content, and a post whose backup holds classic content counts as classic. It converts posts and pages by default; pass `--post_type` to include products.

A post ID is skipped when its content is not Divi content (or not classic content with `--classic`), when its post type or status is not selected by `--post_type` and `--status`, and when it is a revision. A converted post edited after its conversion is skipped, and so is a post whose conversion is empty.

A post is skipped when its blocks need a block type that is not registered, or a static block the converter has no save for (see Block markup). The table lists, per post, the modules that had no rule (`fallback`) and the missing blocks.

`--force` saves these posts too: a missing block becomes a hidden placeholder, an empty conversion is saved, and a post edited after its conversion is converted again, which discards the edits.

A post saves unfiltered when its author has the `unfiltered_html` capability, and block custom CSS saves when the author has `edit_css`. The posts of other authors go through the editor's filters, and a post whose saved content differs from the markup gets the note `filtered on save`.

A post that fails gets the status `failed: <reason>`. The other posts still convert, and the command exits with code 1.

`reset` needs post IDs or `--all`. It skips a post edited after its conversion unless `--force` is passed, and exits with code 1 when a post is not found or fails to restore.

Deactivating or deleting the plugin keeps its data, so the original content stays recoverable. Each converted post keeps the `_s3s_divi_migration_content`, `_s3s_divi_migration_converted_at` and `_s3s_divi_migration_markup_hash` meta, and a post converted from Divi content also keeps `_s3s_divi_migration_builder`, and the `s3s_divi_migration_forms` option stays. `reset` removes a post's meta when it restores the post. Delete the rest by hand once the migration is final.

### Migration map

Project decisions live in a JSON file passed with `--map`. All keys optional:

```json
{
  "gutter": 3,
  "classNames": { "section": "is-style-section", "row": "is-style-row" },
  "imageSizes": { "card": "post-card", "wide": "post-wide" },
  "sidebarPart": "sidebar",
  "targets": { "carousel": "auto", "accordion": "core-accordion", "table": "flexible-table", "form": "ws-form" },
  "menus": "classic",
  "emptyParagraphs": "keep",
  "colors": { "#336699": "accent" },
  "fonts": { "Open Sans": "body" },
  "modules": {
    "et_pb_social_media_follow": "skip"
  },
  "shortcodes": {
    "et_bloom_inline": [
      { "attrs": { "optin_id": "optin_4" }, "markup": "<!-- wp:shortcode -->[mc4wp_form id=\"1\"]<!-- /wp:shortcode -->" },
      { "attrs": { "optin_id": "optin_5" }, "skip": true },
      "skip"
    ]
  },
  "classes": {
    "big-button": "is-style-big",
    "/^(big|small)[A-Za-z0-9]+$/": "",
    "card card--dark": "is-style-card-dark",
    "highlight": { "backgroundColor": "accent" },
    "/^(p|s|li|ul)\\d+$/": ""
  }
}
```

- `gutter`, `classNames`, `imageSizes`, `sidebarPart`, `targets`, `menus`: the settings (see Settings). `gutter` is a whole number from 1 to 4, `classNames`, `imageSizes` and `sidebarPart` take strings, and `targets` takes `auto` or a registered target name for each known kind (`carousel`, `accordion`, `table`, `form`). The map is rejected with an error otherwise.
- `emptyParagraphs`: `keep` (default) or `drop` the empty paragraphs classic content uses as spacers. Keep them while matching the old site.
- `colors`, `fonts`: palette and font family slugs for Divi values the theme does not match exactly. Exact matches need no entry. Values are strings.
- `modules`: per shortcode tag, `"skip"` drops the module, `{ "markup": … }` puts block markup (or HTML) in its place.
- `shortcodes`: per shortcode tag, what replaces a shortcode that stands alone in a paragraph: `"skip"`, `{ "markup": … }`, `{ "skip": true }`, or a list of those, each with an optional `attrs` object the shortcode's attributes must match. A `{ "skip": true }` entry drops the shortcodes it matches, so `{ "attrs": { … }, "skip": true }` skips only those. For shortcodes that stop working without Divi or its plugins. Bloom's `[et_bloom_inline]` converts on its own through the `form` target.
- `classes`: per class, class set (all classes present) or pattern (between slashes), the classes that replace it, `""` to drop it, or block attributes to merge with an optional `className`. A block keeps one block style: a mapped `is-style-*` replaces the one it had. `wp divi-migration classes` lists what is left to translate. A key cannot be empty; `{}` is a valid value.

### Markup for templates and patterns

Theme Builder layouts, Library items, widget areas and child theme templates become template parts and patterns, not posts. `wp divi-migration markup` prints their block markup:

```bash
wp divi-migration markup 123 > parts/footer.html
wp divi-migration markup --widget-area=sidebar-1 > parts/sidebar.html
```

## Menus

Divi uses classic menus. The `menus` setting decides how they carry over:

- `classic` (default): the menus stay classic, edited under Appearance → Menus. `wp divi-migration site` assigns each one to the same location in the active theme and lists the locations the theme must register. Menu modules convert into Navigation blocks bound to their location (`__unstableLocation`). Core renders that attribute only with the Gutenberg plugin, so the theme fills a location-bound Navigation block with the location's classic menu as it renders (`render_block_data`), and returns false from `wp_navigation_should_create_fallback`, so the editor does not turn the classic menus into Navigation posts.
- `navigation`: each menu is copied once into a Navigation post (a block menu, edited in the Site Editor). Menu modules point to it with `ref`. Running `site` again overwrites those posts.

Run `wp divi-migration site` with the block theme active and before converting content, so menu modules find their location or Navigation post. Set the mode with `--menus`, or with `menus` in the map.

## Target blocks

Rules target core blocks. Sliders, accordions and tables can convert into different block plugins; each has **targets**:

| Module | Targets, in order of preference | Map value |
|---|---|---|
| Sliders (`et_pb_slider`, `et_pb_fullwidth_slider`) | [Outstand Carousel](https://github.com/pixelalbatross/outstand-carousel), Cover (one per slide, stacked) | `outstand-carousel`, `core-cover` |
| Accordions and toggles (`et_pb_accordion`, `et_pb_toggle`) | [Outstand Accordion](https://github.com/pixelalbatross/outstand-accordion), Accordion | `outstand-accordion`, `core-accordion` |
| Tables in module HTML | Table, [Flexible Table Block](https://wordpress.org/plugins/flexible-table-block/) | `core-table`, `flexible-table` |
| Contact forms (`et_pb_contact_form`) and Bloom opt-ins (`[et_bloom_inline]`) | [WS Form](https://wordpress.org/plugins/ws-form/) | `ws-form` |

The `ws-form` target creates one WS Form form per source form, with its fields, button text, success message and recipient, and reuses it when the conversion runs again (the `s3s_divi_migration_forms` option keeps the IDs). Bloom opt-ins keep their title, text, image, layout, fonts and colors as blocks around the form, whose block gets the `optin` or `optin-light` block style. WS Form creates forms only for a user who may: run `convert` and `markup` with `--user`. The forms keep their submissions; connect them to the email service list by hand.

By default (`auto`) the first target whose blocks are registered wins, so a site without the block plugin gets the core target. The conversion report names the target used for each module.

Tables convert to the core Table block unless the map picks `flexible-table`. Core keeps each cell's content, tag, spans and text alignment, as the editor does, plus the caption the editor drops. Flexible Table keeps the inline styles of the table, its cells and its caption, each cell's class, ID, headers and scope, and the automatic layout of HTML tables.

The layout blocks carry the class names `is-style-section`, `is-style-section-fullwidth`, `is-style-row`, `is-style-row-inner`, `is-style-text` and `is-style-testimonial` (the Testimonial module's group). Register block style variations with those names in the theme to give them Divi's spacing.

Blog posts show their featured image in the `post-card` size (grid lists) or the `post-wide` size (full-width lists). The `imageSizes` map key (`card` and `wide`) sets other slugs. The theme registers the sizes with Divi's dimensions and crop:

```php
add_image_size( 'post-card', 400, 250, true );
add_image_size( 'post-wide', 1080, 675, true );
```

Run `wp media regenerate --only-missing --skip-delete --yes` once, so uploads that exist already get these sizes and keep their other thumbnail files. Until then WordPress serves the full image.

## Block markup

Blocks are written on the server. Static blocks get the markup their `save()` writes in the editor, block support classes and inline styles included; dynamic blocks get their comment delimiters. A registered static block that has no save in `Saves` makes the post skip, so each such block needs a save; the save may return empty markup around the inner blocks. Raw HTML from text modules and classic content goes through a port of the editor's raw handler: paragraphs, headings, lists, images, quotes, separators, tables, videos and embeds become their blocks, and shortcodes on their own line too: `[caption]` an Image, `[gallery]` a Gallery, `[audio]`, `[video]` and `[embed]` their blocks, any other a Shortcode block. The classic `<!--more-->` (with custom text and `<!--noteaser-->`) and `<!--nextpage-->` comments become More and Page Break blocks. Anything else becomes a Custom HTML block. Gallery images get their URL, alternative text, caption and link from the attachment, as the editor sets them when it opens the gallery.

Beyond the editor:

- Inline styles on paragraphs, headings and list items become block attributes. Colors, font sizes and font families that match the theme's presets become their preset attributes (`has-*` classes); other values go to the block's styles.
- A `<span>` that only colors text becomes the editor's text color format (`<mark class="has-inline-color">`), with the palette color's class when the color is in the palette.
- Divi's `[button]` shortcode becomes a Buttons block with Divi's button classes (`big-button`, `bigpink`), for the theme to style.
- Global modules merge like Divi: the page's settings, with the Library item's synced settings on top.
- Divi's Global Presets (`builder_global_presets` in the `et_divi` option) fill the settings a module does not set: each module uses the preset its `_module_preset` names, or its module type's default preset when that preset does not exist.
- Divi's minimum heights and tablet and phone background images carry over.
- Divi's defaults apply when a module does not set a value: blog author and pagination, post title image, author and comments link, slider centered text and inherited button styles, the call to action's accent background, light text on dark background layouts, toggle and accordion title settings and default border, number counter title level, and each counter's track color. An image's lightbox applies before its link, blurb links open in a new window when Divi says so, and justified text becomes left-aligned text with a note in the report. Width and max-width settings use the smaller value, and a single-column row keeps its column attributes.
- Block markup inside a Divi text module stays as blocks, and text outside modules stays in place. Linked images in figures become Image blocks with their link, an `id` becomes the block's anchor, list text before a nested list is kept, tables with several sections convert, and entities in top-level text are kept.
- A video with `<source>` elements becomes a Custom HTML block. A classed `<div>` becomes a block with its ID and styles, or a Custom HTML block. Comments inside shortcodes are dropped, and a `[caption]` inside a paragraph converts.
- Separator colors, drop cap alignment, the button text alignment class, cover gradients and cover videos save as the editor writes them, and a column width of zero sets no flex basis.
- Presets match colors after normalizing them, and a transparent tablet or phone color is kept when it differs from the desktop color.

The converter ships saves for the static core blocks its rules and targets use. A block plugin whose blocks save markup adds a save per block with `s3s_divi_migration_block_saves`: a callable that takes the block's attributes (with defaults) and its `Serializer\Supports`, and returns the markup before and after the inner blocks.

```php
use S3S\WP\DiviMigration\Serializer\Html;
use S3S\WP\DiviMigration\Serializer\Supports;

add_filter(
	's3s_divi_migration_block_saves',
	function ( array $saves ): array {
		$saves['my/card'] = fn( array $attributes, Supports $supports ) => [
			Html::open( 'div', $supports->props( $attributes ) ),
			'</div>',
		];
		return $saves;
	}
);
```

## Customization

### Conversion rules

The `s3s_divi_migration_rules` filter receives the rules, keyed by shortcode tag. A rule implements `S3S\WP\DiviMigration\Rules\Rule`:

```php
add_filter(
	's3s_divi_migration_rules',
	function ( array $rules ): array {
		$rules['et_pb_slider'] = new My_Slider_Rule();
		return $rules;
	}
);
```

### Targets

Pick a target per kind with `targets` in the map, or through the settings:

```php
add_filter(
	's3s_divi_migration_settings',
	function ( $settings ) {
		$settings->targets = [
			'accordion' => 'core-accordion',
		];
		return $settings;
	}
);
```

Or add a target for another block plugin. The rule reads the Divi module into data (`CarouselData` with its `Slide`s, or `AccordionData` with its `AccordionItem`s), with the content blocks of each slide or item ready-made; the target only builds the outer blocks. A carousel target implements `S3S\WP\DiviMigration\Targets\CarouselTarget`:

```php
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Targets\CarouselData;
use S3S\WP\DiviMigration\Targets\CarouselTarget;

class My_Carousel_Target implements CarouselTarget {

	public function blocks(): array {
		return [ 'my/carousel', 'my/slide' ];
	}

	public function build( CarouselData $carousel ): array {
		$slides = [];

		foreach ( $carousel->slides as $slide ) {
			$slides[] = Spec::block( 'my/slide', [ 'style' => $slide->style ], $slide->blocks );
		}

		return [ Spec::block( 'my/carousel', [ 'autoplay' => $carousel->autoplay ], $slides ) ];
	}
}

add_filter(
	's3s_divi_migration_targets',
	function ( array $targets ): array {
		// Put the target first, so `auto` prefers it when its blocks are registered.
		$targets['carousel'] = [ 'my-carousel' => new My_Carousel_Target() ] + $targets['carousel'];
		return $targets;
	}
);
```

An accordion target implements `AccordionTarget` the same way, and a table target `TableTarget`, which builds one block from `TableData` (sections of `TableCell`s, the table and caption attributes). A form target implements `FormTarget` and registers under the `form` kind: its `build( FormData $form, bool $create = true )` method rebuilds a `FormData` (key, label, fields, submit text, success message, email addresses, label visibility and submit button width) in the form plugin and returns the block that shows it, or `null` when it cannot create the form; its `error()` method returns why the last form could not be created. `Targets\Css::color()` turns a preset reference into CSS, and `Targets\Css::accordion_states()` writes the closed and open state colors for an item.

### Settings

The `s3s_divi_migration_settings` filter receives the settings, after the map: the default gutter, the class names of the layout blocks, the image size slugs of blog post images, the sidebar template part slug, the target per kind (`carousel`, `accordion`, `table` and `form`) and the menu mode.

## Agent skill

The `skill/` folder holds `wp-divi-migration`, a Claude Code skill that guides a whole Divi to block theme migration and runs the commands of this plugin. It needs the `wp-design-to-block-theme` skill from [s3rgiosan/wp-skills](https://github.com/s3rgiosan/wp-skills). The plugin ZIP leaves it out.

See [skill/README.md](skill/README.md) to install it.

## Tests

`tests/e2e/serializer.spec.js` checks the server markup against the block editor for a set of fabricated block specs: each must parse valid, into the same blocks and attributes as the editor's markup. It runs against an existing site with the plugin active:

```bash
npm install && npx playwright install chromium-headless-shell
WP_BASE_URL=https://example.test WP_CLI="wp --path=/path/to/wordpress" npm run test:e2e
```

Environment variables:

- `WP_BASE_URL`: the site URL (default `http://localhost:8888`).
- `WP_USERNAME`, `WP_PASSWORD`: the administrator login (default `admin` / `password`).
- `WP_CLI`: the command that runs WP-CLI on the site (default `wp`), such as `wp --path=/var/www/html` or a container wrapper.
- `DIVI_MIGRATION_SPECS`: the fixtures path as WP-CLI reads it (default `wp-content/plugins/divi-migration/tests/e2e/fixtures/specs.json`, relative to the WordPress root).
- `DIVI_MIGRATION_SKIP_BLOCKS`: comma-separated block names whose specs may be skipped because the editor does not register them.

A spec for a block the editor does not register fails the test, unless the block is in `DIVI_MIGRATION_SKIP_BLOCKS`. The fixtures use `outstand/*` blocks (Outstand Carousel and Outstand Accordion), `flexible-table-block/table` and the `core/accordion` blocks. Install those plugins, or list the blocks to skip:

```bash
DIVI_MIGRATION_SKIP_BLOCKS="outstand/carousel,outstand/slides,outstand/slide,outstand/carousel-navigation,outstand/carousel-pagination,outstand/accordion,outstand/accordion-item,outstand/accordion-header,outstand/accordion-icon,outstand/accordion-panel,flexible-table-block/table" npm run test:e2e
```

The PHPUnit tests in `tests/phpunit/` run the `convert` and `reset` commands, the raw handler and the Divi parser against fabricated content. They need a test database and the WordPress test library. The `wp-phpunit/wp-phpunit` development package provides the library. Install the development dependencies, then run the tests on a WP Container site, or point `WP_PHPUNIT__TESTS_CONFIG` to a `wp-tests-config.php` that sets `ABSPATH` to a WordPress core directory and defines the database constants. `.github/workflows/tests.yml` shows a complete config. `WP_TESTS_DIR` points to another test library and takes priority.

```bash
composer install
wpc test <site>
# or
WP_PHPUNIT__TESTS_CONFIG=/path/to/wp-tests-config.php composer test
```

The tests need the development packages in `vendor/`. After the tests, restore a runtime-only `vendor/` before you commit it:

```bash
composer install --no-dev -o
```

If development packages remain in `vendor/`, remove them:

```bash
git clean -fd vendor && git checkout -- vendor/composer/
```

## Changelog

All notable changes to this project are documented in [CHANGELOG.md](https://github.com/s3rgiosan/divi-migration/blob/main/CHANGELOG.md).

## License

This project is licensed under the [GPL-3.0-or-later](https://spdx.org/licenses/GPL-3.0-or-later.html). See the [LICENSE](LICENSE) file for details.