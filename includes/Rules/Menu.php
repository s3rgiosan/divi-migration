<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a menu module, or a full-width menu module, into a Navigation block.
 *
 * With classic menus (the `menus` setting), the block is bound to the menu
 * location the classic menu is assigned to, through `__unstableLocation`;
 * the theme renders that location's menu. With Navigation menus, the block
 * points to the Navigation post made from the same classic menu (matched by
 * slug). A module without a menu ID uses the `primary-menu` location.
 * `wp divi-migration site` sets up either one; without it, the block
 * shows WordPress's fallback menu.
 */
class Menu extends Module {

	/**
	 * Menu location of a menu module without a menu ID.
	 */
	const PRIMARY_LOCATION = 'primary-menu';

	/**
	 * Divi menu styles, mapped to flex justification.
	 */
	const JUSTIFY = [
		'left_aligned'         => 'left',
		'centered'             => 'center',
		'inline_centered_logo' => 'center',
	];

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$style = new StyleSet();

		$this->typography( $node, 'menu', $style, $converter->presets );
		$this->design( $node, $style, $converter->presets, [], $converter );
		$this->note_unconverted( $node, $converter, 'menu logo', [ 'logo' ] );

		$attributes = array_merge(
			$this->common_attributes( $node ),
			[
				'overlayMenu' => 'mobile',
				'layout'      => [
					'type'           => 'flex',
					'justifyContent' => self::JUSTIFY[ $node->attr( 'menu_style', 'left_aligned' ) ] ?? 'left',
				],
				'style'       => $style->to_style(),
			]
		);

		// A menu without an ID shows the menu of the primary menu location.
		$by_location = '' === $node->attr( 'menu_id' );
		$menu_id     = (int) $node->attr( 'menu_id' );

		if ( 'navigation' === $converter->settings->menus ) {
			if ( $by_location ) {
				$menu_id = (int) ( get_nav_menu_locations()[ self::PRIMARY_LOCATION ] ?? 0 );
			}

			$navigation_id = $this->navigation_id( $menu_id );

			if ( $navigation_id > 0 ) {
				$attributes['ref'] = $navigation_id;
			}
		} else {
			$location = $by_location ? self::PRIMARY_LOCATION : $this->location( $menu_id );

			if ( '' !== $location ) {
				$attributes['__unstableLocation'] = $location;
			}
		}

		return [ Spec::block( 'core/navigation', $attributes ) ];
	}

	/**
	 * Returns the active theme's location a classic menu is assigned to, or an
	 * empty string.
	 *
	 * @param int $menu_id Classic menu term ID.
	 * @return string
	 */
	private function location( int $menu_id ): string {
		$location = array_search( $menu_id, array_map( 'intval', get_nav_menu_locations() ), true );

		return is_string( $location ) ? $location : '';
	}

	/**
	 * Returns the Navigation post made from a classic menu, or 0.
	 *
	 * @param int $menu_id Classic menu term ID.
	 * @return int
	 */
	private function navigation_id( int $menu_id ): int {
		$menu = wp_get_nav_menu_object( $menu_id );

		if ( ! $menu instanceof WP_Term ) {
			return 0;
		}

		$navigation = get_page_by_path( $menu->slug, OBJECT, 'wp_navigation' );

		return $navigation instanceof WP_Post ? $navigation->ID : 0;
	}
}
