<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a call to action into a Group with a heading, text and a button.
 *
 * Divi gives the module the theme's accent color as its background unless
 * `use_background_color` is off, and centers the text by default.
 */
class CallToAction extends Module {

	/**
	 * Divi's accent color when the theme's `accent_color` option is not set.
	 */
	const DEFAULT_ACCENT = '#7ebec5';

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets = $converter->presets;
		$align   = $this->alignment( $node, 'text_orientation', $converter )['desktop'];
		$align   = '' !== $align ? $align : 'center';
		$inner   = [];

		$title = $node->attr( 'title' );

		if ( '' !== $title ) {
			$heading_style = new StyleSet();
			$this->typography( $node, 'header', $heading_style, $presets );
			$heading_style->set_value( [ 'typography', 'textAlign' ], $node->attr( 'header_text_align', $align ) );

			$inner[] = $this->heading_block( esc_html( $title ), $this->heading_level( $node, 'header_level', 2 ), $heading_style );
		}

		$body = trim( $node->content );

		if ( '' !== $body ) {
			$body_style = new StyleSet();
			$this->typography( $node, 'body', $body_style, $presets );
			$body_style->set_value( [ 'typography', 'textAlign' ], $align );
			$inner[] = Spec::raw( wpautop( $body ), [ 'core/paragraph' => [ 'style' => $body_style->to_style() ] ] );
		}

		array_push( $inner, ...$this->button_block( $node, 'button', $node->attr( 'button_text' ), $node->attr( 'button_url' ), $converter, $align ) );

		$style          = new StyleSet();
		$use_color      = $node->attr( 'use_background_color', 'on' );
		$enable_color   = $node->attr( 'background_enable_color', 'on' );
		$has_background = 'off' !== $use_color;
		$skip           = $has_background ? [] : [ 'background' ];

		$this->design( $node, $style, $presets, $skip, $converter );

		$background = $style->to_style()['color']['background'] ?? '';

		if ( $has_background && '' === $background && 'off' !== $enable_color ) {
			$style->set_value( [ 'color', 'background' ], $presets->color( self::accent_color() ) );
		}

		$this->background_layout( $node, $style, $presets, 'dark' );

		$attributes = $this->group_attributes( $node, $style, $converter->settings->class_name( 'text' ) );

		return $this->wrap( $node, [ Spec::block( 'core/group', $attributes, $inner ) ], new StyleSet() );
	}

	/**
	 * Returns the accent color of the Divi theme: the `accent_color` setting
	 * in the `et_divi` option, or Divi's default.
	 *
	 * @return string
	 */
	private static function accent_color(): string {
		$options = get_option( 'et_divi' );
		$color   = is_array( $options ) ? ( $options['accent_color'] ?? '' ) : '';
		$color   = is_string( $color ) ? trim( $color ) : '';
		$valid   = 1 === preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\))$/i', $color );

		return $valid ? $color : self::DEFAULT_ACCENT;
	}
}
