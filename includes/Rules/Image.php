<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Converts an image module, or a full-width image module, into an Image block.
 */
class Image extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$url = $node->attr( 'src' );

		if ( '' === $url ) {
			return [];
		}

		$attributes = array_merge(
			$this->common_attributes( $node ),
			[
				'url'      => $url,
				'alt'      => $node->attr( 'alt' ),
				'title'    => $node->attr( 'title_text' ),
				'sizeSlug' => 'full',
			]
		);

		$attributes = $this->with_attachment_id( $attributes, $url );

		$link = self::url( $node->attr( 'url' ) );

		if ( $node->is_on( 'show_in_lightbox' ) ) {
			$attributes['lightbox'] = [ 'enabled' => true ];
		} elseif ( '' !== $link ) {
			$attributes['href']            = $link;
			$attributes['linkDestination'] = 'custom';

			$attributes = $this->with_new_tab( $node, $attributes );
		}

		$alignment = Values::responsive( $node, 'align' )['desktop'];

		if ( 'et_pb_fullwidth_image' === $node->tag || $node->is_on( 'force_fullwidth' ) ) {
			$attributes['width'] = '100%';
		} elseif ( in_array( $alignment, [ 'center', 'right' ], true ) ) {
			$attributes['align'] = $alignment;
		}

		$style = new StyleSet();
		$this->design( $node, $style, $converter->presets, [ 'background', 'padding', 'height' ], $converter );
		$attributes['style'] = $style->to_style();

		// The module's background and padding surround the image, so they go on a wrapper.
		$wrapper = new StyleSet();
		$this->design( $node, $wrapper, $converter->presets, [ 'margin', 'border' ], $converter );

		return $this->wrap( $node, [ Spec::block( 'core/image', $attributes ) ], $wrapper );
	}
}
