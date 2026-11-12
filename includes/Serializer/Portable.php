<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

/**
 * Makes block markup free of database IDs and of the site's own host, so it
 * can go into theme files: template parts, templates and patterns.
 *
 * Media blocks lose their attachment ID attributes and the matching
 * `wp-image-<id>` classes, as the editor writes them without an ID. Every
 * block also loses the ID of its background image. Upload URLs become
 * root-relative.
 */
class Portable {

	/**
	 * Attachment ID attributes per block name.
	 */
	const ID_ATTRIBUTES = [
		'image'      => [ 'id' ],
		'cover'      => [ 'id' ],
		'media-text' => [ 'mediaId', 'mediaLink' ],
		'video'      => [ 'id' ],
		'audio'      => [ 'id' ],
		'file'       => [ 'id' ],
		'gallery'    => [ 'ids' ],
	];

	/**
	 * Matches a block comment that has attributes.
	 */
	const COMMENT_PATTERN = '/<!-- wp:([a-z][a-z0-9_-]*) (\{.*?\}) (\/)?-->/s';

	/**
	 * Returns the markup without attachment IDs and with root-relative upload
	 * URLs.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	public static function markup( string $markup ): string {
		$markup = (string) preg_replace_callback( self::COMMENT_PATTERN, [ self::class, 'comment' ], $markup );
		$markup = self::without_id_classes( $markup );

		return self::relative_uploads( $markup );
	}

	/**
	 * Removes the ID attributes from one block comment.
	 *
	 * @param string[] $matches Block name, attributes JSON and the self-closing slash.
	 * @return string
	 */
	private static function comment( array $matches ): string {
		$name       = $matches[1];
		$attributes = json_decode( $matches[2], true );

		if ( ! is_array( $attributes ) ) {
			return $matches[0];
		}

		$without = self::without_background_ids( $attributes );

		foreach ( self::ID_ATTRIBUTES[ $name ] ?? [] as $key ) {
			unset( $without[ $key ] );
		}

		if ( $without === $attributes ) {
			return $matches[0];
		}

		$attributes = $without;

		$closer = isset( $matches[3] ) && '/' === $matches[3] ? '/-->' : '-->';

		if ( [] === $attributes ) {
			return '<!-- wp:' . $name . ' ' . $closer;
		}

		return '<!-- wp:' . $name . ' ' . serialize_block_attributes( $attributes ) . ' ' . $closer;
	}

	/**
	 * Removes the attachment ID from every background image in block
	 * attributes, at any depth.
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	private static function without_background_ids( array $attributes ): array {
		foreach ( $attributes as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			if ( 'backgroundImage' === $key ) {
				unset( $value['id'] );
			}

			$attributes[ $key ] = self::without_background_ids( $value );
		}

		return $attributes;
	}

	/**
	 * Removes `wp-image-<id>` classes from the HTML, and a class attribute
	 * that ends up empty.
	 *
	 * The class values change in place, so the rest of each tag keeps the
	 * exact form the block saves write.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	private static function without_id_classes( string $markup ): string {
		return (string) preg_replace_callback(
			'/ class="([^"]*)"/',
			function ( array $matches ): string {
				$classes = array_filter(
					preg_split( '/\s+/', trim( $matches[1] ) ),
					fn( string $class_name ) => 1 !== preg_match( '/^wp-image-\d+$/', $class_name )
				);

				return [] === $classes ? '' : ' class="' . implode( ' ', $classes ) . '"';
			},
			$markup
		);
	}

	/**
	 * Makes the site's upload URLs root-relative.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	private static function relative_uploads( string $markup ): string {
		$base_url = (string) wp_get_upload_dir()['baseurl'];

		if ( '' === $base_url ) {
			return $markup;
		}

		$relative = wp_make_link_relative( $base_url );
		$urls     = array_unique( [ $base_url, set_url_scheme( $base_url, 'http' ), set_url_scheme( $base_url, 'https' ) ] );

		return str_replace( $urls, $relative, $markup );
	}
}
