<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a blurb into a Group with an image, a heading and text.
 *
 * With the image on the left (`icon_placement="left"`), the image and the
 * text sit side by side in a row. Divi icon fonts have no block counterpart;
 * a blurb with an icon and no image keeps its heading and text.
 */
class Blurb extends Module {

	/**
	 * Divi's space below a blurb image placed above the text.
	 */
	const IMAGE_GAP = '30px';

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets = $converter->presets;
		$url     = $node->attr( 'url' );
		$image   = $this->image( $node, $url );
		$blank   = $node->is_on( 'url_new_window' );
		$text    = [];
		$align   = $this->alignment( $node, 'text_orientation', $converter )['desktop'];

		$title = $node->attr( 'title' );

		if ( '' !== $title ) {
			$heading_style = new StyleSet();
			$this->typography( $node, 'header', $heading_style, $presets );
			$heading_style->set_value( [ 'typography', 'textAlign' ], $align );

			$level   = $this->heading_level( $node, 'header_level', 4 );
			$content = esc_html( $title );

			if ( '' !== $url ) {
				$content = sprintf( '<a href="%s"%s>%s</a>', esc_attr( self::url( $url ) ), $blank ? ' target="_blank" rel="noopener"' : '', $content );
			}

			$text[] = $this->heading_block( $content, $level, $heading_style );
		}

		$variants = $this->viewport_contents( $node );

		if ( [] !== $variants ) {
			$body_style = new StyleSet();
			$this->typography( $node, 'body', $body_style, $presets );
			$body_style->set_value( [ 'typography', 'textAlign' ], $align );

			$apply = [ 'core/paragraph' => [ 'style' => $body_style->to_style() ] ];

			array_push( $text, ...$this->variant_blocks( $variants, fn( string $body ) => [ Spec::raw( wpautop( $body ), $apply ) ] ) );
		}

		$style = new StyleSet();
		$this->design( $node, $style, $presets, [], $converter );
		$this->background_layout( $node, $style, $presets );

		if ( $node->is_on( 'use_icon' ) ) {
			$this->note_unconverted( $node, $converter, 'icon font', [ 'font_icon' ] );
		}

		$text_class = $converter->settings->class_name( 'text' );
		$is_left    = 'left' === $node->attr( 'icon_placement', 'top' ) && [] !== $image;

		$inner = $is_left
			? [
				Spec::block(
					'core/group',
					[
						'layout' => [
							'type'              => 'flex',
							'flexWrap'          => 'nowrap',
							'verticalAlignment' => 'top',
						],
					],
					[
						...$image,
						Spec::block(
							'core/group',
							[
								'className' => $text_class,
								'layout'    => [ 'type' => 'default' ],
							],
							$text
						),
					]
				),
			]
			: [
				...$image,
				Spec::block(
					'core/group',
					[
						'className' => $text_class,
						'layout'    => [ 'type' => 'default' ],
					],
					$text
				),
			];

		$attributes = $this->group_attributes( $node, $style );

		return $this->wrap( $node, [ Spec::block( 'core/group', $attributes, $inner ) ], new StyleSet() );
	}

	/**
	 * Returns the blurb's image block, or an empty list.
	 *
	 * @param Node   $node Blurb node.
	 * @param string $url  Blurb link.
	 * @return array[]
	 */
	private function image( Node $node, string $url ): array {
		$src = $node->attr( 'image' );

		if ( '' === $src || $node->is_on( 'use_icon' ) ) {
			return [];
		}

		$is_top     = 'left' !== $node->attr( 'icon_placement', 'top' );
		$attributes = [
			'url'      => $src,
			'alt'      => $node->attr( 'alt' ),
			'sizeSlug' => 'full',
			'align'    => $is_top ? 'center' : '',
		];

		// Divi leaves 30px between an image on top and the blurb text.
		if ( $is_top ) {
			$attributes['style'] = [ 'spacing' => [ 'margin' => [ 'bottom' => self::IMAGE_GAP ] ] ];
		}

		$attributes = $this->with_attachment_id( $attributes, $src );

		$max_width = $node->attr( 'image_max_width' );

		if ( Values::is_length( $max_width ) ) {
			$attributes['width'] = $max_width;
		}

		if ( '' !== $url ) {
			$attributes['href']            = self::url( $url );
			$attributes['linkDestination'] = 'custom';

			$attributes = $this->with_new_tab( $node, $attributes );
		}

		return [ Spec::block( 'core/image', $attributes ) ];
	}
}
