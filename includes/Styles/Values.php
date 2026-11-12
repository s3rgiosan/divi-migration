<?php

namespace S3S\WP\DiviMigration\Styles;

use S3S\WP\DiviMigration\Parser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Divi's packed attribute formats.
 */
class Values {

	/**
	 * The `_builder_version` Divi gives content saved without one.
	 */
	const LEGACY_BUILDER_VERSION = '3.0.47';

	/**
	 * The Divi version that migrated "Keep padding on mobile".
	 */
	const MOBILE_PADDING_VERSION = '3.0.74';

	/**
	 * The tablet padding Divi gives a section without "Keep padding on mobile".
	 */
	const MOBILE_PADDING = '50px|0|50px|0';

	/**
	 * Positions of the parts in a Divi font value, `family|weight|italic|uppercase|underline|smallcaps|strikethrough|line color|line style`.
	 */
	const FONT_PARTS = [ 'family', 'weight', 'italic', 'uppercase', 'underline', 'smallcaps', 'strikethrough' ];

	/**
	 * Divi background positions, mapped to CSS.
	 */
	const POSITIONS = [
		'top_left'      => 'left top',
		'top_center'    => 'center top',
		'top_right'     => 'right top',
		'center_left'   => 'left center',
		'center'        => 'center center',
		'center_right'  => 'right center',
		'bottom_left'   => 'left bottom',
		'bottom_center' => 'center bottom',
		'bottom_right'  => 'right bottom',
	];

	/**
	 * Returns an attribute's desktop, tablet and phone values.
	 *
	 * Divi stores the tablet and phone values in `<name>_tablet` and
	 * `<name>_phone`, and uses them only when `<name>_last_edited` starts with
	 * `on`. Settings in a group, such as the background, share the group's
	 * `<group>_last_edited`. An empty phone value inherits the tablet value.
	 *
	 * @param Node   $node  Module node.
	 * @param string $name  Attribute name.
	 * @param string $group Settings group, such as `background`, or an empty string.
	 * @return array{desktop: string, tablet: string, phone: string}
	 */
	public static function responsive( Node $node, string $name, string $group = '' ): array {
		$desktop     = $node->attr( $name );
		$last_edited = $node->attr( $name . '_last_edited', '' !== $group ? $node->attr( $group . '_last_edited' ) : '' );

		if ( ! str_starts_with( $last_edited, 'on' ) ) {
			return [
				'desktop' => $desktop,
				'tablet'  => '',
				'phone'   => '',
			];
		}

		$tablet = $node->attr( $name . '_tablet' );
		$phone  = $node->attr( $name . '_phone', $tablet );

		return [
			'desktop' => $desktop,
			'tablet'  => $tablet,
			'phone'   => $phone,
		];
	}

	/**
	 * Returns an attribute's hover value, when hover styles are enabled for it.
	 *
	 * @param Node   $node Module node.
	 * @param string $name Attribute name.
	 * @return string
	 */
	public static function hover( Node $node, string $name ): string {
		$enabled = $node->attr( $name . '__hover_enabled' );

		if ( ! str_starts_with( $enabled, 'on' ) ) {
			return '';
		}

		return $node->attr( $name . '__hover' );
	}

	/**
	 * Parses a Divi spacing value, `top|right|bottom|left|linked|linked`.
	 *
	 * Older Divi content stores bare numbers, which are pixels.
	 *
	 * @param string $value Spacing value.
	 * @return array<string, string> Sides that have a value.
	 */
	public static function spacing( string $value ): array {
		if ( '' === $value ) {
			return [];
		}

		$parts = array_pad( explode( '|', $value ), 4, '' );
		$sides = [];

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $index => $side ) {
			$side_value = trim( $parts[ $index ] );

			if ( '' !== $side_value ) {
				$sides[ $side ] = is_numeric( $side_value ) ? $side_value . 'px' : $side_value;
			}
		}

