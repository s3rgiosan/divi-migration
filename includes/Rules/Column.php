<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a column into a Column block. The row rule sets its width.
 *
 * Divi hides an empty column at 980px and below, so the Column block of an
 * empty column is hidden on tablets and phones.
 */
class Column extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$style = new StyleSet();

		$this->design( $node, $style, $converter->presets, [ 'margin' ], $converter );

		$attributes          = $this->common_attributes( $node );
		$attributes['style'] = $style->to_style();

		$inner = $converter->convert_children( $node );

		if ( [] === $inner ) {
			$attributes['metadata']['blockVisibility']['viewport']['mobile'] = false;
			$attributes['metadata']['blockVisibility']['viewport']['tablet'] = false;
		}

		return [ Spec::block( 'core/column', $attributes, $inner ) ];
	}
}
