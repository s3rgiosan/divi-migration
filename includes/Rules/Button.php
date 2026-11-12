<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a button module into a Buttons block with one Button.
 *
 * The button's own styles apply only when the module turns on Divi's custom
 * button styles (`custom_button`); otherwise the theme's button styles apply.
 */
class Button extends Module {

	/**
	 * Divi button alignments, mapped to flex justification.
	 */
	const JUSTIFY = [
		'left'   => 'left',
		'center' => 'center',
		'right'  => 'right',
	];

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$text = $node->attr( 'button_text' );

		if ( '' === $text ) {
			return [];
		}

		$button = [
			'text' => esc_html( $text ),
			'url'  => self::url( $node->attr( 'button_url' ) ),
		];

		$button = $this->with_new_tab( $node, $button );

		$style = new StyleSet();
		$this->spacing( $node, 'custom_padding', [ 'spacing', 'padding' ], $style );

		if ( $node->is_on( 'custom_button' ) ) {
			$this->button_styles( $node, 'button', $style, $converter->presets );
		}

		$button['style'] = $style->to_style();

		$wrapper = new StyleSet();
		$this->spacing( $node, 'custom_margin', [ 'spacing', 'margin' ], $wrapper );

		$alignment = Values::responsive( $node, 'button_alignment' );
		$layout    = [ 'type' => 'flex' ];

		if ( isset( self::JUSTIFY[ $alignment['desktop'] ] ) ) {
			$layout['justifyContent'] = self::JUSTIFY[ $alignment['desktop'] ];
		}

		$wrapper_style = $wrapper->to_style();

		foreach ( [
			'tablet' => '@tablet',
			'phone'  => '@mobile',
		] as $viewport => $state ) {
			$justify = self::JUSTIFY[ $alignment[ $viewport ] ] ?? '';

			if ( '' !== $justify && $justify !== ( $layout['justifyContent'] ?? '' ) ) {
				$wrapper_style[ $state ]['layout']['justifyContent'] = $justify;
			}
		}

		$attributes = array_merge(
			$this->common_attributes( $node ),
			[
				'layout' => $layout,
				'style'  => $wrapper_style,
			]
		);

		return [ Spec::block( 'core/buttons', $attributes, [ Spec::block( 'core/button', $button ) ] ) ];
	}
}
