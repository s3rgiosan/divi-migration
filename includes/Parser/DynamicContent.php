<?php

namespace S3S\WP\DiviMigration\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Divi dynamic content values.
 *
 * Divi stores dynamic content as `@ET-DC@<base64 JSON>@`, such as a link to
 * a page by ID. Values that resolve to a fixed string, such as a page's URL,
 * become that string. Others resolve to an empty string, and the type of
 * dynamic content is reported to the caller.
 */
class DynamicContent {

	/**
	 * Matches a dynamic content value.
	 */
	const PATTERN = '/^@ET-DC@([A-Za-z0-9+\/=]+)@$/';

	/**
	 * Resolves a value when it is dynamic content.
	 *
	 * @param string $value      Attribute value.
	 * @param string $unresolved Receives the dynamic content type when the value resolves to an empty string, or `unknown` when the value has no readable type.
	 * @return string The resolved value, or the value unchanged when it is not dynamic content.
	 */
	public static function resolve( string $value, string &$unresolved = '' ): string {
		$is_dynamic = preg_match( self::PATTERN, $value, $matches );

		if ( 1 !== $is_dynamic ) {
			return $value;
		}

		$data = json_decode( (string) base64_decode( $matches[1], true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( ! is_array( $data ) || empty( $data['content'] ) || ! is_scalar( $data['content'] ) ) {
			$unresolved = 'unknown';
			return '';
		}

		$settings = is_array( $data['settings'] ?? null ) ? $data['settings'] : [];
		$resolved = self::resolve_content( (string) $data['content'], $settings );

		if ( '' === $resolved ) {
			$unresolved = (string) $data['content'];
			return '';
		}

		return self::scalar_text( $settings['before'] ?? '' ) . $resolved . self::scalar_text( $settings['after'] ?? '' );
	}

	/**
	 * Returns a decoded JSON value as a string, or an empty string when it is
	 * not a scalar.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar_text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Resolves one dynamic content type.
	 *
	 * @param string $content  Dynamic content type, such as `post_link_url_page`.
	 * @param array  $settings Dynamic content settings.
	 * @return string
	 */
	private static function resolve_content( string $content, array $settings ): string {
		$post_id = (int) ( $settings['post_id'] ?? 0 );

		if ( str_starts_with( $content, 'post_link_url' ) && $post_id > 0 ) {
			$url = get_permalink( $post_id );
			return is_string( $url ) ? $url : '';
		}

		switch ( $content ) {
			case 'home_url':
				return home_url( '/' );
			case 'site_title':
				return get_bloginfo( 'name' );
			case 'site_tagline':
				return get_bloginfo( 'description' );
			default:
				return '';
		}
	}
}
