<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a section into a full-width Group.
 *
 * A regular section uses a constrained layout, so its rows take the theme's
 * content width. A full-width section lets its modules span the page. A
 * specialty section holds columns directly; they become a Columns block.
 * A section with a width, a maximum width or an alignment keeps them through
 * a full-width wrapper Group.
 */
class Section extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$is_fullwidth = $node->is_on( 'fullwidth' );
		$role         = $is_fullwidth ? 'section_fullwidth' : 'section';
		$style        = new StyleSet();

		$this->design( $node, $style, $converter->presets, [], $converter );

		$inner = $node->is_on( 'specialty' )
			? $this->specialty_columns( $node, $converter )
			: $converter->convert_children( $node );

		$attributes = array_merge(
			$this->common_attributes( $node, $converter->settings->class_name( $role ) ),
			[
				'align'  => 'full',
				'layout' => [ 'type' => $is_fullwidth ? 'default' : 'constrained' ],
				'style'  => $style->to_style(),
			]
		);

		$layout = $this->sized_layout( $node );

		if ( [] === $layout ) {
			return [ Spec::block( 'core/group', $attributes, $inner ) ];
		}

		// A section with its own width sits in a full-width Group whose content size is that width.
		unset( $attributes['align'] );

		$wrapper_style = [];

		foreach ( $layout['viewports'] as $state => $viewport_layout ) {
			$wrapper_style[ $state ]['layout'] = $viewport_layout;
		}

		return [
			Spec::block(
				'core/group',
				[
					'align'  => 'full',
					'layout' => $layout['base'],
					'style'  => $wrapper_style,
				],
				[ Spec::block( 'core/group', $attributes, $inner ) ]
			),
		];
	}

	/**
	 * Converts a specialty section's columns into one Columns block.
	 *
	 * @param Node      $node      Section node.
	 * @param Converter $converter Converter.
	 * @return array[]
	 */
	private function specialty_columns( Node $node, Converter $converter ): array {
		$gap     = Row::gutter_gap( $node, $converter );
		$columns = ( new Row() )->columns( $node, $converter, $gap );

		return [
			Spec::block(
				'core/columns',
				[
					'className' => $converter->settings->class_name( 'row' ),
					'style'     => [ 'spacing' => [ 'blockGap' => Row::gap( $gap ) ] ],
				],
				$columns
			),
		];
	}
}
