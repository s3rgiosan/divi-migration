# Divi's layout model in a block theme

Divi pages are **sections → rows → columns → modules**. Divi gives each level default spacing from its theme options and its stylesheet. Put those defaults in the theme (theme.json, style variations, a small stylesheet), so converted blocks carry only what a module sets itself. Then editors change the site's spacing in one place, as they did in Divi.

Measure before you write: open a builder page on the Divi site and read the computed styles of `.et_pb_section`, `.et_pb_row`, `.et_pb_column`, a module, `h1`–`h6`, `p` and `.et_pb_button` at desktop and phone widths. The theme options and the stylesheet interact (option values feed generated CSS with breakpoint-specific caps), so check the values below against the measured ones.

The spacing, width and gutter values in this file come from the Divi theme's `style.dev.css` and `functions.php`; the sources are named beside them. Text sizes, heading sizes and font values are not from the source: measure them.

## Breakpoints

Divi uses **980px** (tablet and below) and **767px** (phone). WordPress 7.1 reads breakpoints from theme.json:

```json
"settings": { "viewport": { "mobile": "767px", "tablet": "980px" } }
```

`style['@mobile']` then applies at `width <= 767px` and `style['@tablet']` at `767px < width <= 980px`. A Divi tablet value with no phone value applies to both, so it goes into both states.

## Widths

- **Content width** = row width: Divi rows are `width: 80%; max-width: 1080px`, centered (`style.dev.css`, `.et_pb_row`; `functions.php` sets `max-width` from the `content_width` option when it is not 1080). In theme.json: `settings.layout.contentSize: "min(80%, 1080px)"`. Sections use a constrained layout, so rows take that width.
- A row with its own width (`width`, `max_width`, per viewport) becomes a full-width Group whose constrained layout holds that width as `contentSize`, per viewport in `style['@tablet'].layout`.
- A section with its own width (`width`, `max_width`, `module_alignment`) sits in a full-width wrapper Group whose constrained layout holds that width and alignment. The section Group inside has no `align`, so its background takes the section width, as in Divi. Without `module_alignment` the section is at the left.
- `<main>` in templates needs a **default** layout; a constrained `<main>` boxes full-width sections.

## Spacing

| Level | Divi default (desktop) | Tablet / phone | Where it goes |
|---|---|---|---|
| Section | `padding: 4% 0` (`section_padding` option, default 4; capped in px from the viewport width 1.25 × `content_width`, see below) | 50px, or `tablet_section_height` / `phone_section_height` | style variation `section` on `core/group` (`blockGap: 0`) |
| Full-width section | no padding | no padding | variation `section-fullwidth` |
| Row | `padding: 2% 0` (`row_padding`, default 2; same cap rule) | 30px, or `tablet_row_height` / `phone_row_height` | variation `row` on `core/group` and `core/columns` |
| Inner row | by column type: 3.735% in a 3/4 column, 4.2415% in a 2/3 column, 5.82% in a 1/2 or 3/5 column, none otherwise | 30px, or `tablet_row_height` / `phone_row_height` (Divi applies them to `.et_pb_column .et_pb_row_inner` too, `functions.php`) | variation `row-inner` |
| Between modules | `margin-bottom` as a percentage of the column width, by column type. Gutter 3: 2.75% in a full-width column, 5.82% in 1/2, 9.27% in 1/3, 13.174% in 1/4, 3.735% in 3/4. All are about 30px at the default 1080px row width. Read other column types in the same file. The last module of a column has none. | 30px flat; none after the last module | the stylesheet: a bottom margin on each child but the last of row Groups and Columns. A module's own margin (an inline style) replaces it, as in Divi. Row Groups get `blockGap: 0`: a flow gap is a top margin on the next block, and margin collapsing keeps it even when a module sets its bottom margin to 0. |
| Between columns | gutter: `gutter_width` 1–4, default 3 → 0, 3%, 5.5%, 8% of the row (a custom `gutter_width` of 0 counts as 1; rows and specialty sections follow the same rule) | columns stack | Columns `blockGap.left` |

