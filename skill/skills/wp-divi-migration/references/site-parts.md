# Site parts: header, footer, templates, menus, widgets

## Header

Divi builds the default header from theme options (`header_style`, default `left`, with values `left`, `centered`, `split`, `slide`, `fullscreen`; `vertical_nav`), unless a Theme Builder header layout replaces it. Measure the rendered header (heights, logo box, menu font and gaps, top bar, drop-down) at desktop and at 980px and below, then rebuild it as `parts/header.html` from core blocks:

- **Top bar** (`#top-header`, secondary menu, cart): a Group with the secondary Navigation and the shop's mini cart. At 980px and below Divi hides the secondary menu (`#et-secondary-nav`, `#et-secondary-menu`). It hides the whole top bar only when the page has the class `.et_secondary_nav_only_menu` (`style.dev.css`, `max-width: 980px` block). Use viewport visibility to match.
- **Logo:** Site Logo. Set `site_logo` from Divi's `divi_logo` URL (attachment ID). An SVG logo has no intrinsic size: set its width in the stylesheet.
- **Menu:** Navigation bound to the primary menu's location (a menu module without `menu_id` binds the `primary-menu` location), or with `ref` to its Navigation post (Menus, below). Divi switches to its mobile menu at 980px; core opens its overlay below 600px, so move core's breakpoint in the stylesheet. With WooCommerce active, stop its hooked blocks on each header Navigation block (woocommerce.md, "Cart in the header").
- **Fixed header** (`divi_fixed_nav`, default `on`): `position: fixed` on desktop (`.et_fixed_nav #main-header`); a sticky header reproduces it. At 980px and below Divi sets `#main-header` and `#top-header` to `position: absolute`, so the header scrolls with the page. Offset the header by the admin bar.

When a Theme Builder header layout exists, write the part from blocks the converter already produces (`wp divi-migration markup` for the layout), not from hand-written block HTML. Step 3 of the workflow leaves `parts/header.html` empty. Fill it in step 5, after `site` and the map.

## Without Theme Builder layouts

A site with no Theme Builder layouts has nothing for `markup` to print. Divi then renders its default header (above) and `footer.php`. Build both parts by hand from core blocks, and measure the rendered footer at desktop and at 980px and below, as for the header.

The default footer (`footer.php`, `sidebar-footer.php`) holds, in order:

- **Footer widgets** (`#footer-widgets`): up to six widget areas, side by side. The `footer_columns` theme option (default `4`) sets how many show and their widths. Divi hides the whole block when none of the areas holds a widget. Convert each area with `markup --widget-area` (Widget areas, below) and place the results in Columns.
- **Footer menu** (`#et-footer-nav`): shown when the `footer-menu` location has a menu (Menus, below).
- **Bottom bar** (`#footer-bottom`): the social icons (`show_footer_social_icons`, default on) and the credits (`#footer-info`). The credits come from the `custom_footer_credits` option, with Divi's own text when it is empty, and are empty when `disable_custom_footer_credits` is set. Read the options from `et_divi` and write the text as static copy in a pattern with `__()`.

Wrap the result in a `<footer>` Group in `parts/footer.html`.

Build these templates, each with the header and footer parts, from the capture of the matching route:

- `index.html`
- `page.html` (no title; Page titles, below)
- `single.html`
- `archive.html`
- `search.html`
- `404.html`
- `home.html` or `front-page.html`, only when the site uses a posts page or a static front page

Archives and search results follow "Archives and search results", below.

## Library items

The Divi Library (`et_pb_layout` posts) holds saved sections, rows, modules and layouts. `wp divi-migration markup <id> --map=<file> --portable` accepts a Library item ID and prints its blocks, as it does for a post.

- A Library item that pages use as a global module (`global_module="<id>"`) needs no output of its own: `convert` and `markup` merge the item into each module that references it (module-map.md, "Shared settings").
- A Library item that editors insert by hand becomes a pattern. Save its `markup` output as a pattern file (core `references/section-to-pattern.md`). Ask the owner which unused items to keep.

## Footer and other Theme Builder layouts

A Theme Builder layout (`et_header_layout`, `et_body_layout`, `et_footer_layout`) is Divi content: `wp divi-migration markup <layout id> --map=<file> --portable` prints its blocks, without attachment IDs and with root-relative upload URLs, as theme files need. Wrap them in a `<footer>` (or `<header>`) Group, and save them as the part. Then:

