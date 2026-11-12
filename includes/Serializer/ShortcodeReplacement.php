<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces a Shortcode block whose shortcode stops working without Divi or
 * one of its plugins with blocks.
 */
interface ShortcodeReplacement {

	/**
	 * Returns the shortcode tags it replaces.
	 *
	 * @return string[]
	 */
	public function tags(): array;

	/**
	 * Returns the block specs that replace a shortcode, or null to keep it.
	 *
	 * @param string $tag   Shortcode tag.
	 * @param array  $attrs Shortcode attributes.
	 * @return array[]|null
	 */
	public function specs( string $tag, array $attrs ): ?array;

	/**
	 * Returns notes on shortcodes it kept, for the conversion report, and
	 * clears them.
	 *
	 * @return string[]
	 */
	public function notes(): array;
}
