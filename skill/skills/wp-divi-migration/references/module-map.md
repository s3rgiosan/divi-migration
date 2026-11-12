# Module recipes

One recipe per Divi module. Targets default to core blocks. Where core has no fitting block (a carousel, an accordion with the look the site needs), the site chooses a block plugin and the converter rule targets it; keep that choice in one rule so another site can swap it.

Every recipe also applies the module's shared settings (see "Shared settings" below).

## Layout

| Module | Blocks | Notes |
|---|---|---|
| `et_pb_section` | `core/group`, `align: full`, variation `section`, constrained layout | `fullwidth="on"` → variation `section-fullwidth`, default layout, modules span the page. `specialty="on"` → the section holds columns directly: one `core/columns`, with the gap of a row (custom gutter rule as in the row below). A section width (`width`, `max_width`, `module_alignment`) → a full-width wrapper Group with that `contentSize` and `justifyContent`, the section Group inside without `align`. |
| `et_pb_row`, `et_pb_row_inner` | one column without its own design → `core/group` (variation `row`); otherwise `core/columns` (variation `row`) | Gap from `use_custom_gutter` / `gutter_width` (a `gutter_width` of 0 counts as 1), else the site gutter. A row width → full-width Group with that `contentSize` per viewport, the Columns inside. Older content sets column padding on the row: `padding_<side>_<n>` (bare numbers are px). Divi renders a row's `custom_padding` twice: its shared padding rule reads the parts as top, right, bottom and left at every width, and its row rule reads two parts (`a|b`) as top and bottom. So a two-part padding is top `a`, right `b` and bottom `b`. With `padding_mobile="off"` and no tablet or phone padding, the row rule applies at 981px and up only, so on tablets and phones the bottom padding is Divi's row height (`tablet_row_height`, `phone_row_height`). A four-part padding applies at every width. |
| `et_pb_column`, `et_pb_column_inner` | `core/column` with its width (see layout-model.md) | Column background, padding and border on the column. An empty column is hidden on tablets and phones (`metadata.blockVisibility`), as Divi hides `.et_pb_column_empty` at 980px and below. |

## Content

