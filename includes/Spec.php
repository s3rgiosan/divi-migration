<?php

namespace S3S\WP\DiviMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Builds block specs: the converter's output, serialized by the block editor.
 *
 * A spec is one of:
 * - a block: `[ 'name' => 'core/group', 'attributes' => [], 'innerBlocks' => [] ]`;
 * - raw HTML: `[ 'raw' => '<h2>…</h2>', 'apply' => [] ]`, which the editor's
 *   raw handler turns into blocks. `apply` holds attributes merged into the
 *   resulting blocks, keyed by block name (`core/paragraph`) or by heading
 *   level (`core/heading:2`).
 */
class Spec {

	/**
	 * Returns a block spec.
	 *
	 * @param string $name        Block name.
	 * @param array  $attributes  Block attributes.
	 * @param array  $inner       Inner block specs.
	 * @return array
	 */
	public static function block( string $name, array $attributes = [], array $inner = [] ): array {
		return [
			'name'        => $name,
			'attributes'  => self::clean( $attributes ),
			'innerBlocks' => array_values( $inner ),
		];
	}

	/**
	 * Returns a block markup spec: markup that goes in the content as is.
	 *
	 * @param string $markup Block markup.
	 * @return array
	 */
	public static function markup( string $markup ): array {
		return [ 'markup' => $markup ];
	}

	/**
	 * Returns a classic content spec: HTML that goes in the content as is,
	 * outside any block.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function classic( string $html ): array {
		return [ 'classic' => $html ];
	}

	/**
	 * Returns a raw HTML spec.
	 *
	 * @param string $html  HTML to convert.
	 * @param array  $apply Attributes to merge into the resulting blocks.
	 * @return array
	 */
	public static function raw( string $html, array $apply = [] ): array {
		return [
			'raw'   => $html,
			'apply' => (object) $apply,
		];
	}

	/**
	 * Removes empty attribute values, recursively.
	 *
	 * @param array $attributes Attributes.
	 * @return array
	 */
	public static function clean( array $attributes ): array {
		foreach ( $attributes as $key => $value ) {
			if ( is_array( $value ) ) {
				$value              = self::clean( $value );
				$attributes[ $key ] = $value;
			}

			if ( [] === $value || '' === $value || null === $value ) {
				unset( $attributes[ $key ] );
			}
		}

		return $attributes;
	}
}
