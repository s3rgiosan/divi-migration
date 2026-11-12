<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * One table cell read from HTML, ready for a table target.
 */
final class TableCell {

	/**
	 * Creates a cell.
	 *
	 * @param string $tag        `td` or `th`.
	 * @param string $content    Cell HTML.
	 * @param string $styles     The cell's `style` attribute.
	 * @param string $class_name The cell's `class` attribute.
	 * @param string $id         The cell's `id` attribute.
	 * @param string $headers    The cell's `headers` attribute.
	 * @param string $scope      The cell's `scope` attribute.
	 * @param string $rowspan    The cell's `rowspan` attribute.
	 * @param string $colspan    The cell's `colspan` attribute.
	 */
	public function __construct(
		public readonly string $tag,
		public readonly string $content,
		public readonly string $styles = '',
		public readonly string $class_name = '',
		public readonly string $id = '',
		public readonly string $headers = '',
		public readonly string $scope = '',
		public readonly string $rowspan = '',
		public readonly string $colspan = '',
	) {}
}
