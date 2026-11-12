# The child theme

Switching to the block theme turns the child theme off, and everything in it stops: hooks, shortcodes, field groups, template overrides, styles. Inventory it (inventory.md), then sort each item.

## Behaviour → a site plugin

Hooks that change what the site does (checkout fields, order meta, payment texts, redirects, booking tweaks, field group registrations, shortcodes, head scripts) are not presentation. Move them to a site plugin or must-use plugin, so the next theme change keeps them:

- Keep every meta key, option name, field key and shortcode tag exactly as it is: existing orders, posts and settings depend on them.
- Register on the same hook the child theme used (often `after_setup_theme`); the order against other plugins' callbacks can matter.
- Prefer current APIs where the old ones are deprecated (order meta through the order object works with and without HPOS), and keep the result identical.
- Template helpers called from template overrides stay global functions.
- Verify each hook is registered at runtime, and exercise the flows that matter (a checkout with a custom field, a shortcode on a page).

Skip what the platform already does (head cleanup, emoji, heartbeat) only after checking the site's must-use plugins really do it; list what is dropped and why.

## Template overrides → the block theme

WooCommerce (and other plugins) load template overrides from the active theme's folder. Copy `woocommerce/` from the child theme into the block theme unchanged, then compare each file's `@version` with the plugin's own copy and report outdated ones; updating them is a separate task.

## Templates → block templates

PHP templates (`single-post.php`, page templates) become block templates. A template that builds Divi shortcodes at render time converts like content (site-parts.md).

## CSS → the block theme

Read the child stylesheet rule by rule:

- Rules for Divi structure (`#main-content`, `.et_pb_row`, `.et_pb_text_inner`) usually become theme.json or variation values, or vanish.
- Rules for site components (a styled section heading, quote marks, a callout box, card grids) move to the theme stylesheet, rewritten against the block markup. Content classes (`module_class` values) do not carry over as they are: move each component rule to CSS on `.is-style-<slug>`, and translate the class through the map's `classes` (converter.md, "Class names").
- Rules for plugins (forms, WooCommerce, opt-in forms) move with their selectors, re-scoped where Divi wrappers disappear.
- Inline `data:` images in the CSS become files in `assets/images`.
