<?php

namespace S3S\WP\DiviMigration;

use WP_Classic_To_Block_Menu_Converter;
use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Moves Divi's site-wide settings to their block theme counterparts: the
 * logo to the Site Logo, and the classic menus to the same locations in the
 * active theme or to Navigation posts.
 */
class SiteSettings {

	/**
	 * Sets the site logo from Divi's logo option.
	 *
	 * A logo that is already set stays unless `$force` is true.
	 *
	 * @param bool $force Whether to replace a logo that is already set.
	 * @return array{attachment: int, kept: bool}|WP_Error Attachment ID of the logo, and whether it is the logo that was already set.
	 */
	public static function import_logo( bool $force = false ): array|WP_Error {
		$options = get_option( 'et_divi', [] );
		$url     = is_array( $options ) ? (string) ( $options['divi_logo'] ?? '' ) : '';

		if ( '' === $url ) {
			return new WP_Error( 's3s_divi_migration_no_logo', 'Divi has no logo set.' );
		}

		$current = (int) get_option( 'site_logo', 0 );

		if ( $current > 0 && ! $force ) {
			return [
				'attachment' => $current,
				'kept'       => true,
			];
		}

		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id <= 0 ) {
			return new WP_Error( 's3s_divi_migration_logo_not_found', sprintf( 'The logo is not in the media library: %s', $url ) );
		}

		update_option( 'site_logo', $attachment_id );

		return [
			'attachment' => $attachment_id,
			'kept'       => false,
		];
	}

	/**
	 * Creates a Navigation post for each classic menu assigned to a location
	 * in the Divi theme.
	 *
	 * A Navigation post that already exists for a menu, matched by slug, is
	 * updated, so the import can run again.
	 *
	 * @param string $stylesheet Theme whose menu locations to read. Defaults to the
	 *                           active theme when it runs on Divi. Required when the
	 *                           active theme does not run on Divi: the fallback, the
	 *                           `theme_switched` option, is cleared by core on the
	 *                           first load after a theme switch.
	 * @return array<string, array{menu: string, navigation: int}|WP_Error> Results keyed by menu location.
	 */
	public static function import_menus( string $stylesheet = '' ): array {
		$results = [];

		foreach ( self::menu_locations( $stylesheet ) as $location => $menu_id ) {
			$menu = wp_get_nav_menu_object( $menu_id );

			if ( ! $menu instanceof WP_Term ) {
				continue;
			}

			$content = WP_Classic_To_Block_Menu_Converter::convert( $menu );

			if ( is_wp_error( $content ) ) {
				$results[ $location ] = $content;
				continue;
			}

			$blocks = trim( (string) $content );

			if ( '' === $blocks ) {
				continue;
			}

			$navigation_id = self::save_navigation( $menu, $content );

			$results[ $location ] = is_wp_error( $navigation_id )
				? $navigation_id
				: [
					'menu'       => $menu->name,
					'navigation' => $navigation_id,
				];
		}

		return $results;
	}

	/**
	 * Keeps the classic menus: assigns each one to the same location in the
	 * active theme, which must register those locations.
	 *
	 * Only a menu that exists is assigned. A location that already has a menu
	 * that exists keeps it unless `$force` is true; the result flags that
	 * location as kept and names the menu it keeps.
	 *
	 * @param string $stylesheet Divi theme whose menu locations to read (see `import_menus()`).
	 * @param bool   $force      Whether to replace locations that already have a menu.
	 * @return array<string, array{menu: string, kept: bool}> Menus keyed by location.
	 */
	public static function keep_menus( string $stylesheet = '', bool $force = false ): array {
		$divi_locations = self::menu_locations( $stylesheet );

		if ( [] === $divi_locations ) {
			return [];
		}

		$locations = get_theme_mod( 'nav_menu_locations', [] );
		$locations = is_array( $locations ) ? $locations : [];
		$results   = [];
		$changed   = false;

		foreach ( $divi_locations as $location => $menu_id ) {
			$menu = $menu_id > 0 ? wp_get_nav_menu_object( $menu_id ) : false;

			if ( ! $menu instanceof WP_Term ) {
				continue;
			}

			$current_id = (int) ( $locations[ $location ] ?? 0 );
			$current    = $current_id > 0 ? wp_get_nav_menu_object( $current_id ) : false;

			if ( $current instanceof WP_Term && ! $force ) {
				$results[ $location ] = [
					'menu' => $current->name,
					'kept' => true,
				];
				continue;
			}

			$locations[ $location ] = $menu_id;
			$changed                = true;
			$results[ $location ]   = [
				'menu' => $menu->name,
				'kept' => false,
			];
		}

		if ( $changed ) {
			set_theme_mod( 'nav_menu_locations', $locations );
		}

		return $results;
	}

	/**
	 * Returns the code Divi adds to every page from its Integration options.
	 *
	 * The code has no block theme counterpart; it moves to a plugin or the
	 * theme by hand.
	 *
	 * @return array<string, string> Code keyed by Divi option name.
	 */
	public static function integration_code(): array {
		$options = get_option( 'et_divi', [] );
		$code    = [];

		foreach ( [ 'divi_integration_head', 'divi_integration_body', 'divi_integration_single_top', 'divi_integration_single_bottom' ] as $key ) {
			$value = is_array( $options ) ? trim( (string) ( $options[ $key ] ?? '' ) ) : '';

			if ( '' !== $value ) {
				$code[ $key ] = $value;
			}
		}

		return $code;
	}

	/**
	 * Returns a theme's menu locations.
	 *
	 * @param string $stylesheet Theme stylesheet, or empty for the default (see `import_menus()`).
	 * @return array<string, int> Menu IDs keyed by location.
	 */
	private static function menu_locations( string $stylesheet ): array {
		if ( '' === $stylesheet ) {
			$template   = get_template();
			$stylesheet = 'Divi' === $template ? get_stylesheet() : (string) get_option( 'theme_switched' );
		}

		$mods      = get_option( 'theme_mods_' . $stylesheet, [] );
		$locations = is_array( $mods ) ? ( $mods['nav_menu_locations'] ?? [] ) : [];

		return is_array( $locations ) ? array_map( 'intval', $locations ) : [];
	}

	/**
	 * Creates or updates the Navigation post for a menu.
	 *
	 * @param WP_Term $menu    Classic menu.
	 * @param string  $content Serialized navigation link blocks.
	 * @return int|WP_Error Navigation post ID.
	 */
	private static function save_navigation( WP_Term $menu, string $content ) {
		$found    = get_posts(
			[
				'post_type'        => 'wp_navigation',
				'name'             => $menu->slug,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);
		$existing = $found[0] ?? null;

		$post = [
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => $menu->name,
			'post_name'    => $menu->slug,
			'post_content' => wp_slash( $content ),
		];

		if ( $existing instanceof WP_Post ) {
			$post['ID'] = $existing->ID;
			return wp_update_post( $post, true );
		}

		return wp_insert_post( $post, true );
	}
}
