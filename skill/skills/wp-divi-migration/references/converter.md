# The converter: Divi Migration

Use the [Divi Migration](https://github.com/s3rgiosan/divi-migration) plugin (`s3rgiosan/divi-migration`) for the conversion. It implements the pipeline below and ships one rule per module in the Layout and Content tables of module-map.md, except `et_pb_wc_*` (woocommerce.md). Activate it on the copy of the site for the migration and deactivate it afterwards: converted content does not need it.

Requires Divi Migration 1.0.0 or later, WordPress 7.1 (per-viewport block styles) and PHP 8.2. The plugin README has the full details of every command and key; this file keeps the commands the workflow uses.

## Commands

Run `wp help divi-migration <command>` for the full options of each command.

| Command | Does |
|---|---|
| `wp divi-migration analyze [--post_type=<types>] [--format=table\|json\|csv]` | Module census: uses, posts and distinct settings per module, across builder content and the Divi Library. `--post_type` defaults to `post,page,product,et_pb_layout,et_header_layout,et_body_layout,et_footer_layout`. Use the default `table` format: `csv` and `json` hide the version lines. |
| `wp divi-migration list [--post_type=<types>] [--status=<statuses>]` | Posts that hold Divi content or were converted. |
| `wp divi-migration preview <id> [--tree] [--map=<file>]` | The block specs and conversion report for a post, as JSON; `--tree` prints the parsed Divi tree. Creates nothing, not even forms. |
| `wp divi-migration convert [<id>...] [--classic] [--post_type=<types>] [--status=<statuses>] [--map=<file>] [--dry-run] [--force] [--format=table\|json\|csv]` | Converts posts and saves the blocks (see below). |
| `wp divi-migration classes [<id>...] [--post_type=<types>] [--status=<statuses>] [--map=<file>]` | Class census over the Divi and classic content of posts (`--post_type` defaults to `post,page,product` for Divi content and to `post,page` for classic content, as `convert` does). It picks the Divi or the classic path per post. It covers posts only, not theme parts or widget areas ("Class names"). It lists the classes the converted markup keeps that the block editor does not write and the map does not translate, with their uses and posts ("Class names"). Creates nothing. |
| `wp divi-migration markup <id>` / `--content=<file>` / `--widget-area=<id>` / `--specs=<file>` `[--map=<file>]` | Prints block markup without saving: a post, Theme Builder layout or Library item; Divi content in a file; a widget area; or a JSON list of block specs (it prints a JSON list with the markup of each). `--widget-area` stops with an error when the area does not exist or has no widgets. With a form target it creates the forms, because its output goes into templates: run it with `--user=<admin>`. `--portable` drops the attachment IDs of media blocks (`id`, `mediaId`, `ids` and the `wp-image-<id>` classes) and the ID of every background image, at any depth, and makes upload URLs root-relative, for template parts and patterns. |
| `wp divi-migration shortcodes [--post_type=<types>] [--status=<statuses>] [--format=table\|json\|csv]` | Shortcode census: each tag in post content (the Library included by default) and in text, HTML and block widgets, without `et_pb_*` tags, with its uses, its places (post IDs and `widget:<id>`) and the code that registers it on the running site (`core`, `plugin:<slug>`, `mu-plugin:<file>`, `theme:<slug>`, or empty when nothing does). Run it on the Divi site, or on the copy before the theme switch, to see the tags Divi and its plugins register. Creates nothing. |
| `wp divi-migration site --theme=<divi stylesheet or child> [--menus=classic\|navigation] [--map=<file>] [--force]` | Sets the Site Logo from Divi's logo, carries the menus over (site-parts.md), prints a table of the menu locations (status `assigned`, or `kept` when the location already had a menu), and prints Divi's integration code to move by hand. Always pass `--theme`: the Divi child theme's stylesheet, or `Divi` when there is no child theme. It is required when the active theme is not Divi-based. `--menus` defaults to the map's `menus`. Without `--force`, it keeps a logo already set. `--force` replaces the logo and, with classic menus only, the locations that already have a menu. |
| `wp divi-migration reset <id>...` / `--all` | Restores the Divi or classic content of converted posts from their backups, with their modified dates kept. It fails without IDs or `--all`. It exits with code 1 when a post is not found or fails to restore. |

`convert`:

- Without IDs, it converts every post with Divi content. `--post_type` defaults to `post,page,product`, `--status` to `publish,draft,private,future,pending`, `--format` to `table`. Pass `--post_type=<all builder types>` from the `analyze` and `list` output, so custom post types convert too.
- With `--classic`, it converts classic content instead, and `--post_type` defaults to `post,page`. Products stay out by default: WooCommerce edits product descriptions as classic content. `--post_type` can include `product`. Ask the owner whether to include products; the default answer is no. To cover all other classic content, run it with every public post type that holds classic content (`wp post-type list --public --field=name`), minus products unless the owner said yes.
- Explicit IDs must match `--post_type` and `--status`; an ID that does not match is skipped (status `skipped: post type or status not selected`). An ID that is not a Divi post is skipped (status `skipped: not Divi content`). With `--classic`, an ID that is not a classic post is skipped (status `skipped: not classic content`). An ID that does not exist gets the status `failed: not found`.
- It keeps each post's status, dates, modified date and page template (an overdue scheduled post stays scheduled). When it cannot put the status and dates back, it adds a note and still counts the post as converted.
- It lists per post the modules without a rule (`fallback`), missing blocks, and notes. The notes are:
  - a Bloom opt-in or contact form kept, and why;
  - `kept: no accordion plugin` and `kept: no carousel plugin`: the module stays as a fallback because no target is registered;
  - `dropped inline style: <property>`: an inline style the blocks cannot hold;
  - `not converted: list text alignment`, `not converted: video overlay image` and `not converted: webm video source`: a Divi setting with no block attribute.
- A module with no rule leaves a hidden marker (`data-divi-module`) that keeps the module's non-empty attributes as JSON in `data-divi-attrs`. An accordion or carousel with no registered target falls back the same way, with a note.
- A post with missing blocks is skipped unless `--force`, which saves it with each missing block as a hidden placeholder. A missing block is a block type that is not registered, or a static block without a save.
- A converted post edited after its conversion is skipped (status `skipped: edited after conversion`) unless `--force`, which converts it again and discards those changes.
- A result with no content is skipped (status `skipped: empty result`) unless `--force`.
- A post that fails gets the status `failed: <message>`, and the run continues with the next post. The exit code is 1 when any post failed.
- Text outside the modules stays as converted HTML, in its position between the modules.
- A note `filtered on save` on a row means the saved content differs from the converted markup (Save filters).
- Products built only from WooCommerce modules get their description back (woocommerce.md).
- With a form target, run it with `--user=<admin>` (a WP-CLI option): the form plugin creates forms only for a user who may.
- `--dry-run` saves nothing and creates no forms.
- A `<!--more-->` or `<!--nextpage-->` comment in classic content or a text module, at the top level, bare or inside a paragraph (the paragraph splits at the comment), becomes a More or Page Break block. A `<!--noteaser-->` comment after `<!--more-->` sets the More block's no-teaser option.

Backups: every converted post has a backup. The original content sits in `_s3s_divi_migration_content`, the conversion time in `_s3s_divi_migration_converted_at`, the hash of the saved content in `_s3s_divi_migration_markup_hash` (it detects edits after the conversion), the original `_et_pb_use_builder` value in `_s3s_divi_migration_builder` (Divi posts), and `_et_pb_use_builder` turns `off` (Divi posts). Every conversion starts from the backup, so `convert` can run again after a theme, map or rule change.

`reset`:

- `reset <id>...` restores the listed posts. `reset --all` restores every post that has a backup, Divi and classic, of any post type and status. `reset` without IDs or `--all` fails.
- It skips a post that was edited after its conversion, unless `--force`, which restores the backup and discards those edits.
- It keeps the post's status, dates and modified date.
- It warns for a post that is not found or fails to restore, restores the others, and exits with code 1.
- It deletes the backup and the conversion meta only when the saved content equals the backup. On a mismatch it warns and keeps the backup. It puts `_et_pb_use_builder` back to the value saved in `_s3s_divi_migration_builder`, or deletes the meta when the post had none (a post converted before that backup existed gets `on` when its original content is Divi content), then deletes `_s3s_divi_migration_builder`.
- It restores the content unfiltered, so scripts and iframes in code modules come back as they were.

## Migration map

A JSON file with the project's decisions, passed with `--map`. Keep it in the site's repository: production runs the same conversion. All keys are optional. The plugin validates the map: `--map` fails on unknown keys, a key that is not an object (`classNames`, `imageSizes`, `targets`, `colors`, `fonts`, `modules`, `shortcodes`, `classes`), an `emptyParagraphs` or `menus` value outside its options, a `modules` or `shortcodes` entry in the wrong shape, a `colors` or `fonts` value that is not a string, an empty or wrongly shaped `classes` entry, and a `classes` pattern that is not a valid regular expression. It also fails on a `gutter` outside 1 to 4, a `classNames`, `imageSizes` or `sidebarPart` value that is not valid, and an unknown target name.

| Key | Holds |
|---|---|
| `gutter` | default Divi gutter width, 1 to 4 (defaults to Divi's option, then 3) |
| `classNames` | class names of the layout roles (`section`, `section_fullwidth`, `row`, `row_inner`, `text`, `testimonial`), matching the theme's style variations (layout-model.md) |
| `imageSizes` | image size slugs of blog post images: `card` for grid lists, `wide` for full-width lists (defaults `post-card` and `post-wide`). The theme registers them with Divi's dimensions (site-parts.md, "Archives and search results") |
| `sidebarPart` | template part slug that replaces sidebar modules |
| `targets` | target per kind (`carousel`, `accordion`, `table`, `form`), or `auto` |
| `menus` | `classic` or `navigation` (site-parts.md) |
| `emptyParagraphs` | `keep` (default) or `drop` the `<p>&nbsp;</p>` spacers of classic content; keep them during the parity loop |
| `colors` | palette slug per Divi color the theme does not hold exactly (`"#336699": "accent"`) |
| `fonts` | font family slug per Divi font name (`"Open Sans": "body"`) |
| `modules` | per shortcode tag, `"skip"`, or `{ "markup": "<!-- wp:… -->" }` to put block markup or HTML in the module's place |
| `classes` | per class, class set (`"big-button bigpink"`) or pattern (`"/^p\\d+$/"`): the classes that replace it (`"is-style-big"`; `""` drops it), or an object of block attributes with an optional `className` ("Class names") |
| `shortcodes` | per shortcode tag, what replaces a shortcode that stands alone in a paragraph: `"skip"`, `{ "markup": "…" }`, or a list of those with an `attrs` object to match (`{ "attrs": { "optin_id": "optin_1" }, "markup": "…" }`). An entry `{ "skip": true }` drops the shortcode; it can carry an `attrs` object to match. |

Divi's Global Presets (`et_divi[builder_global_presets]`) need no entry either: the converter fills each module's unset settings from its preset, or from its module type's default preset, as Divi does.

Colors, fonts and font sizes that match the theme's presets exactly need no entry: they become `var:preset|…` references by value. Build the theme before converting.

## Block markup

The plugin writes blocks on the server. Static blocks get the markup their `save()` writes in the editor; dynamic blocks get their comment delimiters. Raw HTML from text modules goes through a port of the editor's raw handler. The plugin README's "Block markup" section lists what the handler converts and what the plugin adds (classed `<div>` to Group, inline styles to block attributes, Divi's `[button]` to Buttons, Bloom opt-ins to blocks, shortcodes on their own line to their block or a Shortcode block, anything else to Custom HTML). Block markup that Divi text already holds stays as blocks, with its attributes. The map's `classes` translate the class names on every block and on inline elements in text.

The plugin ships saves for the static core blocks its rules and targets use. A target for another block plugin whose blocks save markup also adds a save per block (`s3s_divi_migration_block_saves`); without one, posts that use the block are skipped.

The plugin's end-to-end test (`tests/e2e/serializer.spec.js`) checks the saves against the block editor on a running site. Run it after a WordPress update. The test fails when it skips a fixture spec, unless the block is listed in the environment variable `DIVI_MIGRATION_SKIP_BLOCKS`.

## Class names

Classic content and Divi modules carry class names: paste leftovers (`p1`, `s1`, `Apple-converted-space`, `MsoNormal`), Divi markup pasted into posts (`et_pb_*`), shortcode classes (`big-button bigpink`), and the site's own module classes (`module_class`, such as `card card--dark`). Classes that the map does not translate stay in the converted content, so translate each one in the map's `classes`:

1. Run `wp divi-migration classes --map=divi-migration.json`. It lists each class left, with its uses and posts. The census hides classes that start with `is-`, `has-`, `wp-` or `size-`, `align` classes that WordPress writes (`alignleft`, `alignright`, `aligncenter`, `alignwide`, `alignfull`, `alignnone`) and `columns-<n>`. Check the old CSS separately for site-owned `is-*` and `has-*` classes.
2. Look each class up in the old site's CSS: Divi's, the child theme's, the Additional CSS (`wp_get_custom_css()`). Divi's ePanel custom CSS field (`divi_custom_css`) writes there; an old copy can stay in the `et_divi` option.
3. Decide per class:
   - **No rule matches it:** drop it (`""`); a pattern covers families (`"/^(p|s|li|ul)\\d+$/"`, `"/^et_pb_/"`).
   - **Its rules describe a look of one block type:** a block style. Register it in the theme as `styles/<name>.json` (`blockTypes`, `slug`, and the styles theme.json can express) and port the rest of the rules to CSS on `.is-style-<slug>`, and keep the CSS properties; map the class to `is-style-<slug>`. A class set maps several classes to one style (`"card card--dark": "is-style-card-dark"`).
   - **Its rules span block types or set layout:** keep the CSS properties unchanged. Either register one block style per block type (the same slug on each), or put the rules in CSS on `.is-style-<slug>` with identical properties, and map the class to `is-style-<slug>`.
   - **Its rules set preset values:** block attributes (`{ "backgroundColor": "accent" }`), which also write the `has-*` classes.
   - **A class that only names a color or size variant with no rules of its own:** drop it.
4. Convert again and repeat until the census is empty.
5. The census covers posts only. Print the markup of each part, Theme Builder layout and widget area (`wp divi-migration markup … --map=divi-migration.json > file`), and search the output for leftover classes: `grep -o 'class="[^"]*"' <file> | sort | uniq -c`. The plugin writes `widget`, `widget-<id_base>` and `widget-title` on widget markup; they pass through the map's `classes` as other classes do.

A block keeps one block style: a mapped `is-style-*` replaces the layout role's style (`is-style-text`), so give the new style the role's spacing too. Classes in the theme's own templates and parts follow the same rule. Classes the plugin's rules write are block styles or attributes already (`is-style-testimonial`).

## Extending it

Targets per kind, in order of preference. The map's `targets` key names one; with `auto` (the default), the first target whose blocks are registered wins.

| Kind | Targets, in preference order | Default |
|---|---|---|
| `carousel` | `outstand-carousel`, `core-cover` | the first registered |
| `accordion` | `outstand-accordion`, `core-accordion` | the first registered |
| `table` | `core-table`, `flexible-table` | `core-table`; `flexible-table` only when the map picks it |
| `form` | `ws-form` | `ws-form` |

- **Targets.** Sliders, accordions and tables convert through targets, so the site picks the block plugin. Tables go to the core Table block unless the map picks `flexible-table` (Flexible Table Block), which keeps inline cell styles: pick it when the site's tables depend on styled cells. The rule reads the Divi module into neutral data (slides with their background and content blocks; accordion items with title, open state and content blocks; colors and settings), and a target builds the outer blocks. Built-in targets cover a carousel and an accordion block plugin (named in the plugin's README) and core blocks: stacked Cover blocks for a slider, the core Accordion block for an accordion. With `auto`, the first target whose blocks are registered wins; the report names the target used.
- **Forms.** Divi's contact form module and Bloom's inline opt-ins go to the `form` target. The built-in target, `ws-form`, creates one WS Form form per source form (fields, button text, success message; the consent checkbox WS Form adds is removed), keeps the form ID per source form in the `s3s_divi_migration_forms` option so converting again reuses the form while it exists outside the trash, and puts its block in the content. Field and button widths use the column grid of the active WS Form framework. A contact form sends its submissions to the module's valid recipient addresses, and to the site's admin email (WS Form's `#blog_admin_email`) when the module has none; an opt-in form has no email action. If creating a form fails, the plugin deletes the unfinished form and keeps the module as a fallback (contact form) or as the shortcode (opt-in), with a note. Opt-in forms get the `optin` or `optin-light` block style for the theme to style. `--dry-run` and `preview` create no forms; `convert` and `markup` create them, so run them with `--user=<admin>`. Without WS Form active, contact forms fall back and opt-ins stay shortcodes, with a note (`kept: no form plugin`).
- `s3s_divi_migration_targets` adds a target for another block plugin (a class implementing `CarouselTarget`, `AccordionTarget`, `TableTarget` or `FormTarget`), or reorders the defaults.
- `s3s_divi_migration_rules` filters the rules, keyed by shortcode tag (`et_pb_text`): add a rule for a Divi extension's module, or replace one.
- `s3s_divi_migration_settings` filters the site-wide settings after the map: the default gutter, the class names of the layout roles, the sidebar template part slug, the target per kind and the menu mode. Prefer the map: it travels with the project.
- `s3s_divi_migration_block_saves` adds a save for a static block of another plugin: a callable that takes the block's attributes (with defaults) and its `Serializer\Supports`, and returns the markup before and after the inner blocks.

## Pipeline

1. **Parse** the content into a tree. Only `et_pb_*` tags become nodes; other shortcodes stay in the text. Divi always writes closing tags; a missing one closes the open nodes above a match. Attribute values are decoded (`%22` → `"`, `%91` → `[`, `%93` → `]`, `%92` → `\`) and `<!-- [et_pb_line_break_holder] -->` becomes a newline.
2. **Convert** each node with the rule for its tag (or the map's action) into **block specs**:
   - a block: `{ name, attributes, innerBlocks }`;
   - raw HTML: `{ raw, apply }`, which the raw handler turns into blocks; `apply` holds attributes to merge into the resulting blocks, keyed by block name (`core/paragraph`) or heading level (`core/heading:2`);
   - markup: `{ markup }`, block markup that goes in as is.
3. **Serialize** the specs on the server (Block markup).
4. **Back up** the Divi content before the first save, then save the post.

## Checking the result

`convert` saves without a browser. Check the result in one: open each converted page in the editor, or parse every converted post's content with `wp.blocks.parse()` in an editor page and list the blocks whose `isValid` is false. Expect none; an invalid block means a save that no longer matches its block (a new WordPress or block plugin version).

Check that code-module output survives for non-admin authors: look for `filtered on save` notes in the `convert` report. For each such post, compare the saved content with the converted markup (`wp divi-migration markup <id>`). When a script or iframe is gone, decide with the site owner: convert with a different post author, or move the code to a site plugin or a template.

## Pitfalls (WordPress 7.1)

The plugin handles these; they matter when you write a rule or edit markup by hand.

- **`core/html`:** `content` is a local attribute and `save()` returns null; the markup lives in the block's inner content, between the comment delimiters.
- **Text alignment** of paragraphs, headings and post titles is `style.typography.textAlign`, not an attribute.
- **Save filters:** WordPress filters content on save per post author. An author without `unfiltered_html` loses scripts and iframes (kses), and an author without `edit_css` loses per-block custom CSS (`style.css`). `convert` applies these filters according to the post author's capabilities. A row note `filtered on save` marks a post whose saved content differs from the converted markup. Markup saved any other way needs a user with `edit_css` and `unfiltered_html`.
- **Per-viewport styles** (`style['@tablet']`, `style['@mobile']`), hover (`style[':hover']`), per-block custom CSS (`style.css`) and viewport visibility are core. Layout and `blockGap` accept per-viewport values (`style['@tablet'].layout.contentSize`). Per-block custom CSS takes flat `&` rules only, no `@media`; declarations before the first `&` style the block itself, and inline block styles beat it.
- **Raw handler habits:** it moves images out of paragraphs, keeps classed `<div>` wrappers as Custom HTML and leaves `<p>&nbsp;</p>` spacers; the plugin's additions (Block markup) handle all three.
- **WooCommerce products** open in WooCommerce's classic product screen, so their descriptions stay classic content.
