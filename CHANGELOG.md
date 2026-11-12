# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-11-12

### Added

- WP-CLI commands `analyze`, `list`, `preview`, `convert`, `classes`, `shortcodes`, `markup`, `site` and `reset` inspect Divi content, convert it, and restore converted posts.
- `convert` converts a whole site on the server, or the post IDs passed, and `--dry-run` reports without saving.
- `convert --classic` converts classic content, such as blog posts written in the classic editor, and handles posts and pages by default; `--post_type` adds other post types, such as products.
- `convert` skips a post ID whose content is not Divi content, whose post type or status `--post_type` and `--status` do not select, or that is a revision.
- `convert` skips a converted post edited after its conversion and a post whose conversion is empty, and `--force` converts these posts too.
- `convert` saves a post with missing blocks only with `--force`, and writes a hidden placeholder for each missing block.
- List options such as `--post_type` and `--status` ignore spaces and empty items, and stop with an error when no item is left.
- A post that fails to convert gets the status `failed: <reason>`, the other posts still convert, and the command exits with code 1.
- A post ID that does not exist gets the status `failed: not found` in the `convert` and `reset` output.
- CLI loops over large sites empty the in-memory object cache every 100 posts, so memory use stays flat.
- `convert` backs up every post before it saves, so a post can be converted again or restored, and each post keeps its original `_et_pb_use_builder` value.
- `reset` restores the original content and the original `_et_pb_use_builder` value, or deletes that meta when the post had none.
- `reset` needs post IDs or `--all`, skips a post edited after its conversion unless `--force` is passed, and exits with code 1 when a post is not found or fails to restore.
- `reset` deletes the backup only after the saved content matches it.
- `convert` and `reset` keep each post's status, dates and modified date, so a scheduled post whose date has passed stays scheduled.
- A save keeps the page template of the post, and does not schedule pingbacks or enclosure scans.
- `convert` saves unfiltered HTML and block custom CSS only for authors who may save them, and adds the note `filtered on save` to a post whose saved content differs from the markup.
- Products built only from WooCommerce modules get their original description back.
- Divi Builder shortcodes convert to blocks through one filterable rule per module, and the `s3s_divi_migration_rules` filter replaces or adds rules.
- Rules cover sections, rows, columns, text, images, buttons, code, menus, sliders, blurbs, calls to action, testimonials, number and bar counters, video, post titles, blog lists, sidebars, accordions, toggles and contact forms.
- Divi's tablet and phone settings convert to per-viewport block styles, and global Library modules, Divi Global Presets and dynamic content resolve.
- Row and section sizing follows Divi: two-part padding, `padding_mobile="off"`, custom gutter widths, minimum heights and per-viewport background images carry over, and a `gutter_width` of 0 counts as 1.
- A module's `module_id` becomes the block's anchor and keeps characters such as `.`, `:` and `%`.
- A menu module binds a Navigation block to its menu, and a module without `menu_id` binds the `primary-menu` location.
- A module without a rule becomes a fallback block that keeps the module's attributes in a `data-divi-attrs` attribute.
- Blog lists convert to Query Loop blocks with Divi's author, pagination, spacing and excerpt settings, and blog post images use the `post-card` and `post-wide` image sizes, which the `imageSizes` map key changes.
- A video module converts to a Video block for a file and to an Embed block for the URL of any oEmbed provider.
- Raw HTML from text modules and classic content goes through a port of the editor's raw handler, and static blocks get the markup their `save()` writes in the editor.
- Paragraphs, headings, lists, images, quotes, separators, tables, videos and embeds convert to their blocks, a video that plays from `<source>` elements becomes a Custom HTML block, and other HTML becomes a Custom HTML block.
- `[caption]`, `[gallery]`, `[audio]`, `[video]` and `[embed]` shortcodes convert to Image, Gallery, Audio, Video and Embed blocks, and gallery images get their URL, alternative text, caption and link from the attachment.
- Classic `<!--more-->` (with custom text and `<!--noteaser-->`) and `<!--nextpage-->` comments become More and Page Break blocks.
- Inline styles on paragraphs, headings and list items become block attributes, with theme presets as `has-*` classes, and colored `<span>`s become the editor's text color format.
- Divi's `[button]` shortcode becomes a Buttons block, and the map's `shortcodes` key replaces or drops other shortcodes, such as Bloom's `[et_bloom_inline]`.
- A migration map (`--map`, JSON) holds the project decisions: gutter, class names, image sizes, sidebar part, targets, menus, empty paragraphs, colors, fonts, modules to skip or replace with markup, shortcodes and classes.
- The map rejects values of the wrong type or range, such as a `gutter` that is not a whole number from 1 to 4 or a `targets` entry that names an unknown kind or target.
- The map's `classes` key translates class names into block styles, preset classes or block attributes, or drops them, and `wp divi-migration classes` lists the classes left to translate.
- Empty paragraphs stay by default, and the map's `emptyParagraphs` key drops them.
- Sliders and accordions convert through targets: Outstand Carousel or stacked core Cover blocks, and Outstand Accordion or the core Accordion block.
- The first target whose blocks are registered is used, a setting picks one, and the `s3s_divi_migration_targets` filter adds targets for other block plugins.
- A site without a slider or accordion plugin keeps the module as a fallback block, and the report notes `kept: no carousel plugin` or `kept: no accordion plugin`.
- Tables in module HTML convert through a table target: the core Table block, or the Flexible Table block, which keeps inline cell styles.
- Divi contact forms and Bloom opt-ins convert through the `form` target, which rebuilds them in WS Form; Bloom opt-ins keep their layout, fonts and colors as blocks.
- Forms are created only for posts that are saved: `preview` and `--dry-run` create none, and a form left half-built after an error is discarded.
- A Divi contact form on a site without a form plugin stays as it was and gets the note `kept: no form plugin`.
- The `s3s_divi_migration_block_saves` filter adds saves for static blocks of other plugins.
- `wp divi-migration site` sets the site logo from Divi's logo, carries menus to the block theme, and prints Divi's integration code.
- Menus stay classic by default and keep their locations; the `menus` setting (`--menus=navigation`) copies them into Navigation posts instead.
- `site` keeps a logo and menu locations that are already set and reports them, `--force` replaces them, and it requires `--theme` when the active theme does not run on Divi.
- `wp divi-migration markup` prints block markup for Theme Builder layouts, Library items, widget areas, Divi content in a file and block specs in a JSON file.
- `markup --portable` makes upload URLs root-relative and drops attachment IDs from media blocks, with background image IDs removed at any depth, for theme files.
- The conversion report notes what the converter keeps or drops: shortcodes and modules kept, unresolved dynamic content such as `unresolved dynamic content: post_title`, dropped inline styles, list text alignment, the video overlay image, the WebM source, and other Divi settings that have no block equivalent.
- The plugin states the Divi version it is tested with, 4.9.11, and `analyze`, `convert` and `preview` warn when the installed Divi version or the newest `_builder_version` in the content differs from it; Divi 5 gets a warning that it is not supported.
- `analyze` prints the installed, tested and content builder versions, with the module count per version, and reads builder versions in straight, curly, encoded and single quotes.
- `wp divi-migration shortcodes` lists the shortcode tags in content and widgets, with their places and the plugin, theme or core code that registers each one.
- The plugin loads its translations from its `languages` folder, which ships a `divi-migration.pot` template.
- The plugin updates itself from its GitHub repository through the plugin update checker.

[Unreleased]: https://github.com/s3rgiosan/divi-migration/compare/1.0.0...HEAD
[1.0.0]: https://github.com/s3rgiosan/divi-migration/releases/tag/1.0.0