| Module | Blocks | Notes |
|---|---|---|
| `et_pb_text` | `core/group` (variation `text`) holding the HTML as blocks (raw handler) | Run `wpautop()` on the HTML first: Divi pages get paragraphs from `the_content`. Body settings (`text_*`) on the Group, which its blocks inherit; heading settings per level (`header_*`, `header_2_*` … `header_6_*`) on each heading; `text_orientation` as `typography.textAlign` on paragraphs, headings and lists (`justified` becomes `left`; the note `justified text set to left` shows in the `preview` report, not in the `convert` notes). Link color on the Group's `elements.link`. A module with its own font size and no line height gets the body line height in px (Divi sets the body line height in `em`). With `content_last_edited="on|…"`, `content_tablet` and `content_phone` hold other HTML for tablets and phones: each distinct content becomes a text Group hidden where it does not apply (`metadata.blockVisibility`), and an empty value hides the content there. |
| `et_pb_image`, `et_pb_fullwidth_image` | `core/image` (attachment ID from the URL) | `show_in_lightbox` → `lightbox` (it wins over `url`: with both set, the image gets the lightbox and no link); `url` → link (`url_new_window` opens it in a new tab); `align` center/right; full-width or `force_fullwidth` → width 100%. Module padding and background go on a wrapping Group. |
| `et_pb_button` | `core/buttons` + `core/button` | `button_alignment` per viewport → Buttons flex justification; module padding is the button padding; custom styles (`button_*`) only when `custom_button="on"`, hover from `button_*__hover`. |
| `et_pb_code`, `et_pb_fullwidth_code` | `core/html`; a single shortcode → `core/shortcode` | Shortcodes inside HTML still render. |
| `et_pb_blurb` | `core/group`: image (or nothing for an icon font), heading (`header_level`, default h4), text | `icon_placement="left"` → image and text in a flex row. `url` links the title and image; `url_new_window` opens the link in a new tab. The description follows the responsive content rule of `et_pb_text`. |
| `et_pb_cta` | `core/group`: heading (`header_level`, default h2), text, Buttons | With no background color set, the Group gets Divi's `accent_color` option as background (`#7ebec5` when the option is empty or not a valid color), unless `use_background_color="off"` or `background_enable_color="off"`. `background_layout` defaults to dark here, and a dark layout sets the text color to `#ffffff` when the module sets none. Text is centered by default. |
| `et_pb_testimonial` | `core/group` with the `testimonial` block style (`is-style-testimonial`): text, optional portrait image, author paragraph (author, job title, company) | Register the block style in the theme; it draws the quote mark. |
| `et_pb_counters` / `et_pb_counter` | a Group per bar: the title paragraph, then a track Group (`#dddddd`, 10px below except the last bar) holding a filled Group sized with custom CSS `width: <n>%` | The title is 12px with line height 1.6 and `#666666` on a light background; `title_*` settings win. The fill has 1px padding, a 6px minimum height and the percentage inside (600 12px, right-aligned, 10px side padding) unless `use_percentages="off"`. No animation. |
| `et_pb_number_counter` | `core/group` (variation `text`): large number paragraph, title heading (`title_level`, default h3) | The number ends with `%` unless `percent_sign="off"`, and has 20px below it when there is a title. Divi sizes the title like an h4: `intval( <heading size option> × 0.6 )` per viewport. No count-up. |
| `et_pb_video` | `core/embed` (the URL of any oEmbed provider) or `core/video` (a video file URL) | The overlay image (`image_src`) and a WebM source (`src_webm`, when `src` is also set) are not converted; the report notes `not converted: video overlay image` and `not converted: webm video source`. |
| `et_pb_post_title` | `core/post-title` (level 1), meta row (`core/post-author-name`, `core/post-date`, `core/post-terms`, `core/post-comments-link`), `core/post-featured-image` | Each part shows unless its setting is `off` (`title`, `meta`, `author`, `date`, `categories`, `comments`, `featured_image`). The image goes above the title when `featured_placement="above"`, otherwise below the meta row. Text alignment defaults to left. Used in Theme Builder body layouts and post templates. |
| `et_pb_blog` | `core/query` + `core/post-template` (grid of 3 with `fullwidth="off"`, otherwise a list), image, title (level 2, linked), meta, excerpt, pagination | Image, author, date, categories, excerpt and pagination show unless their `show_*` setting is `off`. `include_categories` IDs → `taxQuery` (`all` and `current` leave the query unfiltered); `posts_number` (default 10) → `perPage`; `offset_number` → `offset`; `excerpt_length` characters → words. With `show_more="on"`, the "read more" link has its own line in a list (`showMoreOnNewLine`) and follows the excerpt in a grid. The meta line is 14px with 6px below it. When the body font size is not 14px, Divi sets the meta line and the excerpt to the desktop body size at every viewport, with the body line height in px. |
| `et_pb_sidebar` | `core/template-part` (`sidebar`) | The part holds the widget area converted to blocks (site-parts.md). |
| `et_pb_menu`, `et_pb_fullwidth_menu` | `core/navigation` bound to the classic menu's location (`__unstableLocation`), or with `ref` to the Navigation post made from the menu when menus become block menus (site-parts.md) | A module without `menu_id` uses the `primary-menu` location. `menu_style` → justification; menu font and colors. Run `wp divi-migration site` first, so the menu has its location or Navigation post. |
| `et_pb_slider`, `et_pb_fullwidth_slider` / `et_pb_slide` | a carousel target (site choice; core fallback: one Cover block per slide, stacked) with one slide per Divi slide: background, heading, text, button, image | Loop (always on), autoplay (`auto`, `auto_speed`, default 7000 ms; `auto_ignore_hover`), `min_height` as slide height, arrows and dots unless turned off (shown with two or more slides). Loop, autoplay, arrows and dots apply only on the carousel target. The `core-cover` fallback stacks the slides and keeps only the slide height. Slides inherit the slider's text and button settings; a slide heading defaults to h2 and a dark `background_layout` (the default) sets white text. Divi pads a slide by `0 6%` and its text box by `16% 8%` of the slide's content width (88% of the slide), so the block slide gets both: `14.08% 13.04%` by default. The slider's `custom_padding`, then the slide's, replace the text box padding per side, and a left or right value adds the 6% (`calc(6% + 20px)`). A slide with an image has no side padding on its text box. When Divi's `content_width` option is not 1080, Divi caps the text box at that width and centers it, so the sides become `calc()` values with `min(88%, <content_width>px)`. The slide text sits in a Group with the `text` variation: 1.14 × the body font size of each viewport (14px with the default body size), weight 400, `#333333` on a light background, and the body line height in px. |
| `et_pb_accordion` / `et_pb_accordion_item`, `et_pb_toggle` | an accordion target (site choice, or core `core/accordion`) | `toggle_level` (`title_level` on a toggle) → heading level (default h5); Divi opens the first item of an accordion and ignores the items' `open` setting (a toggle keeps its own); the module border applies to each item; toggle and body typography. Each item is a module, so in a column the module spacing wins over the accordion's own 10px: half the row gap as a share of the column on desktop (2.75% in a 4_4 column with gutter 3), 30px at 980px and below. The converter takes it from the column it converts. The title has no bottom padding and keeps 50px on its right for the icon. |
| `et_pb_contact_form` | the `form` target (WS Form by default) | The title becomes a heading (`title_level`, default h1); the form is rebuilt with the module's fields (or Divi's defaults: Name, Email Address, Message), half-width fields, placeholders for labels, the button text, the success message and the recipient. Divi's math captcha has no counterpart. Style the form plugin's fields in the theme to match. |
| `et_pb_wc_*` | see woocommerce.md | |

