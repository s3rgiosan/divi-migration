<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A table read from HTML, ready for a table target.
 */
final class TableData {

	/**
	 * Creates a table.
	 *
	 * @param array<string, TableCell[][]> $sections       Rows of cells keyed by section: `head`, `body`, `foot`.
	 * @param string                       $styles         The table's `style` attribute.
	 * @param string                       $class_name     The table's `class` attribute.
	 * @param string                       $caption        Caption HTML, or an empty string.
	 * @param string                       $caption_styles The caption's `style` attribute.
	 */
	public function __construct(
		public readonly array $sections,
		public readonly string $styles = '',
		public readonly string $class_name = '',
		public readonly string $caption = '',
		public readonly string $caption_styles = '',
	) {}
}
