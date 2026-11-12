# Parity loop

## Setup

- **Before:** the Divi site, untouched.
- **After:** a copy with its own database (a branch environment), where the theme is active and content is converted. Iterate there; restart from the Divi content when a rule changes (the converter re-converts from its backup).

## Guard the copy

The copy holds the live site's settings, so it can send mail to customers and call live services. Stop both with a must-use plugin before you work on the copy (workflow step 0), for example `wp-content/mu-plugins/copy-guard.php`:

```php
<?php
/**
 * Plugin Name: Copy guard
 * Description: Stops outgoing mail and outgoing HTTP requests on a copy of the site.
 */

// Report each message as sent, so callers treat it as delivered.
add_filter( 'pre_wp_mail', '__return_true' );

add_filter(
	'pre_http_request',
	static function ( $response, array $args, string $url ) {
		$host      = (string) wp_parse_url( $url, PHP_URL_HOST );
		$allowlist = [
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'wordpress.org',
			'youtube.com',
			'vimeo.com',
		];

		foreach ( $allowlist as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return $response;
			}
		}

		return new WP_Error( 'copy_guard', sprintf( 'Request to %s blocked on the copy.', $host ) );
	},
	10,
	3
);
```

- `pre_wp_mail` receives `null` and the `wp_mail()` arguments. Any value other than `null` stops `wp_mail()`, which returns that value.
- `pre_http_request` receives `false`, the request arguments and the URL. Any value other than `false` stops the request: return a `WP_Error` to block it, and return `$response` to let it run.
- The allowlist holds the copy's own host (cron and loopback requests), `*.wordpress.org` (updates and translations) and the oEmbed hosts that the content embeds. Add a host only when the copy needs it. Core fetches YouTube and Vimeo embeds from `www.youtube.com` and `vimeo.com`.

An SMTP catcher such as Mailpit is not enough. It catches only mail that PHP sends through SMTP or `sendmail`. A mail plugin with an API mailer sends each message over HTTPS to the provider's API. WP Mail SMTP's Gmail mailer, for example, uses the Google API client with its own HTTP library, so `pre_http_request` does not see it either. `pre_wp_mail` stops it, because WP Mail SMTP keeps core's `wp_mail()`. A plugin that replaces the pluggable `wp_mail()` skips `pre_wp_mail`: disable its mailer in its settings instead.

Also remove the live keys from the copy (payment, mail, analytics, form services). A service SDK with its own HTTP library goes around `pre_http_request`.

## Each pass

1. Capture before and after for each route with core `scripts/capture.mjs`, at desktop, tablet and phone widths with `--breakpoints 1440,900,390` (the script's default is `390,768,1280`; 900 sits between Divi's 767px and 980px breakpoints). Use the same breakpoints on both sites. The script scrolls each page before it captures. Hide cookie banners and other overlays in both. Run these from the working folder outside the theme:

   ```bash
   node <core>/scripts/capture.mjs --routes routes.txt --base <divi-url> --breakpoints 1440,900,390 --out ./capture/before
   node <core>/scripts/capture.mjs --routes routes.txt --base <copy-url> --breakpoints 1440,900,390 --out ./capture/after
   ```

   Sites on `.test`, `.localhost`, `localhost` and `127.0.0.1` need no flag for HTTPS certificates. For a remote HTTPS site with an untrusted certificate, add `--insecure`.

2. Compare with core `scripts/compare.mjs`. Pair route folders whose slugs differ with `--map before=after`. Read the screenshots side by side as well: a missing section shows as a height difference long before a pixel diff makes sense.

   ```bash
   node <core>/scripts/compare.mjs ./capture/before ./capture/after
   ```
3. For each difference, find its layer:
   - a theme default (spacing, type, color) → theme.json, a variation or the stylesheet;
   - one module's setting not carried over → the converter rule; re-convert;
   - a child-theme style → port it (child-theme.md);
   - content that never converted → the report's fallback list.