A module without a recipe keeps its text and child modules, gets a hidden marker (`<div hidden data-divi-module="et_pb_…">`) and appears in the report.

## No rule

These common modules have no rule in Divi Migration, so they appear in the report's `fallback` column. Settle each with the owner (SKILL.md, step 4), suggest the block below, and record the answer in the map's `modules`.

| Module | Suggested replacement |
|---|---|
| `et_pb_divider` | `core/separator` or `core/spacer` |
| `et_pb_tabs` | `core/tabs` (WordPress 7.1 and later; its `block.json` has no experimental flag and does not hide it from the inserter), or `core/details` blocks, or ask the owner |
| `et_pb_gallery` | `core/gallery` |
| `et_pb_pricing_tables` | columns of Groups, or ask the owner |
| `et_pb_social_media_follow` | `core/social-links` |
| `et_pb_signup` (email opt-in) | ask the owner; suggest the form plugin of the `form` target |
| `et_pb_search` | `core/search` |
| `et_pb_comments` | `core/comments` |
| `et_pb_map` | ask the owner |
| `et_pb_audio` | `core/audio` |
| `et_pb_circle_counter` | ask the owner |
| `et_pb_countdown_timer` | ask the owner |
| `et_pb_portfolio`, `et_pb_filterable_portfolio` | `core/query`, or ask the owner |
| `et_pb_post_content`, `et_pb_fullwidth_post_content` | `core/post-content` |
| `et_pb_fullwidth_post_title` | `core/post-title` with the featured image and meta, as for `et_pb_post_title` |
| `et_pb_team_member` | a Group with image, name, role, text and social links, or ask the owner |
| `et_pb_login` | ask the owner |
| `et_pb_fullwidth_header` | `core/cover` with heading, text and Buttons, or ask the owner |
| `et_pb_shop` | ask the owner (woocommerce.md) |
| `et_pb_posts_nav` | `core/post-navigation-link` |
| `et_pb_post_slider`, `et_pb_video_slider` | ask the owner |
| `et_pb_fullwidth_map` | ask the owner |
| `et_pb_fullwidth_portfolio` | `core/query`, or ask the owner |

Any other module (an extension's module, for example): see the report's `fallback` column.

## Shared settings

| Divi | Block |
|---|---|
| `background_color` (+ tablet/phone/hover) | `style.color.background` (preset when it matches the palette) |
| `background_image`, `_size`, `_position`, `_repeat` | `style.background` (`backgroundImage` with the attachment ID) |
| `custom_padding`, `custom_margin` (`top\|right\|bottom\|left\|…`) | `style.spacing.padding` / `margin`, per viewport. Content from before Divi 2.4 stores two parts: on a section, a desktop `custom_padding` of `0\|50px` is top and bottom (Divi rewrites it before rendering); on a row or inner row, it is top, right and bottom (see `et_pb_row`); on other modules, it is top and right |
| `border_width_all`, `border_color_all`, `border_style_all`, `border_radii` | `style.border` |
| `<prefix>_font` (`family\|weight\|italic\|uppercase\|underline\|smallcaps\|strikethrough…`), `_font_size`, `_line_height`, `_letter_spacing`, `_text_color` | `style.typography`, `style.color.text`; `em` line heights become unitless. A bare number is px, except a line height of 3 or less, which stays unitless (Divi's `et_builder_process_range_value()`) |
| `width`, `max_width`, `module_alignment` | a constrained Group around the module, `contentSize` and `justifyContent` per viewport; with both `width` and `max_width`, `contentSize` is the smaller one (a CSS `min()` for different units) |
| `disabled_on` (`phone\|tablet\|desktop`) | `metadata.blockVisibility.viewport` |
| `module_id`, `module_class`, `admin_label` | `anchor`, `className`, `metadata.name` |
| `@ET-DC@<base64 JSON>@` setting values | the plugin resolves `post_link_url_*` (permalink of the chosen post), `site_title`, `site_tagline` and `home_url`; any other type becomes empty with no report. Before converting, search the content for them: `wp db query "SELECT ID FROM wp_posts WHERE post_content LIKE '%@ET-DC@%'"` (use the site's table prefix), and decide each case with the owner. |
| `global_module="<id>"` | the plugin merges the page copy with the Library item: the Library's settings win, except those in the item's `_et_pb_excluded_global_options`. Content comes from the Library only when it is synced (inventory.md, "Builder content"). |
