<?php

namespace S3S\WP\DiviMigration\Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Maps colors and font families to the active theme's presets.
 *
 * A Divi value that matches a preset in the theme's theme.json becomes a
 * preset reference, so the block follows the theme when the preset changes.
 */
class Presets {

	/**
	 * Palette colors, keyed by lowercase hex value.
	 *
	 * @var array<string, string>
	 */
	private array $colors = [];

	/**
	 * Font family slugs, keyed by lowercase family name.
	 *
	 * @var array<string, string>
	 */
	private array $fonts = [];

	/**
	 * Font size slugs, keyed by size value.
	 *
	 * @var array<string, string>
	 */
	private array $font_sizes = [];

	/**
	 * Reads the presets from the active theme's global settings.
	 *
	 * @return Presets
	 */
	public static function from_global_settings(): Presets {
		$presets = new self();

		foreach ( self::flatten( wp_get_global_settings( [ 'color', 'palette' ] ) ) as $color ) {
			if ( isset( $color['color'], $color['slug'] ) && is_string( $color['color'] ) ) {
				$presets->colors[ self::normalize( $color['color'] ) ] = $color['slug'];
			}
		}

		foreach ( self::flatten( wp_get_global_settings( [ 'typography', 'fontFamilies' ] ) ) as $font ) {
			if ( ! isset( $font['slug'] ) ) {
				continue;
			}

			$names = [ $font['name'] ?? '', strtok( (string) ( $font['fontFamily'] ?? '' ), ',' ) ];

			foreach ( $names as $name ) {
				$name = strtolower( trim( (string) $name, " \"'" ) );

				if ( '' !== $name ) {
					$presets->fonts[ $name ] = $font['slug'];
				}
			}
		}

		foreach ( self::flatten( wp_get_global_settings( [ 'typography', 'fontSizes' ] ) ) as $size ) {
			if ( isset( $size['size'], $size['slug'] ) && is_string( $size['size'] ) ) {
				$presets->font_sizes[ $size['size'] ] = $size['slug'];
			}
		}

		return $presets;
	}

	/**
	 * Adds palette colors and font families the theme does not match by value.
	 *
	 * @param array<string, string> $colors Palette slugs keyed by color.
	 * @param array<string, string> $fonts  Font family slugs keyed by font name.
	 * @return Presets
	 */
	public function with( array $colors, array $fonts ): Presets {
		foreach ( $colors as $color => $slug ) {
			$this->colors[ self::normalize( (string) $color ) ] = $slug;
		}

		foreach ( $fonts as $name => $slug ) {
			$this->fonts[ strtolower( trim( $name ) ) ] = $slug;
		}

		return $this;
	}

	/**
	 * Returns a color as a preset reference when it matches the palette.
	 *
	 * Colors match after normalizing: hex colors are lowercase with six or
	 * eight digits, and functional colors have no spaces. A fully
	 * transparent color, which Divi stores for "no color", returns an empty
	 * string.
	 *
	 * @param string $value CSS color.
	 * @return string
	 */
	public function color( string $value ): string {
		if ( self::is_transparent( $value ) ) {
			return '';
		}

		$slug = $this->colors[ self::normalize( $value ) ] ?? null;
		return null === $slug ? $value : 'var:preset|color|' . $slug;
	}

	/**
	 * Returns a color per viewport.
	 *
	 * A transparent desktop color is dropped, since it means "no color". A
	 * transparent tablet or phone color is kept when it differs from the
	 * desktop color, because it clears the color the desktop value sets.
	 *
	 * @param array<string, string> $values CSS colors keyed by `desktop`, `tablet` and `phone`.
	 * @return array<string, string>
	 */
	public function responsive( array $values ): array {
		$desktop = (string) ( $values['desktop'] ?? '' );
		$colors  = [ 'desktop' => $this->color( $desktop ) ];

		foreach ( [ 'tablet', 'phone' ] as $viewport ) {
			$value = (string) ( $values[ $viewport ] ?? '' );

			if ( '' !== $value && self::is_transparent( $value ) ) {
				$colors[ $viewport ] = self::is_transparent( $desktop ) ? '' : 'transparent';
				continue;
			}

			$colors[ $viewport ] = $this->color( $value );
		}

		return $colors;
	}

	/**
	 * Returns a font family as a preset reference when it matches a theme font.
	 *
	 * @param string $family Font family name, such as `Raleway`.
	 * @return string
	 */
	public function font_family( string $family ): string {
		$slug = $this->fonts[ strtolower( trim( $family ) ) ] ?? null;
		return null === $slug ? $family : 'var:preset|font-family|' . $slug;
	}

	/**
	 * Returns a font size as a preset reference when it matches a theme size.
	 *
	 * @param string $size Font size, such as `24px`.
	 * @return string
	 */
	public function font_size( string $size ): string {
		$slug = $this->font_sizes[ trim( $size ) ] ?? null;
		return null === $slug ? $size : 'var:preset|font-size|' . $slug;
	}

	/**
	 * Whether a color is fully transparent, which Divi stores for "no color".
	 *
	 * @param string $value CSS color.
	 * @return bool
	 */
	private static function is_transparent( string $value ): bool {
		$normalized = self::normalize( $value );
		$is_rgba    = preg_match( '/^rgba\\([\\d.]+,[\\d.]+,[\\d.]+,0(\\.0+)?\\)$/', $normalized );

		return 'transparent' === $normalized || 1 === $is_rgba;
	}

	/**
	 * Returns a CSS color in a form that equal colors share: lowercase, with
	 * six or eight hex digits and no spaces in functional colors.
	 *
	 * @param string $value CSS color.
	 * @return string
	 */
	private static function normalize( string $value ): string {
		$color = strtolower( trim( $value ) );

		$is_short_hex = preg_match( '/^#[0-9a-f]{3,4}$/', $color );

		if ( 1 === $is_short_hex ) {
			return '#' . implode( '', array_map( fn( $digit ) => $digit . $digit, str_split( substr( $color, 1 ) ) ) );
		}

		return str_contains( $color, '(' ) ? (string) preg_replace( '/\\s+/', '', $color ) : $color;
	}

	/**
	 * Merges the theme and custom preset origins into one list.
	 *
	 * WordPress's default presets are left out: a theme preset with the same
	 * slug replaces the default one's CSS variable, so matching a value against
	 * the defaults could point to a variable with a different value.
	 *
	 * @param mixed $presets Presets keyed by origin, or a flat list.
	 * @return array
	 */
	private static function flatten( $presets ): array {
		if ( ! is_array( $presets ) ) {
			return [];
		}

		if ( array_is_list( $presets ) ) {
			return $presets;
		}

		$merged = [];

		foreach ( [ 'theme', 'custom' ] as $origin ) {
			if ( isset( $presets[ $origin ] ) && is_array( $presets[ $origin ] ) ) {
				$merged = array_merge( $merged, $presets[ $origin ] );
			}
		}

		return $merged;
	}
}
