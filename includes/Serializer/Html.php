<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a block's saved HTML in the form that passes block validation.
 */
final class Html {

	/**
	 * Attributes written without a value when true.
	 */
	const BOOLEAN_ATTRIBUTES = [ 'autoplay', 'controls', 'hidden', 'loop', 'muted', 'open', 'playsinline', 'reversed' ];

	/**
	 * Elements without a closing tag.
	 */
	const VOID_ELEMENTS = [ 'br', 'hr', 'img', 'source', 'track', 'wbr' ];

	/**
	 * Returns an opening tag, or a void element.
	 *
	 * @param string   $tag        Tag name.
	 * @param array    $attributes Attributes; null and false values are left out, `style` takes
	 *                             an array of CSS declarations.
	 * @param string[] $keep_empty Attributes written even when empty; `alt` always is.
	 * @return string
	 */
	public static function open( string $tag, array $attributes = [], array $keep_empty = [] ): string {
		$html = '<' . $tag;

		foreach ( $attributes as $name => $value ) {
			if ( 'style' === $name && is_array( $value ) ) {
				$value = self::style( $value );
			}

			if ( null === $value || false === $value ) {
				continue;
			}

			// A boolean attribute is written without a value, whatever its value.
			if ( in_array( $name, self::BOOLEAN_ATTRIBUTES, true ) ) {
				$html .= ' ' . $name;
				continue;
			}

			if ( '' === $value && 'alt' !== $name && ! in_array( $name, $keep_empty, true ) ) {
				continue;
			}

			if ( true === $value ) {
				$html .= ' ' . $name . '="true"';
				continue;
			}

			$html .= ' ' . $name . '="' . self::escape_attribute( (string) $value ) . '"';
		}

		return $html . ( in_array( $tag, self::VOID_ELEMENTS, true ) ? '/>' : '>' );
	}

	/**
	 * Returns an element with its content.
	 *
	 * @param string   $tag        Tag name.
	 * @param array    $attributes Attributes (see `open()`).
	 * @param string   $content    HTML content.
	 * @param string[] $keep_empty Attributes written even when empty (see `open()`).
	 * @return string
	 */
	public static function element( string $tag, array $attributes = [], string $content = '', array $keep_empty = [] ): string {
		$open = self::open( $tag, $attributes, $keep_empty );

		return in_array( $tag, self::VOID_ELEMENTS, true ) ? $open : $open . $content . '</' . $tag . '>';
	}

	/**
	 * Returns a style attribute value from CSS declarations.
	 *
	 * @param array<string, string> $declarations CSS values keyed by property.
	 * @return string
	 */
	public static function style( array $declarations ): string {
		$rules = [];

		foreach ( $declarations as $property => $value ) {
			if ( null !== $value && '' !== $value ) {
				$rules[] = $property . ':' . $value;
			}
		}

		return implode( ';', $rules );
	}

	/**
	 * Escapes an attribute value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function escape_attribute( string $value ): string {
		return str_replace( [ '"', '<', '>' ], [ '&quot;', '&lt;', '&gt;' ], self::escape_ampersand( $value ) );
	}

	/**
	 * Escapes text content.
	 *
	 * @param string $value Text.
	 * @return string
	 */
	public static function escape_text( string $value ): string {
		return str_replace( '<', '&lt;', self::escape_ampersand( $value ) );
	}

	/**
	 * Removes HTML comments, and any comment start or end left over, so the
	 * text holds no block delimiter.
	 *
	 * @param string $value HTML.
	 * @return string
	 */
	public static function strip_comments( string $value ): string {
		// Removing a comment start can join the characters around it into a new one.
		while ( str_contains( $value, '<!--' ) || str_contains( $value, '-->' ) ) {
			$value = str_replace( [ '<!--', '-->' ], '', (string) preg_replace( '/<!--.*?-->/s', '', $value ) );
		}

		return $value;
	}

	/**
	 * Returns class names joined, without empty or repeated ones.
	 *
	 * @param array $classes Class names; keys with a boolean value add the key when true.
	 * @return string
	 */
	public static function classes( array $classes ): string {
		$names = [];

		foreach ( $classes as $key => $value ) {
			$name = is_string( $key ) ? ( $value ? $key : '' ) : (string) $value;

			foreach ( preg_split( '/\s+/', trim( $name ) ) as $part ) {
				if ( '' !== $part ) {
					$names[ $part ] = true;
				}
			}
		}

		return implode( ' ', array_keys( $names ) );
	}

	/**
	 * Formats a number as JavaScript prints it: the shortest digits that read
	 * back as the same number, without trailing zeros.
	 *
	 * @param int|float $value Number.
	 * @return string
	 */
	public static function number( int|float $value ): string {
		$value = (float) $value;

		$floored = floor( $value );

		$magnitude = abs( $value );

		if ( $floored === $value && $magnitude < 1e15 ) {
			return (string) (int) $value;
		}

		$precision = 1;

		while ( $precision < 17 && (float) sprintf( '%.' . $precision . 'G', $value ) !== $value ) {
			++$precision;
		}

		$text = sprintf( '%.' . $precision . 'G', $value );

		// JavaScript writes numbers between 1e-7 and 1e21 without an exponent.
		return str_contains( $text, 'E' ) ? rtrim( rtrim( number_format( $value, 20, '.', '' ), '0' ), '.' ) : $text;
	}

	/**
	 * Escapes ampersands that do not start an entity.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function escape_ampersand( string $value ): string {
		return (string) preg_replace( '/&(?!([a-z0-9]+|#[0-9]+|#x[a-f0-9]+);)/i', '&amp;', $value );
	}
}
