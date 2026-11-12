<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a text module's HTML into core blocks.
 *
 * The editor's raw handler turns the HTML into paragraphs, headings, lists
 * and images. They sit in a Group, one per module, which keeps the module's
 * spacing between paragraphs and holds the body text settings its blocks
 * inherit. Heading settings, set per level in Divi, go on each heading.
 */
class Text extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$variants = $this->viewport_contents( $node );

		if ( [] === $variants ) {
			return [];
		}

		$presets = $converter->presets;
		$wrapper = new StyleSet();

		$this->design( $node, $wrapper, $presets, [], $converter );
		$this->typography( $node, 'text', $wrapper, $presets );
		$this->inherited_line_height( $node, $wrapper, $converter );
		$this->background_layout( $node, $wrapper, $presets );
		$this->links( $node, $wrapper, $converter );

		$apply = $this->heading_styles( $node, $converter );
		$align = $this->alignment( $node, 'text_orientation', $converter );

		$aligned = implode( '', $align );

		if ( '' !== $aligned ) {
			$alignment = new StyleSet();
			$alignment->set( [ 'typography', 'textAlign' ], $align );

			// The List block has no text alignment support.
			foreach ( [ 'core/paragraph', 'core/heading' ] as $name ) {
				$apply[ $name ] = array_replace_recursive( $apply[ $name ] ?? [], [ 'style' => $alignment->to_style() ] );
			}

			foreach ( range( 1, 6 ) as $level ) {
				$key = 'core/heading:' . $level;

				if ( isset( $apply[ $key ] ) ) {
					$apply[ $key ] = array_replace_recursive( [ 'style' => $alignment->to_style() ], $apply[ $key ] );
				}
			}
		}

		// Divi pages get paragraphs from `wpautop` on `the_content`, which runs before shortcodes render.
		$specs = $this->variant_blocks(
			$variants,
			function ( string $html ) use ( $apply, $aligned, $node, $converter ) {
				$has_list = preg_match( '/<(ul|ol)[\s>]/i', $html );

				if ( '' !== $aligned && 1 === $has_list ) {
					$converter->note( $node->tag, 'not converted: list text alignment' );
				}

				return [ Spec::raw( wpautop( $html ), $apply ) ];
			}
		);

		return $this->wrap( $node, $specs, $wrapper, $this->common_attributes( $node, $converter->settings->class_name( 'text' ) ) );
	}

	/**
	 * Returns heading styles per level, keyed `core/heading:<level>`.
	 *
	 * Divi names the first level's settings `header_*` and the others
	 * `header_<level>_*`.
	 *
	 * @param Node      $node      Text module node.
	 * @param Converter $converter Converter.
	 * @return array<string, array>
	 */
	private function heading_styles( Node $node, Converter $converter ): array {
		$apply = [];

		foreach ( range( 1, 6 ) as $level ) {
			$prefix = 1 === $level ? 'header' : 'header_' . $level;
			$style  = new StyleSet();

			$this->typography( $node, $prefix, $style, $converter->presets );

			if ( ! $style->is_empty() ) {
				$apply[ 'core/heading:' . $level ] = [ 'style' => $style->to_style() ];
			}
		}

		return $apply;
	}

	/**
	 * Adds the link color and font settings to the wrapper's link element.
	 *
	 * @param Node      $node      Text module node.
	 * @param StyleSet  $wrapper   Wrapper styles.
	 * @param Converter $converter Converter.
	 * @return void
	 */
	private function links( Node $node, StyleSet $wrapper, Converter $converter ): void {
		$color = $node->attr( 'link_text_color' );

		if ( '' !== $color ) {
			$wrapper->set_value( [ 'elements', 'link', 'color', 'text' ], $converter->presets->color( $color ) );
		}
	}

	/**
	 * Sets the body line height in pixels on a text module that sets its own
	 * font size and no line height.
	 *
	 * Divi sets the body line height in `em`, so the module text inherits it
	 * in pixels and not as a multiple of the module's font size.
	 *
	 * @param Node      $node      Text module node.
	 * @param StyleSet  $wrapper   Wrapper styles.
	 * @param Converter $converter Converter.
	 * @return void
	 */
	private function inherited_line_height( Node $node, StyleSet $wrapper, Converter $converter ): void {
		$sizes        = implode( '', Values::responsive( $node, 'text_font_size' ) );
		$line_heights = implode( '', Values::responsive( $node, 'text_line_height' ) );

		if ( '' === $sizes || '' !== $line_heights ) {
			return;
		}

		$wrapper->set( [ 'typography', 'lineHeight' ], $converter->settings->body_line_heights() );
	}
}