- make upload URLs root-relative (`/wp-content/uploads/…`) so the part works on every domain;
- bind menu modules to a location (classic menus) or a Navigation post (block menus): run `wp divi-migration site --theme=<divi stylesheet or child>` before `markup`;
- for a body layout, swap its post-content module for `core/post-content` and keep the rest as the template.

Theme Builder conditions (`et_template` → "use on" / "exclude from") decide which templates the layouts become: all pages → `page.html`, posts → `single.html`, archives → `archive.html`, and so on. Read them from the post meta (inventory.md, "Builder content").

## Templates a child theme builds

Child themes often build a post template as a Divi shortcode string at render time (`do_shortcode( '[et_pb_section…]' )`). Save the string to a file and run `wp divi-migration markup --content=<file>`, with marker text where dynamic content goes (`MARKER_POST_CONTENT`), then replace each marker's block with the dynamic block (`core/post-content`, a share block, comments). Re-read the child theme's CSS for overrides of that template (title size, row padding) and apply them to the generated blocks.

## Archives and search results

When no Theme Builder layout covers them, Divi's `index.php` lists posts in archives and search results. Rebuild that list in `archive.html`, `search.html` and `index.html`:

- The featured image uses Divi's 1080 by 675 crop. Register it as `post-wide`: `add_image_size( 'post-wide', 1080, 675, true )`. For a grid list of posts, register Divi's 400 by 250 crop as `post-card`: `add_image_size( 'post-card', 400, 250, true )`. These names are the converter's defaults (`imageSizes` in converter.md). Set the name as the Post Featured Image block's `sizeSlug`. Run `wp media regenerate --only-missing --skip-delete --yes` once, so existing uploads record the new sizes and keep the Divi-size files that content links to. Until then WordPress serves the full image. Without the crop, square and portrait images show at full height.
- The meta line (`.post-meta`) is 14px; check the child theme and the Customizer CSS for its line height.
- The excerpt is bare text in the article, so it follows the body size per viewport (layout-model.md, "Text").
- On phones Divi sets `.et_pb_post > h2` to 18px at 767px and below and to 16px at 479px and below.

## Page titles

Divi's `page.php` shows the page title (`<h1 class="entry-title main_title">`) only when the page does not use the builder (`et_pb_is_pagebuilder_used()`, which checks `_et_pb_use_builder = on`). Builder pages show no title. Pages without the builder, such as the cart or the account page, show it. Check the child theme for its own `page.php`.

- Leave the title out of `templates/page.html`, so the converted builder pages match.
- Add a custom template with a level 1 `core/post-title`, such as `templates/page-with-title.html`. Declare it in `theme.json` under `customTemplates`, with `"postTypes": [ "page" ]`.
- List the pages without the builder before `convert`. The converter sets `_et_pb_use_builder` to `off` on each Divi post that it converts. Replace `wp_` with the table prefix:

  ```bash
  wp db query "SELECT p.ID, p.post_title FROM wp_posts p
    WHERE p.post_type = 'page' AND p.post_status = 'publish'
    AND NOT EXISTS (SELECT 1 FROM wp_postmeta m WHERE m.post_id = p.ID
      AND m.meta_key = '_et_pb_use_builder' AND m.meta_value = 'on')"
  ```

- Assign the template to each of those pages: `wp post meta update <id> _wp_page_template page-with-title`.
- The assignment lives in the database. Put the page list and the command in the cutover notes (parity.md, "Cutover notes"), and run them again on production.

## Comment form

Divi's `comments.php` calls `comment_form()` with the title "Submit a Comment" and the button "Submit Comment". Divi's `js/custom.unified.js` (`et_pb_form_placeholders_init()`) then hides the label of each text field and of the textarea. It writes the label text, with the required mark, into the field as its value, and clears the value on focus.

The Post Comments Form block also calls `comment_form()`. Reproduce Divi's form with two filters in the theme. Use the theme's text domain:

