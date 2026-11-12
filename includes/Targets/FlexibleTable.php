<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a table into the Flexible Table block (Flexible Table Block
 * plugin), which keeps the inline styles of the table, its cells and its
 * caption, and each cell's class, ID, headers and scope.
 *
 * The table keeps the automatic layout HTML tables have.
 */
class FlexibleTable implements TableTarget {

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'flexible-table-block/table' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( TableData $table ): array {
		$attributes = [ 'hasFixedLayout' => false ];

		foreach ( $table->sections as $section => $rows ) {
			$attributes[ $section ] = array_map(
				fn( array $cells ) => [ 'cells' => array_map( [ $this, 'cell' ], $cells ) ],
				$rows
			);
		}

		$attributes['tableStyles']   = $table->styles;
		$attributes['className']     = $table->class_name;
		$attributes['caption']       = trim( $table->caption );
		$attributes['captionStyles'] = $table->caption_styles;

		return Spec::block( 'flexible-table-block/table', $attributes );
	}

	/**
	 * Returns a cell's attributes.
	 *
	 * @param TableCell $cell Cell.
	 * @return array
	 */
	private function cell( TableCell $cell ): array {
		return array_filter(
			[
				'content'   => $cell->content,
				'tag'       => $cell->tag,
				'styles'    => $cell->styles,
				'className' => $cell->class_name,
				'id'        => $cell->id,
				'headers'   => $cell->headers,
				'scope'     => in_array( $cell->scope, [ 'row', 'col', 'rowgroup', 'colgroup' ], true ) ? $cell->scope : '',
				'rowSpan'   => self::span( $cell->rowspan ),
				'colSpan'   => self::span( $cell->colspan ),
			],
			fn( $value, $key ) => 'content' === $key || ( null !== $value && '' !== $value ),
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Returns a row or column span as the block keeps it: a string, or null
	 * for 1 and below.
	 *
	 * @param string $value Attribute value.
	 * @return string|null
	 */
	private static function span( string $value ): ?string {
		$found = preg_match( '/^\s*([+-]?\d+)/', $value, $matches );

		if ( 1 !== $found ) {
			return null;
		}

		$span = (int) $matches[1];

		return $span <= 1 ? null : (string) $span;
	}
}
