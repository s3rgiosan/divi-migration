<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Handles a module that has no rule.
 *
 * The module's text and child modules are kept, so no content is lost, and
 * a hidden element with a `data-divi-module` attribute marks the spot for
 * review. The element's `data-divi-attrs` attribute holds the module's
 * non-empty attributes as JSON, so its settings stay in the post. The
 * converter's report lists every module that fell back.
 */
class Fallback implements Rule {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$attrs  = array_filter( $node->attrs, fn( $value ) => '' !== $value && null !== $value && [] !== $value );
		$marker = sprintf( '<div hidden data-divi-module="%s"', esc_attr( $node->tag ) );

		if ( [] !== $attrs ) {
			$marker .= sprintf( ' data-divi-attrs="%s"', esc_attr( (string) wp_json_encode( $attrs ) ) );
		}

		$specs = [ Spec::block( 'core/html', [ 'content' => $marker . '></div>' ] ) ];

		$content = trim( $node->content );

		if ( '' !== $content ) {
			$specs[] = Spec::raw( $content );
		}

		array_push( $specs, ...$converter->convert_children( $node ) );

		return $specs;
	}
}
