<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a table into the core Table block: each cell keeps its tag,
 * content, spans and text alignment, and the caption carries over.
 */
class CoreTable implements TableTarget {

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'core/table' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( TableData $table ): array {
		$attributes = [];

		foreach ( $table->sections as $section => $rows ) {
			$attributes[ $section ] = array_map(
				fn( array $cells ) => [ 'cells' => array_map( [ $this, 'cell' ], $cells ) ],
				$rows
			);
		}

		if ( '' !== $table->class_name ) {
			$attributes['className'] = $table->class_name;
		}

		$caption = trim( $table->caption );

		if ( '' !== $caption ) {
			$attributes['caption'] = $table->caption;
		}

		return Spec::block( 'core/table', $attributes );
	}

	/**
	 * Returns a cell's attributes.
	 *
	 * @param TableCell $cell Cell.
	 * @return array
	 */
	private function cell( TableCell $cell ): array {
		$align = 1 === preg_match( '/(?:^|;)\s*text-align\s*:\s*(left|center|right)\s*(?:;|$)/i', $cell->styles, $matches ) ? strtolower( $matches[1] ) : null;

		return array_filter(
			[
				'tag'     => $cell->tag,
				'content' => $cell->content,
				'rowspan' => self::span( $cell->rowspan ),
				'colspan' => self::span( $cell->colspan ),
				'align'   => $align,
			],
			fn( $value ) => null !== $value
		);
	}

	/**
	 * Returns a row or column span as the editor keeps it: a string, or null
	 * for 1, a negative or a missing value.
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

		return $span < 0 || 1 === $span ? null : (string) $span;
	}
}
