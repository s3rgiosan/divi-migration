<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a testimonial into a Group with the quote, the author and an
 * optional portrait.
 *
 * The Group carries the `testimonial` block style (`is-style-testimonial`);
 * the theme registers it and draws the quote mark, which Divi renders from an
 * icon font.
 */
class Testimonial extends Module {

	/**
	 * Divi's line height of a testimonial.
	 */
	const LINE_HEIGHT = '1.5';

	/**
	 * Divi's space above the author, below the quote.
	 */
	const AUTHOR_GAP = '32px';

	/**
	 * Divi's space above the author of a testimonial without a quote.
	 */
	const AUTHOR_GAP_ALONE = '12px';

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets = $converter->presets;
		$inner   = [];

		$portrait = $node->attr( 'portrait_url' );

		if ( '' !== $portrait ) {
			$image = [
				'url'      => $portrait,
				'sizeSlug' => 'thumbnail',
				'width'    => $node->attr( 'portrait_width', '90px' ),
			];

			$image = $this->with_attachment_id( $image, $portrait );

			$inner[] = Spec::block( 'core/image', $image );
		}

		$body = trim( $node->content );

		// The quote sits in a text group, which spaces its paragraphs as Divi does.
		if ( '' !== $body ) {
			$body_style = new StyleSet();
			$this->typography( $node, 'body', $body_style, $presets );

			$paragraph = array_filter( [ 'style' => $body_style->to_style() ] );
			$inner[]   = Spec::block(
				'core/group',
				[
					'className' => $converter->settings->class_name( 'text' ),
					'layout'    => [ 'type' => 'default' ],
				],
				[ Spec::raw( wpautop( $body ), [] !== $paragraph ? [ 'core/paragraph' => $paragraph ] : [] ) ]
			);
		}

		$author = $node->attr( 'author' );
		$role   = array_filter( [ $node->attr( 'job_title' ), $node->attr( 'company_name' ) ] );

		if ( '' !== $author || [] !== $role ) {
			// Divi leaves 32px above the author, or 12px without a quote.
			$author_style = new StyleSet();
			$author_style->set_value( [ 'spacing', 'margin', 'top' ], '' !== $body ? self::AUTHOR_GAP : self::AUTHOR_GAP_ALONE );
			$this->typography( $node, 'author', $author_style, $presets );

			$company = $node->attr( 'company_name' );
			$content = '' !== $author ? '<strong>' . ( '' === $company ? self::link( $node, esc_html( $author ) ) : esc_html( $author ) ) . '</strong>' : '';

			if ( [] !== $role ) {
				$parts = array_map( fn( string $part ) => $part === $company ? self::link( $node, esc_html( $part ) ) : esc_html( $part ), array_values( $role ) );

				$content .= ( '' !== $content ? '<br>' : '' ) . implode( ', ', $parts );
			}

			$inner[] = Spec::block(
				'core/paragraph',
				[
					'content' => $content,
					'style'   => $author_style->to_style(),
				]
			);
		}

		if ( [] === $inner ) {
			return [];
		}

		// Divi sets a testimonial's line height to 1.5, over the body line height.
		$style = new StyleSet();
		$style->set_value( [ 'typography', 'lineHeight' ], self::LINE_HEIGHT );
		$this->design( $node, $style, $presets, [], $converter );
		$this->background_layout( $node, $style, $presets );

		$attributes = $this->group_attributes( $node, $style, $converter->settings->class_name( 'testimonial' ) );

		return $this->wrap( $node, [ Spec::block( 'core/group', $attributes, $inner ) ], new StyleSet() );
	}

	/**
	 * Wraps HTML in the testimonial's link, or returns it as it is without a
	 * link. Divi links the author, or the company when the testimonial has one.
	 *
	 * @param Node   $node Testimonial node.
	 * @param string $html Escaped HTML to link.
	 * @return string
	 */
	private static function link( Node $node, string $html ): string {
		$url = $node->attr( 'url' );

		if ( '' === $url ) {
			return $html;
		}

		$target = $node->is_on( 'url_new_window' ) ? ' target="_blank" rel="noopener"' : '';

		return '<a href="' . esc_attr( self::url( $url ) ) . '"' . $target . '>' . $html . '</a>';
	}
}
