# wp-divi-migration

Part of the [Divi Migration](../README.md) plugin repository. It ships in the plugin's `skill/` folder and is left out of the plugin ZIP.

A Claude Code skill that moves a site built with **Divi** to a **WordPress block theme**, so Divi can be switched off and every page edits in the block editor while the site looks the same. It covers the inventory of where Divi lives, Divi's layout model in `theme.json`, recipes from each Divi module to blocks, a migration map and a WP-CLI converter that turns `et_pb_` shortcodes into valid blocks on the server, Theme Builder layouts, menus, widget areas, child-theme functionality, WooCommerce, and the before/after parity loop.

**Requires [wp-design-to-block-theme](https://github.com/s3rgiosan/wp-skills/tree/main/wp-design-to-block-theme)** from the wp-skills repository. That skill holds the capture, compare and editor-parity scripts and the theme-building references; this one adds what is specific to Divi. Install both.

---

## Installation

### Marketplace

```bash
claude plugin marketplace add s3rgiosan/wp-skills
claude plugin marketplace add s3rgiosan/divi-migration
claude plugin install wp-divi-migration@s3rgiosan-divi-migration
```

To install it for one site repository, add `--scope project`:

```bash
claude plugin install wp-divi-migration@s3rgiosan-divi-migration --scope project
```

The skill declares `wp-design-to-block-theme@s3rgiosan-wp-skills` as a dependency. When you add the wp-skills marketplace first, the install adds the core skill too. When you do not, the install succeeds with this warning, and the skill does not load until you add the marketplace and install the core skill:

```
dependency "wp-design-to-block-theme@s3rgiosan-wp-skills" was not installed (no marketplace you have added lists it)
```

Update:

```bash
claude plugin marketplace update s3rgiosan-divi-migration
claude plugin update wp-divi-migration@s3rgiosan-divi-migration
```

### Shell scripts

Use the scripts when you do not use the marketplace. Run the commands in order from one directory. Install the core skill first.

```bash
git clone https://github.com/s3rgiosan/wp-skills.git
git clone https://github.com/s3rgiosan/divi-migration.git

# Default: ~/.claude
bash wp-skills/wp-design-to-block-theme/install.sh
bash divi-migration/skill/install.sh

# Custom Claude config dir
CLAUDE_CONFIG_DIR=~/.some-other-dir bash wp-skills/wp-design-to-block-theme/install.sh
CLAUDE_CONFIG_DIR=~/.some-other-dir bash divi-migration/skill/install.sh
```

Uninstall this skill:

```bash
bash divi-migration/skill/uninstall.sh
CLAUDE_CONFIG_DIR=~/.some-other-dir bash divi-migration/skill/uninstall.sh
```

---

## Usage

Ask for the migration with the site at hand:

- "Migrate this Divi site to a block theme."
- "Convert the Divi pages to blocks and compare them with the Divi site."
- "Turn the Divi Theme Builder footer into a template part."

The skill works on a copy of the site and keeps the Divi site as the reference.

---

## What's Inside

```
skill/
├── install.sh
├── uninstall.sh
├── README.md                         ← you are here
├── .claude-plugin/
│   └── plugin.json
└── skills/
    └── wp-divi-migration/
        ├── SKILL.md                  ← Divi workflow and deltas to the core workflow
        └── references/
            ├── inventory.md          ← where Divi stores pages, layouts, options and child-theme code
            ├── layout-model.md       ← Divi's spacing, widths, gutters and breakpoints → theme.json
            ├── module-map.md         ← one recipe per Divi module
            ├── converter.md          ← Divi Migration: commands, migration map, rules, targets
            ├── site-parts.md         ← header, footer, templates, Library items, menus, widgets, forms
            ├── child-theme.md        ← child-theme PHP → plugin, CSS → block markup
            ├── woocommerce.md        ← shop and product templates, template overrides
            └── parity.md             ← before/after loop, hand-over notes, cutover list
```

The skill composes with the other WordPress skills (`wp-block-themes`, `wp-patterns`, `wp-block-development`, `wp-interactivity-api`) when they are installed.

### Scope of the references

The references hold only what holds for any Divi site: how Divi 4.9.11 stores content, lays out pages and renders modules, and how the Divi Migration plugin converts them. WordPress core behavior lives in the core skill's references. A single site's choices, such as its class names, colors or content, do not belong here. The inventory and the capture measure them for each site, and each project's `CONVERSION-NOTES.md` records them.

---

## License

[MIT](../LICENSE)