```php
add_filter(
	'comment_form_defaults',
	static function ( array $defaults ): array {
		$defaults['title_reply']  = __( 'Submit a Comment', 'theme-slug' );
		$defaults['label_submit'] = __( 'Submit Comment', 'theme-slug' );

		return $defaults;
	}
);

add_filter(
	'comment_form_fields',
	static function ( array $fields ): array {
		foreach ( [ 'comment', 'author', 'email', 'url' ] as $name ) {
			if ( ! isset( $fields[ $name ] ) || ! is_string( $fields[ $name ] ) ) {
				continue;
			}

			$label     = trim( wp_strip_all_tags( $fields[ $name ] ) );
			$processor = new WP_HTML_Tag_Processor( $fields[ $name ] );

			if ( $processor->next_tag( 'label' ) ) {
				$processor->add_class( 'screen-reader-text' );
			}

			if ( $processor->next_tag( 'comment' === $name ? 'textarea' : 'input' ) ) {
				$processor->set_attribute( 'placeholder', $label );
			}

			$fields[ $name ] = $processor->get_updated_html();
		}

		return $fields;
	}
);
```

- The labels stay for screen readers. Core's block library CSS hides `.screen-reader-text` in block themes.
- A placeholder disappears when the visitor types, not on focus. Log this difference as accepted in the parity loop.
- Divi's strings use the `Divi` text domain. On a site in another language, translate the new strings with the words that the Divi site shows.

## Menus

Divi uses classic menus. Decide with the site owner whether they stay classic (edited under Appearance → Menus) or become block menus (Navigation posts, edited in the Site Editor). Classic is the safer default: editors keep the screen they know, and the switch to block menus stays open.

- **Classic (default).** `wp divi-migration site --theme=<divi stylesheet or child>` assigns each menu to the same location in the active theme and prints a table of the locations (`assigned`, or `kept` for a location that already had a menu; `--force` replaces those). The theme then:
  - registers those locations (`register_nav_menus()`), so Appearance → Menus keeps working;
  - binds its Navigation blocks to them with `__unstableLocation` (core renders that attribute only with the Gutenberg plugin);
  - fills each location-bound Navigation block with the location's classic menu as it renders: a `render_block_data` filter that sets the block's inner blocks from `WP_Classic_To_Block_Menu_Converter::convert( $menu )`, so menu changes show at once;
  - returns false from `wp_navigation_should_create_fallback`, and keeps no Navigation posts, so the editor does not attach a block menu to those blocks.

  The Site Editor shows these Navigation blocks empty; the menu items render on the front end. Give the theme a filter that switches to block menus: the same render filter then points each block (`ref`) to a Navigation post made once from the location's classic menu, matched by slug.