4. Measure, don't guess: read computed boxes (`getBoundingClientRect`) and styles of the same element on both sites. Header height, logo box, content column x and width, and the first section's y catch most layout errors.
5. Check the editor: every converted page reopens with no invalid blocks (converter.md, "Checking the result"), and the canvas matches the front end (core `references/editor-parity.md`). Capture the editor and compare it with the front end, then check the cascade order of the theme stylesheet:

   ```bash
   WP_USER=<admin> WP_PASSWORD=<password> node <core>/scripts/capture-editor.mjs --routes routes.txt --base <copy-url> --breakpoints 1440,900,390 --out ./capture/editor
   node <core>/scripts/compare.mjs ./capture/after ./capture/editor --editor
   node <core>/scripts/cascade-order.mjs --routes routes.txt --base <copy-url> --breakpoints 1440,900,390
   ```

   `capture-editor.mjs` follows the same certificate rule as `capture.mjs`: a remote HTTPS site with an untrusted certificate needs `--insecure`.

Each gate reports PASS, FAIL or NOT RUN. NOT RUN is never a pass: list it in the hand-over notes with the reason.

## Accepted differences

Some differences are deliberate: animations (counters, entrance effects) dropped, a Divi icon font replaced by an SVG, the mobile menu's "Select Page" label. List each with the reason and show the list to the site owner. Log them in the project's task tracker.

## Hand-over notes

Write `CONVERSION-NOTES.md` in the theme, as the core workflow does (core SKILL.md, step 10). It lists:

- the route and template map;
- the migration map's decisions and who made each one (module replacements, targets, menus, forms);
- the differences accepted at the gates, with the reason;
- each module left as a fallback, and the copy still to supply;
- the gate results, including every NOT RUN and why.

## Cutover notes

Hand over the steps production needs, in order:

1. Back up the database.
2. Install and activate the block theme, the site plugin and the block plugins the content uses.
3. Check that production runs WordPress 7.1 or later and PHP 8.2 or later (`wp core version`, `wp cli info`), the plugin's minimum versions.
4. Install and activate Divi Migration (converter.md; the same ZIP or Composer package as on the copy).
5. Run `wp divi-migration site --theme=<divi stylesheet or child> --map=<file>` (`Divi` when there is no child theme): logo and menus. Classic menus (the default) need nothing more. Block menus are an exception to the core rule of no database IDs in theme files: their Navigation `ref` IDs differ per database, so remap them in the parts.
6. List the pages without the builder and assign them the template with the title (site-parts.md, "Page titles"). Do this before `convert`.
7. Run `wp divi-migration convert --map=<file> --post_type=<all builder types> --dry-run` on production first, and read the report.
8. Run `wp divi-migration convert --map=<file> --post_type=<all builder types> --user=<admin>` (products included), then the classic posts pass (`--classic --post_type=<types>`) if there is one. With a form target, this creates the forms. The theme's form helper finds each form by its label (site-parts.md, "Forms in templates and parts"), so check the labels on production. Connect the forms to the email service list.
9. Move the enabled integration code (verification tags) to its new home (site-parts.md, "Integration code").
10. Deactivate Divi, the child theme, the Divi-only plugins (inventory.md, "Plugins tied to Divi") and the editor switches (inventory.md, "Editor switches"). Production gets the replacements the owner chose on the copy.
11. Run `wp media regenerate --only-missing --skip-delete --yes` once, so existing uploads record the theme's image sizes (site-parts.md).
12. Run the parity checks against production URLs.
13. Deactivate Divi Migration only after the owner accepts the result.

Deactivating or deleting the plugin keeps its data: the `_s3s_divi_migration_*` post meta (the backups) and the `s3s_divi_migration_forms` option. `reset` is a command of the plugin, so it needs the plugin active: keep it active until the owner accepts. Delete the meta and the option by hand once the migration is final.
