---
name: wp-divi-migration
description: >
  Use when moving a WordPress site built with Divi (the Elegant Themes theme and Divi Builder)
  to a block theme and the block editor, so Divi can be switched off and the site looks the same.
  Also converts the classic editor content of the same site. Triggers: "migrate Divi to blocks",
  "Divi to block theme", "convert Divi shortcodes", "get rid of Divi", "replace Divi with
  Gutenberg", "et_pb_ shortcodes to blocks", a Divi site paired with a block theme or FSE
  request, or Divi Theme Builder layouts, Library items or a Divi child theme to carry over.
  Requires wp-design-to-block-theme.
  Not for Divi 5, not for classic (PHP template) themes, and not for plain design-to-theme
  work (use wp-design-to-block-theme).
---

# Divi → WordPress Block Theme

> **Requires `wp-design-to-block-theme` 1.5.0 or later.** That skill holds the capture, compare and editor-parity scripts and the theme-building references. This skill adds what is specific to Divi. Read its files directly and do not invoke it as a skill. "Core `scripts/<file>`" and "core `references/<file>`" mean files in its folder. It ships in the [wp-skills](https://github.com/s3rgiosan/wp-skills) repository, not in this one. Locate it by layout: `../wp-design-to-block-theme/` from this skill's folder once both skills are installed, each with its own `install.sh`; in a wp-skills checkout, `wp-design-to-block-theme/skills/wp-design-to-block-theme/`; after a marketplace install, the two skills are not siblings: read `plugins/installed_plugins.json` in the Claude config dir, take the `installPath` of `wp-design-to-block-theme@s3rgiosan-wp-skills`, and use its `skills/wp-design-to-block-theme/` folder.

> **Tested with Divi 4.9.11** and Divi Migration 1.0.0 or later. Every Divi value in this skill comes from that version. Divi 5 is not supported: it stores content differently, and nothing here is verified against it. With a different Divi version, check layout defaults and module output against the old site before you trust the references.

## Overview

A Divi site stores its pages as `[et_pb_*]` shortcodes with hundreds of design settings per module, renders them through the Divi theme, and keeps site-wide design in Divi's theme options, Theme Builder, Library and often a child theme. The goal is a block theme plus core blocks (and a few chosen block plugins) that render the same pages, edit visually in the block editor, and run with Divi and its child theme switched off.

The same sites often hold classic editor content (posts and pages without Divi modules or blocks). The converter turns it into blocks in the same run (step 5, "Classic content").

Split the work:

- **Mechanical:** inventory, conversion of shortcodes to blocks, captures and comparisons. The [Divi Migration](https://github.com/s3rgiosan/divi-migration) plugin, which ships this skill in its `skill/` folder, does the inventory and the conversion; scripts do the captures.
- **Judgment:** the theme (tokens, layout defaults, header, footer, templates), which block a module becomes, and what a difference in the comparison means.

The Divi site stays untouched as the "before" reference. Work happens on a copy (a branch environment with its own database), so every iteration can start again from the Divi content.

## Workflow

0. **Set up.**
   - Make a copy of the site with its own database. Keep the Divi site untouched. If there is no copy, stop and ask the user where to make it.
   - Export the copy's database before the first `convert`: `wp db export <path outside the repository>/before-convert.sql`. A bad run can then be rolled back. `wp divi-migration reset <id>...` or `reset --all` restores the original post content.
   - Install Divi Migration on the copy and activate it. Download the release ZIP from github.com/s3rgiosan/divi-migration and run `wp plugin install <zip> --activate`, or use Composer (`s3rgiosan/divi-migration`, as the plugin README describes). It needs WordPress 7.1 and PHP 8.2 (`references/converter.md`).
   - Install WS Form when contact forms or opt-ins must convert.
   - On the copy, disable outgoing mail and remove live API keys (payment, mail, analytics, form services), so the copy cannot send mail or charge anyone. Add the must-use plugin from `references/parity.md`, "Guard the copy": it stops `wp_mail()` and blocks HTTP requests to hosts outside an allowlist.
1. **Inventory.** Follow `references/inventory.md`. Write each finding in the project's tracker, never in this skill; each one is a task.
   - Version check: run `wp divi-migration analyze`. It reports the installed Divi version and the `_builder_version` values in the content.
   - Census: modules per post type, Theme Builder layouts, Library items and global modules.
   - Settings: theme options (layout, fonts, colors, header, integration code), menus and widget areas.
   - Code: the child theme's templates, CSS and PHP, and the Divi-dependent plugins.
   - Post types: take the builder post types from the `analyze` and `list` output. You pass them to `convert` in step 5.
   - Modules without a converter and Divi-only plugins: note them for step 4 (`references/inventory.md`, "Plugins tied to Divi" defines the term).
   - Editor switches: the Classic Editor plugin and other plugins, options or filters that turn off the block editor (`references/inventory.md`, "Editor switches"). Each one blocks the migration.
2. **Capture the Divi site.** Run core `scripts/capture.mjs` from a working folder outside the theme, where Playwright is installed (core SKILL.md, "Scripts").
   - Write a routes file (`routes.txt`): home, every builder page, one post, one archive, search, 404, shop, a product, cart, checkout, account.
   - Run `node <core>/scripts/capture.mjs --routes routes.txt --base <divi-url> --breakpoints 1440,900,390` (desktop, tablet and phone: 900 sits between Divi's 767px and 980px breakpoints; the default is `390,768,1280`).
   - The script scrolls each page before it captures, so lazy images and entrance animations render.
   - Sites on `.test`, `.localhost`, `localhost` and `127.0.0.1` need no flag for HTTPS certificates. For a remote HTTPS site with an untrusted certificate, pass `--insecure` to core `capture.mjs` and `capture-editor.mjs`.
3. **Build the theme shell.** Reuse these core steps and skip the others:
   - Core step 3: run `scripts/css-tokens.mjs` on the capture, resolve its warnings, and consolidate the tokens (core `references/theme-json-tokens.md`).
   - Core step 4: scaffold the theme (`style.css`, `theme.json`, `templates/`, `parts/`, `styles/`, `assets/`, `functions.php`, the front-end stylesheet) with the **wp-block-themes** guidance.
   - Put Divi's layout model in theme.json, style variations and a small stylesheet (`references/layout-model.md`): content width, spacing per viewport, gutters, module spacing, text spacing, breakpoints (`settings.viewport`), body and heading defaults, fixed type sizes (`settings.typography.fluid: false`), buttons, palette and fonts.
   - Register the menu locations found in the inventory (`references/site-parts.md`, "Menus").
   - Add empty `parts/header.html`, `parts/footer.html` and the templates. Step 5 fills them from `markup` output. A site without Theme Builder layouts has no `markup` output for them: build the header, footer and templates by hand (`references/site-parts.md`, "Without Theme Builder layouts").
   - Skip core steps 5 to 7 (layout by hand, patterns, Pages and menus by hand): the converter writes the content, the parts and the menus. Use core step 8 only for behaviors that no block plugin covers.
   - Save Divi's widget areas while Divi is still active: `wp option get sidebars_widgets --format=json > sidebars-widgets.json`. The theme switch moves every widget to the inactive widgets (`references/site-parts.md`, "Widget areas").
   - Activate the block theme on the copy at the end of this step. Step 5 needs it active.
4. **Write the migration map.** Record the project's decisions in `divi-migration.json`, in the site's own repository (`references/converter.md`, "Migration map"): the class names of the theme's layout variations, palette and font slugs for Divi values the theme does not match exactly, modules to skip or replace, shortcodes that stop working without Divi or its plugins (`references/site-parts.md`, "Opt-in forms"), and the translation of every class the content carries (`references/converter.md`, "Class names"). Each module type follows a recipe in `references/module-map.md`. List every shortcode in the content and check which ones the new site still registers (`references/inventory.md`). The site owner decides these choices. Ask each one as a question with a suggestion:
   - Which block plugin handles carousels and accordions (`targets`)? Suggest the first target the converter finds registered, or the core fallbacks. Tables use `core-table` unless the map picks `flexible-table`.
   - Do menus stay classic or become block menus (`menus`)? Suggest classic: editors keep Appearance → Menus.
   - Forms: the `form` target converts Divi's contact form and Bloom opt-ins (`references/converter.md`, "Extending it"). Does the owner want a different form plugin?
   - Which replacement fits each module without a rule and each Divi-only plugin? Suggest one from `references/module-map.md`. This is the one rule for every such choice in the skill.
   - Can each editor switch from step 1 go? Suggest yes: the converted content needs the block editor. Deactivate the switches on the copy before step 7.
5. **Convert content.** The conversion runs on the server, no browser needed.
   - Site settings: `wp divi-migration site --theme=<divi stylesheet or child> --map=divi-migration.json` (logo and menus; it prints a table of the menu locations and the integration code to move). Use `Divi` when the site has no child theme.
   - Page titles: before the first `convert`, list the pages without the builder and give them a template with the title (`references/site-parts.md`, "Page titles").
   - Dry run: `wp divi-migration convert --map=divi-migration.json --post_type=<all builder types> --dry-run`. Read the report. Its `fallback` column lists the modules without a rule: settle each as in step 4, and record the answer in the map's `modules` key.
   - Convert: run the same command without `--dry-run`. Add `--user=<admin>` to `convert` and `markup` when WS Form is active, because both create the forms.
   - Parts: save the output of `wp divi-migration markup` for Theme Builder layouts, Library items and widget areas into the shell from step 3 (`references/site-parts.md`; a Library item becomes a pattern, "Library items"). Before `markup --widget-area`, restore the widget areas from the file that step 3 saved.
   - Classic content: find the public post types with `wp post-type list --public --field=name`, and confirm the list with the owner. Ask whether to include WooCommerce products (WooCommerce edits product descriptions as classic content); the default answer is no, so add `product` to `--post_type` only on a yes. Ask the same about the classic places that the inventory lists (`references/inventory.md`, "Classic content outside post content"). Run `wp divi-migration convert --classic --map=divi-migration.json --post_type=<types>`. Add `--status=<statuses>` to change the statuses (default `publish,draft,private,future,pending`).
6. **Carry over the rest.** Widget areas, integration code (`references/site-parts.md`); child-theme functionality into a plugin (`references/child-theme.md`); WooCommerce (`references/woocommerce.md`). The core steps skipped in step 3 still set these rules:
   - Post types, taxonomies and meta live in a site plugin or mu-plugin, not in the theme (core `references/content-model.md`, "Raise these decisions", holds the one fallback).
   - Keep ACF field groups only when the site already uses ACF. Otherwise register the meta with `register_post_meta()` and `show_in_rest`, and fill it in a sidebar panel on block-editor post types (core `references/content-model.md`, "Meta keys").
   - A custom block follows core step 6: its `edit` renders the front-end markup while the block is unselected, and shows its controls only when selected. Raise a custom block with the owner first.
7. **Parity loop.** Compare captures before and after, route by route, desktop and phone; check the editor for invalid blocks; fix the theme, the map or the converter rule, convert again, repeat (`references/parity.md`). Log every difference you accept and why. The migration reproduces the old site: log its inconsistencies (broken links, untranslated strings, stray markup, unused widgets) as tasks in the project's tracker, and fix them after the migration.
8. **Switch off Divi.** With parity reached, deactivate Divi, the child theme and the Divi-only plugins. Do this on the copy only; the same steps go in the production cutover list (`references/parity.md`, "Cutover notes"). Replace each Divi-only plugin with the choice recorded in step 4. Other plugins stay unless the owner asks. Run the checks again and hand over the list of production steps.

## References

| File | Read it for |
|---|---|
| `references/inventory.md` | where Divi stores pages, layouts, options and child-theme code; the inventory queries |
| `references/layout-model.md` | Divi's default spacing, widths, gutters and breakpoints, and their theme.json counterparts |
| `references/module-map.md` | one recipe per Divi module: target blocks and settings mapping |
| `references/converter.md` | Divi Migration: commands, migration map, block markup and saves, rules, targets and settings filters, how it converts, WordPress 7.1 pitfalls |
| `references/site-parts.md` | Theme Builder header and footer, the default header, footer and templates without Theme Builder, Library items, menus, logo, widget areas, child-theme templates, integration code |
| `references/child-theme.md` | moving a child theme's PHP into a plugin, and its CSS onto block markup |
| `references/woocommerce.md` | product layouts built with WooCommerce modules, shop and product templates, template overrides |
| `references/parity.md` | the before/after loop with exact commands, accepted differences, editor checks, hand-over notes and cutover notes |

## Common Mistakes

The core SKILL.md list applies. In addition:

- Converting on the only copy of the site. Keep the Divi site as the reference and convert a copy; keep the original Divi content in post meta so a page can be converted again.
- Writing block markup by hand from attributes. Use `wp divi-migration convert` and `markup`, whose saves write what each block's `save()` writes; a block plugin with static blocks needs a save (`s3s_divi_migration_block_saves`), or its posts are skipped. Open the converted pages in the editor to check for invalid blocks.
- Putting project decisions in code. Settings, targets, slugs and module replacements go in the migration map, so the conversion can run again on production with the same result.
- Mapping every Divi setting to inline styles. Put Divi's defaults in theme.json and style variations, and keep only each module's own settings on its block.
- Ignoring tablet and phone values. Divi stores them as `<setting>_tablet` / `_phone`, active only when `<setting>_last_edited` starts with `on`; they map to `style['@tablet']` and `style['@mobile']`.
- Dropping a module that has no recipe. Keep its text, mark the spot, and list it in the report.
- Keeping legacy class names in the content (`p1`, `et_pb_*`, `big-button`). Each class becomes a block style, preset classes or block attributes, or goes.
- Fixing the old site's inconsistencies during the migration. Reproduce them, log each one as a task, and fix them afterwards.
- Choosing a replacement, a carousel or accordion plugin, or the menu mode without the site owner (step 4).
- Keeping the Classic Editor plugin, or another editor switch, active. Converted posts then open in TinyMCE, and a save can break their blocks.
- Leaving behavior in the child theme. Hooks, shortcodes, field groups and template overrides stop working the moment the child theme is switched off.
- Copying site data into the skill, a public repository or a commit message. Settings tokens, IDs and values from the site's database stay in the site's own repository or its database.
