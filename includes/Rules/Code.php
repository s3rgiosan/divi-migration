<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a code module into a Custom HTML block, or a Shortcode block when
 * the code is a single shortcode.
 *
 * Shortcodes inside HTML still render, since WordPress runs shortcodes on the
 * post content after rendering its blocks.
 */
class Code extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$code = trim( $node->content );

		if ( '' === $code ) {
			return [];
		}

		$style = new StyleSet();
		$this->design( $node, $style, $converter->presets, [], $converter );

		$specs = 1 === preg_match( '/^\[[^\[\]]+\](?:[^\[]*\[\/[^\]]+\])?$/s', $code )
			? [ Spec::block( 'core/shortcode', [ 'text' => $code ] ) ]
			: [ Spec::block( 'core/html', [ 'content' => $code ] ) ];

		return $this->wrap( $node, $specs, $style, $this->common_attributes( $node ) );
	}
}
