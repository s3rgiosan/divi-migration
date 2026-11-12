# Inventory: where Divi lives

Run these on the copy of the site (WP-CLI). Record each finding: it is a task, and the list is the scope.

## Builder content

Divi marks builder posts with `_et_pb_use_builder = on` and stores modules as shortcodes in `post_content`.

Read the table prefix from `wp config get table_prefix`; Divi sites often use a custom one. Replace `wp_` in the query below with it.

```bash
# Posts that hold builder content, per post type and status
# A post can have more than one _et_pb_use_builder row, so count distinct IDs.
wp db query "SELECT p.post_type, p.post_status, COUNT(DISTINCT p.ID) FROM wp_posts p
  JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key = '_et_pb_use_builder' AND m.meta_value = 'on'
  GROUP BY p.post_type, p.post_status"

# Module census: uses, posts, distinct settings per module (builder post types and the Divi Library; --post_type for others)
# Use the default table format: csv and json hide the version lines.
wp divi-migration analyze
```

Post types to include:

- `page`, `post`, `product` and any custom post type with builder content.
- `et_pb_layout`: the Divi Library (saved sections, rows, modules, layouts). Library items flagged as global are referenced from pages by `global_module="<id>"`. The plugin merges the page's settings with the Library item's settings; the Library wins, except for the settings listed in the item's `_et_pb_excluded_global_options`. The content comes from the Library item only when the item is synced; otherwise it comes from the page.
- `et_template`, `et_theme_builder`, `et_header_layout`, `et_body_layout`, `et_footer_layout`: the Theme Builder. `et_theme_builder` holds the template list; each `et_template` points to header, body and footer layouts and to the conditions where it applies. Read them from post meta:
  - on `et_theme_builder` posts: `_et_template` (one value per template ID);
  - on `et_template` posts: `_et_header_layout_id`, `_et_body_layout_id`, `_et_footer_layout_id`, `_et_use_on`, `_et_exclude_from` (each can hold several values), `_et_enabled` and `_et_default` (`1` or `0`).

  Example: `wp post meta list <id>`.

Also count content outside the builder: posts and pages without `_et_pb_use_builder` hold classic HTML.

### Classic content outside post content

HTML also lives in places that `convert --classic` does not reach. List each place that holds HTML on the site, and ask the owner whether it needs blocks:

- Post excerpts (`post_excerpt`).
- Term descriptions (the `description` of each term; `wp term list <taxonomy> --fields=term_id,description`).
- Custom-field HTML: post meta whose value holds markup (a sidebar blurb, a "details" field).
- WooCommerce product descriptions: `convert --classic` includes products only when `--post_type` lists `product`. The default answer is no (converter.md, "Commands").

Keep `_et_pb_old_content` in mind: Divi saves the pre-builder content there when the builder takes over a post. For WooCommerce products it is the real product description.

## Settings that are not in the content

- **Divi theme options:** option `et_divi` (serialized array). Layout (`content_width`, `gutter_width`, `section_padding`, `row_padding`, `phone_section_height`, `tablet_row_height`…), fonts (`body_font`, `heading_font`, sizes per device), colors (`accent_color`, `link_color`, `font_color`, `header_color`), button defaults (`all_buttons_*`), header style and menu colors, footer, the logo (`divi_logo`), the color palette (`divi_color_palette`, hex colors joined by `|`; the builder has 8 default colors when it is empty), and **integration code** (`divi_integration_head`, `_body`, `_single_top`, `_single_bottom`). Divi outputs a block only when its flag is `on` (`divi_integrate_header_enable`, `divi_integrate_body_enable`, `divi_integrate_singletop_enable`, `divi_integrate_singlebottom_enable`): move only enabled code. Some options hold secrets (API keys): read only the keys you need and never copy values into a repository.
- **Custom fonts:** option `et_uploaded_fonts` (separate from `et_divi`) lists font files uploaded through Divi (usually under `uploads/et-fonts/`).
- **Menus:** classic menu locations live in the theme's mods (`theme_mods_<stylesheet>`), so they disappear from `get_nav_menu_locations()` once another theme is active. Read them before switching, or from the old theme's mods.
- **Widget areas:** option `sidebars_widgets`, and each widget type's `widget_<id_base>` option.
- **Per-page settings:** `_et_pb_page_layout` (`et_right_sidebar`, `et_left_sidebar`, `et_no_sidebar` or `et_full_width_page`), `_et_pb_show_title`, `_et_pb_color_palette` (page palette), `_et_pb_gutter_width`, `_et_pb_custom_css` (CSS typed into the page settings: port it with the page).

## Child theme

A Divi child theme can hold far more than CSS. List:

- every `add_action`, `add_filter`, `add_shortcode`, `register_sidebar`, field group registration and custom template (`single-*.php`, page templates);
- template overrides for plugins (WooCommerce `woocommerce/`, others);
- CSS that targets Divi markup (`.et_pb_*`, `#main-content`, `#left-area`);
- functions that build Divi shortcode strings at render time (a post template generated with `do_shortcode()` is common). Those strings convert like page content.

## Plugins tied to Divi

A **Divi-only plugin** needs Divi or the Divi Builder to run (many Divi extensions), or is an Elegant Themes plugin such as Bloom or Monarch. List each one with where it renders (its own shortcodes, Divi modules, triggers). Settle each Divi-only plugin and each module without a converter with the site owner (SKILL.md, step 4). Forms follow the `form` target (converter.md, "Extending it"). Other plugins (an Instagram feed, a booking plugin) stay unless the owner asks to replace them.

## Editor switches

The converted content needs the block editor. List every plugin, option and filter that turns it off. Each one blocks the migration:

- The Classic Editor plugin. Option `classic-editor-replace`: `classic`, `replace` or no value gives every user the classic editor; `block` or `no-replace` gives the block editor. Option `classic-editor-allow-users` set to `allow` lets each user, and each post, choose.
- Disable Gutenberg and similar plugins.
- `use_block_editor_for_post` and `use_block_editor_for_post_type` filters in the child theme or a plugin.
- Divi's "Enable Classic Editor" option (`et_divi` → `et_enable_classic_editor`). It stops with Divi.

```bash
wp plugin list --status=active --field=name
wp option get classic-editor-replace
wp option get classic-editor-allow-users
```

With the classic editor, converted posts open in TinyMCE, a save can break their blocks, and the editor checks of the parity loop cannot run. Ask the owner to remove each switch (suggest removal). Deactivate the switches on the copy before the parity loop, and put their removal in the cutover notes.

## Shortcodes

List every shortcode tag in post content, Library items and widgets, with the posts that use it, and check which code registers each one on the Divi site: run `wp divi-migration shortcodes` there (converter.md, "Commands"). A shortcode registered by Divi itself (`[button]` from Divi's `epanel`) or by a plugin that goes away stops rendering: convert it, replace it through the map's `shortcodes` key, or keep its plugin. Bloom's `[et_bloom_inline]` converts through the `form` target (site-parts.md). Script code and CSS in code modules (`[CDATA`, `[i]`) and Contact Form 7 form tags are not shortcodes.