- **Block menus.** `wp divi-migration site --menus=navigation` creates a Navigation post per menu (core's `WP_Classic_To_Block_Menu_Converter`), or updates the post with the menu's slug; the parts reference the posts by `ref`. Those IDs differ between databases, so remap them at cutover. This is an exception to the core rule of no database IDs in theme files (`ref` left off); classic menus, the default, need no `ref`. Running the command again updates the posts, so run it before anyone edits menus in the Site Editor.

Read the menu locations from the Divi theme's mods (`theme_mods_<divi stylesheet or child>`): after a theme switch, `get_nav_menu_locations()` reads the new theme. Register those locations in the theme shell (workflow step 3) and run `site` before converting content, so menu modules bind to the right location or Navigation post.

## Widget areas

Block themes have no widget areas. Convert each area that a template shows into a template part with `wp divi-migration markup --widget-area=<id> --map=<file>`. The command stops with an error when the area does not exist or has no widgets.

The theme switch and the capture need a fixed order:

1. While Divi is active (workflow step 3, before the activation), list the areas and their widgets with `wp option get sidebars_widgets --format=json`, and the widgets of one area with `wp widget list <area>`.
2. Save the areas to a file: `wp option get sidebars_widgets --format=json > sidebars-widgets.json`.
3. Activate the block theme. On the next load, core remaps the widgets to the new theme's areas (`wp_map_sidebars_widgets()`). When the block theme registers no areas, every widget moves to `wp_inactive_widgets`, and `markup --widget-area` reports the old areas as not found.
4. In workflow step 5, restore the areas on the copy: `wp option update sidebars_widgets --format=json < sidebars-widgets.json`.
5. Run `markup --widget-area` with the block theme active.

Do not run `markup` while Divi is active. The converter matches inline colors, fonts and font sizes to the presets of the active theme. Divi has no `theme.json`, so the converter reads core's default presets, and the markup gets slugs that the block theme does not define.

The plugin converts the widgets as follows:

- text and Black Studio TinyMCE widgets → their HTML through the raw handler (with `wpautop()`);
- search → `core/search`; custom HTML → `core/html`; widget titles → level 4 headings with the class `widget-title`;
- other widgets → `core/legacy-widget`;
- each widget sits in a Group with the classes `widget` and `widget-<id_base>`.

The classes `widget`, `widget-<id_base>` and `widget-title` go through the map's `classes` like any other class (converter.md, "Class names"). The class census does not read widget areas, so search the `markup` output for leftover classes.

## Integration code and per-page CSS

- Divi's integration options (`divi_integration_head`, `_body`, `_single_top`, `_single_bottom`) hold verification tags, analytics and pixels. Divi outputs each one only when its enable flag is on: `divi_integrate_header_enable`, `divi_integrate_body_enable`, `divi_integrate_singletop_enable` and `divi_integrate_singlebottom_enable` (`epanel/custom_functions.php`). Move only the enabled code, each to its proper home: a verification tag to the SEO plugin's setting, scripts to a site plugin. Keep tokens out of the theme's code.
- Per-page CSS (`_et_pb_custom_css`) targets Divi classes. Rewrite the rules that still matter against the block markup, in the theme stylesheet or the page's custom CSS.

## Forms in templates and parts

A form ID differs per database, so a template, part or pattern holds no form ID. The part holds a Shortcode block with the form's label, and a small helper in the theme or the site plugin looks the form up at render time. The sketch uses WS Form, which keeps forms in the `{prefix}wsf_form` table (`id`, `label`, `status`) and renders them with its `[ws_form id="…"]` shortcode. Give each form a unique label, and keep the labels the same on every environment.

```php
add_shortcode(
	'site_form',
	static function ( $atts ): string {
		global $wpdb;

		$atts  = shortcode_atts( [ 'label' => '' ], (array) $atts, 'site_form' );
		$label = (string) $atts['label'];

		if ( '' === $label ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WS Form keeps forms in its own table.
		$form_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wsf_form WHERE label = %s AND status = 'publish' LIMIT 1",
				$label
			)
		);

		if ( 0 === $form_id ) {
			return '';
		}

		return do_shortcode( sprintf( '[ws_form id="%d"]', $form_id ) );
	}
);
```

The part holds `<!-- wp:shortcode -->[site_form label="Newsletter"]<!-- /wp:shortcode -->`. On production, the helper finds the form by its label, with no change to the part. Rename the forms that `convert` creates to the labels the parts use.

## Strings in templates

Block templates and parts cannot hold translatable strings. Text that must translate (a "no results" message, a share title) goes in a pattern or a block with `__()`.

## Opt-in forms (Bloom)

Bloom (Elegant Themes' email opt-in plugin) runs without Divi, but it has no block and it is an Elegant Themes plugin that goes with Divi. Read its opt-ins from the `et_bloom_options` option: type (inline, pop-up, fly-in, below post, widget, locked content), status, design (orientation of image and form, fonts, text colors) and the email service account and list. The converter's `form` target handles it (converter.md, "Extending it").

With WS Form active (the `form` target defaults to `ws-form`), `convert` and `markup` replace each `[et_bloom_inline]` that stands alone with blocks: a group named after the opt-in, the title and text with Bloom's fonts, sizes, colors and alignment, the image beside, above or below them, and the form in a column beside the text or below it, as the opt-in placed them. Run them with `--user=<admin>`. An opt-in that is not in `et_bloom_options`, or that cannot become a form, stays a shortcode; the `convert` notes say `Bloom opt-in <id> not found`, or `Bloom opt-in <id> kept: no form plugin` (or the form error). Then:

- Add the opt-in's fonts (`header_font`, `body_font`) to the theme if it lacks them, so they match presets.
- Style the form plugin's fields for the `optin` and `optin-light` block styles: Bloom's white fields or translucent dark fields, its button and spacing.
- Connect the new forms to the email service list Bloom used; the form plugin keeps the submissions until then. Record it as a task.
- A template or part shows a form through the lookup helper in "Forms in templates and parts", above. The form block of `convert` output in post content keeps its own ID.

Pop-ups, fly-ins and below-post opt-ins have no shortcode in the content: they need a plugin that shows forms by trigger, or a theme template part.
