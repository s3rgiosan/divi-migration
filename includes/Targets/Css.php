<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for targets that write custom CSS.
 */
final class Css {

	/**
	 * Prefix of a color preset reference.
	 */
	const PRESET_PREFIX = 'var:preset|color|';

	/**
	 * Returns a color as CSS: a preset reference becomes its custom property.
	 *
	 * @param string $color Preset reference or CSS color.
	 * @return string
	 */
	public static function color( string $color ): string {
		return str_starts_with( $color, self::PRESET_PREFIX )
			? 'var(--wp--preset--color--' . substr( $color, strlen( self::PRESET_PREFIX ) ) . ')'
			: $color;
	}

	/**
	 * Returns per-block custom CSS (`style.css`) for an item's closed and open
	 * states: background, title color and icon color.
	 *
	 * Core reads declarations before the first `&` as the block's own rule and
	 * each `&…` as a nested rule. Both states share one specificity, so the open
	 * rule, written last, wins while the item has the `is-open` class.
	 *
	 * @param AccordionData $accordion       Accordion data.
	 * @param string        $title_selector  Class of the item's title element, such as `.wp-block-accordion-heading`.
	 * @param string        $icon_selector   Class of the item's icon element, or an empty string.
	 * @return string
	 */
	public static function accordion_states( AccordionData $accordion, string $title_selector, string $icon_selector = '' ): string {
		$css = [];

		if ( '' !== $accordion->closed_background ) {
			$css[] = sprintf( 'background-color: %s;', self::color( $accordion->closed_background ) );
		}

		if ( '' !== $accordion->closed_title ) {
			$css[] = sprintf( '& %s { color: %s; }', $title_selector, self::color( $accordion->closed_title ) );
		}

		if ( '' !== $accordion->icon_color && '' !== $icon_selector ) {
			$css[] = sprintf( '& %s { color: %s; }', $icon_selector, self::color( $accordion->icon_color ) );
		}

		if ( '' !== $accordion->open_background ) {
			$css[] = sprintf( '&.is-open { background-color: %s; }', self::color( $accordion->open_background ) );
		}

		if ( '' !== $accordion->open_title ) {
			$css[] = sprintf( '&.is-open %s { color: %s; }', $title_selector, self::color( $accordion->open_title ) );
		}

		return implode( ' ', $css );
	}
}