		return $sides;
	}

	/**
	 * Expands a padding value in Divi's older two-part format, `top|bottom`,
	 * to the four-part format.
	 *
	 * Divi reads two parts as the top and bottom padding of sections, rows
	 * and inner rows. Other values are returned unchanged.
	 *
	 * @param string $value Padding value.
	 * @return string
	 */
	public static function legacy_padding( string $value ): string {
		$parts      = explode( '|', $value );
		$part_count = count( $parts );

		if ( 2 !== $part_count ) {
			return $value;
		}

		return $parts[0] . '||' . $parts[1] . '|';
	}

	/**
	 * Returns a section's padding per viewport, with the "Keep padding on
	 * mobile" setting applied.
	 *
	 * For content saved before Divi 3.0.74, `padding_mobile="off"` without a
	 * tablet padding gives a tablet padding of 50px top and bottom and 0
	 * sides, and phones take the tablet padding. A saved `_last_edited` value
	 * wins.
	 *
	 * @param Node                                                  $node   Section node.
	 * @param array{desktop: string, tablet: string, phone: string} $values Padding per viewport.
	 * @return array{desktop: string, tablet: string, phone: string}
	 */
	public static function legacy_mobile_padding( Node $node, array $values ): array {
		$version = $node->attr( '_builder_version', self::LEGACY_BUILDER_VERSION );

		if ( version_compare( $version, self::MOBILE_PADDING_VERSION, '>=' ) ) {
			return $values;
		}

		$last_edited = $node->attr( 'custom_padding_last_edited', 'on|desktop' );

		$padding_mobile = $node->attr( 'padding_mobile' );

		if ( 'off' !== $padding_mobile || ! str_starts_with( $last_edited, 'on' ) ) {
			return $values;
		}

		$tablet = $node->attr( 'custom_padding_tablet', self::MOBILE_PADDING );
		$phone  = $node->attr( 'custom_padding_phone', $tablet );

		return [
			'desktop' => $values['desktop'],
			'tablet'  => $tablet,
			'phone'   => $phone,
		];
	}

	/**
	 * Adds Divi's default unit to a number without a unit.
	 *
	 * Divi reads a bare number as pixels. A line height of 3 or less stays a
	 * bare number, which CSS reads as a multiple of the font size.
	 *
	 * @param string $value          CSS length or bare number.
	 * @param bool   $is_line_height Whether the value is a line height.
	 * @return string
	 */
	public static function with_unit( string $value, bool $is_line_height = false ): string {
		$value = trim( $value );

		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$number = (float) $value;

		if ( $is_line_height && $number <= 3 ) {
			return $value;
		}

		return $value . 'px';
	}

	/**
	 * Parses a Divi font value.
	 *
	 * @param string $value Font value.
	 * @return array<string, string|bool> Font parts that have a value.
	 */
	public static function font( string $value ): array {
		if ( '' === $value ) {
			return [];
		}

		$parts = array_pad( explode( '|', $value ), count( self::FONT_PARTS ), '' );
		$font  = [];

		foreach ( self::FONT_PARTS as $index => $name ) {
			$part = trim( $parts[ $index ] );

			if ( '' === $part ) {
				continue;
			}

			$font[ $name ] = 'family' === $name || 'weight' === $name ? $part : 'on' === $part;
		}

		// Divi's legacy bold flag stores `on` in the weight position.
		if ( isset( $font['weight'] ) && 'on' === $font['weight'] ) {
			$font['weight'] = '700';
		}

		return $font;
	}

	/**
	 * Maps a Divi background position to CSS.
	 *
	 * @param string $value Divi position, such as `top_left`.
	 * @return string
	 */
	public static function position( string $value ): string {
		return self::POSITIONS[ $value ] ?? str_replace( '_', ' ', $value );
	}

	/**
	 * Whether a value is a usable CSS length or keyword.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_length( string $value ): bool {
		return 1 === preg_match( '/^-?\d*\.?\d+(px|em|rem|%|vw|vh|vmin|vmax)?$|^auto$/', $value );
	}
}
