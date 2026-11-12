<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a number counter into a large number above its title, centered.
 *
 * Divi counts the number up as it scrolls into view; the number here shows
 * its final value.
 */
class NumberCounter extends Module {

	/**
	 * Divi's default number size.
	 */
	const NUMBER_SIZE = '72px';

	/**
	 * Divi's space between the number and the title.
	 */
	const NUMBER_GAP = '20px';

	/**
	 * Divi's title size, as a multiple of the heading size option.
	 */
	const TITLE_SCALE = 0.6;

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets = $converter->presets;
		$number  = $node->attr( 'number', '0' );

		$percent_sign = $node->attr( 'percent_sign', 'on' );

		if ( 'off' !== $percent_sign ) {
			$number .= '%';
		}

		$number_style = new StyleSet();
		$this->typography( $node, 'number', $number_style, $presets );
		$number_style->set_value( [ 'typography', 'textAlign' ], 'center' );

		$title = $node->attr( 'title' );

		// Divi leaves 20px between the number and the title.
		$style                                   = $number_style->to_style();
		$style['typography']['fontSize']       ??= self::NUMBER_SIZE;
		$style['typography']['lineHeight']     ??= '1';
		$style['color']['text']                ??= $presets->color( $node->attr( 'counter_color' ) );
		$style['spacing']['padding']['bottom'] ??= '' !== $title ? self::NUMBER_GAP : '0px';

		$inner = [
			Spec::block(
				'core/paragraph',
				[
					'content' => esc_html( $number ),
					'style'   => $style,
				]
			),
		];

		if ( '' !== $title ) {
			// Divi sizes the title like an h4: 0.6 times the heading size option.
			$title_sizes = array_map(
				fn( int $size ) => $presets->font_size( (int) ( $size * self::TITLE_SCALE ) . 'px' ),
				$converter->settings->header_font_sizes()
			);

			$title_style = new StyleSet();
			$title_style->set( [ 'typography', 'fontSize' ], $title_sizes );
			$this->typography( $node, 'title', $title_style, $presets );
			$title_style->set_value( [ 'typography', 'textAlign' ], 'center' );

			$inner[] = $this->heading_block( esc_html( $title ), $this->heading_level( $node, 'title_level', 3 ), $title_style );
		}

		$wrapper = new StyleSet();
		$this->design( $node, $wrapper, $presets, [], $converter );
		$this->background_layout( $node, $wrapper, $presets );

		$attributes = $this->group_attributes( $node, $wrapper, $converter->settings->class_name( 'text' ) );

		return [ Spec::block( 'core/group', $attributes, $inner ) ];
	}
}