Sources in the Divi theme (`style.dev.css`): gutters, `.et_pb_gutters1` to `.et_pb_gutters4` `.et_pb_column { margin-right }` (0, 3%, 5.5%, 8%); module margins, `.et_pb_gutters3 .et_pb_column_<type> .et_pb_module { margin-bottom }` inside `@media (min-width: 981px)`; inner-row padding, `.et_pb_column_<type> .et_pb_row_inner { padding }` in the "Responsive Styles Desktop Only" block (`min-width: 981px`); the 30px and 50px values, the `max-width: 980px` block (`.et_pb_row, .et_pb_column .et_pb_row_inner { padding: 30px 0 }`, `.et_pb_column .et_pb_module { margin-bottom: 30px }`, `.et_pb_section { padding: 50px 0 }`). The module margins of other gutters differ (in a full-width column: gutter 1 none, gutter 2 1.5%, gutter 4 4%): read the rest in the same file.

Percentage padding resolves against the section's width, which is the viewport. At wide screens Divi replaces it with a fixed px value. `functions.php` sets `$large_content_width = intval( $content_width * 1.25 )` and, from that viewport width up, row padding `intval( $large_content_width * $row_padding / 100 )` px and section padding `intval( $large_content_width * $section_padding / 100 )` px. At the default `content_width` of 1080 the cap is 1350 × 2 / 100 = 27px for rows and 1350 × 4 / 100 = 54px for sections. Use `min(2vw, 27px)` for rows and `min(4vw, 54px)` for sections, and recompute both when `content_width` is not 1080.

Put each variation's desktop values in a partial, `styles/<slug>.json` with `blockTypes`:

```json
{
  "version": 3, "slug": "row", "title": "Row",
  "blockTypes": [ "core/group", "core/columns" ],
  "styles": {
    "spacing": { "padding": { "top": "min(2vw, 27px)", "bottom": "min(2vw, 27px)" }, "blockGap": "min(2.2vw, 30px)" }
  }
}
```

Put its tablet and phone values in `theme.json`, under `styles.blocks.<block>.variations.<slug>`, once for each block type of the partial. A partial loses `@tablet` and `@mobile`: WordPress 7.1 sanitizes each partial on its own, and the root of a partial has no viewport states. WordPress merges the `theme.json` values into the partial's styles and prints one `@media` rule per viewport for each block that uses the variation:

```json
"styles": { "blocks": {
  "core/group": { "variations": { "row": {
    "@tablet": { "spacing": { "padding": { "top": "30px", "bottom": "30px" }, "blockGap": "30px" } },
    "@mobile": { "spacing": { "padding": { "top": "30px", "bottom": "30px" }, "blockGap": "30px" } }
  } } },
  "core/columns": { "variations": { "row": {
    "@tablet": { "spacing": { "padding": { "top": "30px", "bottom": "30px" }, "blockGap": "30px" } },
    "@mobile": { "spacing": { "padding": { "top": "30px", "bottom": "30px" }, "blockGap": "30px" } }
  } } }
} }
```

Check the result on a phone-width page: the page CSS has `@media (width <= 767px)` rules for `.is-style-row--<n>`.

Do not give the Group variations an object `blockGap` (`{ "top", "left" }`) in a partial that also targets `core/columns`: the flow layout of a Group prints both values in one `margin-block-start`, which is invalid CSS. Set the column gap under `styles.blocks["core/columns"].variations.<slug>` only.

## Columns

