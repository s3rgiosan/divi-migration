<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * The core Accordion block: heading, panel and the core toggle icon.
 */
class CoreAccordion implements AccordionTarget {

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'core/accordion', 'core/accordion-item', 'core/accordion-heading', 'core/accordion-panel' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( AccordionData $accordion ): array {
		$item_style        = $accordion->item_style;
		$item_style['css'] = Css::accordion_states( $accordion, '.wp-block-accordion-heading', '.wp-block-accordion-heading__toggle-icon' );

		$header_style = self::without_line_height( $accordion->header_style );
		$items        = [];

		foreach ( $accordion->items as $item ) {
			$items[] = Spec::block(
				'core/accordion-item',
				[
					'openByDefault' => $item->open,
					'style'         => $item_style,
				],
				[
					Spec::block(
						'core/accordion-heading',
						[
							'title'         => $item->title,
							'level'         => $accordion->heading_level,
							'openByDefault' => $item->open,
							'style'         => $header_style,
						]
					),
					Spec::block(
						'core/accordion-panel',
						[ 'style' => $accordion->panel_style ],
						$accordion->panel_blocks( $item )
					),
				]
			);
		}

		$attributes                 = $accordion->attributes;
		$attributes['headingLevel'] = $accordion->heading_level;
		$attributes['autoclose']    = $accordion->autoclose;
		$attributes['style']        = array_replace_recursive( $attributes['style'] ?? [], $accordion->gap_style() );

		return [ Spec::block( 'core/accordion', $attributes, $items ) ];
	}

	/**
	 * Removes the line height from a style attribute and its viewport
	 * states: the Accordion Heading block has no line height support.
	 *
	 * @param array $style Style attribute.
	 * @return array
	 */
	private static function without_line_height( array $style ): array {
		$style = self::drop_line_height( $style );

		foreach ( [ '@tablet', '@mobile' ] as $state ) {
			if ( ! isset( $style[ $state ] ) || ! is_array( $style[ $state ] ) ) {
				continue;
			}

			$style[ $state ] = self::drop_line_height( $style[ $state ] );

			if ( [] === $style[ $state ] ) {
				unset( $style[ $state ] );
			}
		}

		return $style;
	}

	/**
	 * Removes the line height from one level of a style attribute.
	 *
	 * @param array $style Style attribute, or one viewport state of it.
	 * @return array
	 */
	private static function drop_line_height( array $style ): array {
		unset( $style['typography']['lineHeight'] );

		if ( [] === ( $style['typography'] ?? null ) ) {
			unset( $style['typography'] );
		}

		return $style;
	}
}
