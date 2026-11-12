<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * One toggle read from a Divi accordion, ready for an accordion target.
 */
final class AccordionItem {

	/**
	 * Creates an item.
	 *
	 * @param string  $title  Title, as escaped HTML.
	 * @param bool    $open   Whether the item starts open.
	 * @param array[] $blocks Block specs for the panel content.
	 */
	public function __construct(
		public readonly string $title,
		public readonly bool $open,
		public readonly array $blocks,
	) {}
}