- A k/n column spans k units and the k − 1 gutters between them: **width = f × (100% + gap) − gap**. For the 5.5% gutter: ½ = 47.25%, ⅓ = 29.6667%, ¼ = 20.875%, ¾ = 73.625% (`style.dev.css`, `.et_pb_gutters3 .et_pb_column_<type> { width }`). With the gutter as the Columns gap, columns and gaps add up to 100%.
- Divi sets `*{box-sizing:border-box}` (`style.dev.css`), so a column's padding is inside its width. Add the same reset to the theme's base styles. Without it, padding sits outside the flex basis: a padded column grows past its set width, the other columns shrink to fit, and the images inside them get smaller.
- Stacked columns are 30px apart (`.et_pb_column { margin-bottom: 30px }` at 980px and below). The converter writes it as the Columns block's `blockGap.top`, beside the gutter in `blockGap.left`. A block's own gap overrides a gap from a block style variation, so the variation alone does not reach these rows.
- Divi stacks columns at 980px; core stacks at 781px. Add to the stylesheet:

```css
@media (max-width: 980px) {
	.wp-block-columns:not(.is-not-stacked-on-mobile) { flex-wrap: wrap !important; }
	.wp-block-columns:not(.is-not-stacked-on-mobile) > .wp-block-column { flex-basis: 100% !important; }
}
```

## Text

- Divi headings: line-height 1em, `padding-bottom: 10px`, no margins; sizes come from the heading size option `body_header_size` (default 30): h1 = the size, h2 = `intval( size × .86 )`, h3 = `× .73`, h4 = `× .6`, h5 = `× .53`, h6 = `× .47` (`functions.php`). Tablet and phone sizes use `tablet_header_font_size` and `phone_header_font_size` with the same factors; Divi prints a tablet size only when it differs from 30 and from the desktop size, and a phone size only when it differs from 30 and from the tablet size. Paragraphs and lists: `padding-bottom: 1em`, last child 0. Lists in content have `line-height: 26px` (`style.dev.css`, `.entry-content ul`, `.et-l--body ul`). Bullet lists are indented 1em (`padding-left: 1em`); numbered lists have no indent and `list-style-position: inside`; a list nested in a bullet list is indented 20px, and in a numbered list 35px. The browser default indent is 40px, which wraps narrow list text onto more lines. Put the heading values in `styles.elements` and the paragraph spacing in the stylesheet, scoped to text groups and post content, with root `blockGap: 0`.
- Each text module becomes its own Group (variation `text`, `blockGap: 0`), so paragraph spacing stays inside the module and the module spacing stays between modules.
- Divi bumps body text on tablets and phones (`tablet_body_font_size`, `phone_body_font_size`): a media query on `body`. Divi prints a tablet size only when it differs from 14 and from the desktop size, and a phone size only when it differs from 14 and from the tablet size.
- Divi sets the body line height in `em` (`body_font_height`, default 1.7), so text with its own font size inherits the body line height in px. Slide text and listed-post paragraphs show this; the converter writes their line height in px.
- Posts in archives and search results: Divi prints the excerpt as bare text in the article, so it follows the body size per viewport. A blog module's excerpt is a paragraph and keeps the desktop body size (module-map.md, `et_pb_blog`).
- Set `settings.typography.fluid` to `false`. Divi does not scale type. With fluid type on, WordPress clamps every font size that the converter writes on a block: at 390px a 50px heading renders at about 31px, and text modules get about 40% shorter. This replaces the fluid type advice of the core `references/theme-json-tokens.md`, and the fluid default of theme templates. Divi's tablet and phone sizes come through as `style['@tablet']` and `style['@mobile']` on each block.

## Buttons, links, colors, fonts

- `all_buttons_*` options → `styles.elements.button` (colors, border width and color, radius, font, size, uppercase) and its `:hover`. Divi's default button padding is `0.3em 1em`.
- `link_color`, `font_color`, `header_color` → `styles.elements.link`, `styles.color.text`, `styles.elements.heading`.
- Palette: start from `divi_color_palette`, then add the colors the content uses most (count hex values across builder content). The converter matches module colors against the palette, so a well-chosen palette turns most colors into presets.
- Fonts: Google fonts and Divi uploads (`et_uploaded_fonts`) go into `assets/fonts` and `settings.typography.fontFamilies` with `fontFace`. The converter matches font names to these families.
