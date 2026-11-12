<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Outstand Accordion: header, panel and a plus icon that turns into a minus.
 */
class OutstandAccordion implements AccordionTarget {

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'outstand/accordion', 'outstand/accordion-item', 'outstand/accordion-header', 'outstand/accordion-panel', 'outstand/accordion-icon' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( AccordionData $accordion ): array {
		$item_style        = $accordion->item_style;
		$item_style['css'] = Css::accordion_states( $accordion, '.wp-block-outstand-accordion-header' );

		$items = [];

		foreach ( $accordion->items as $item ) {
			$items[] = Spec::block(
				'outstand/accordion-item',
				[
					'isOpen' => $item->open,
					'style'  => $item_style,
				],
				[
					Spec::block(
						'outstand/accordion-header',
						[
							'title' => $item->title,
							'level' => $accordion->heading_level,
							'style' => $accordion->header_style,
						],
						[
							Spec::block(
								'outstand/accordion-icon',
								[
									'iconKey' => 'plus',
									'style'   => [ 'color' => [ 'text' => $accordion->icon_color ] ],
								]
							),
						]
					),
					Spec::block(
						'outstand/accordion-panel',
						[ 'style' => $accordion->panel_style ],
						$accordion->panel_blocks( $item )
					),
				]
			);
		}

		$attributes                 = $accordion->attributes;
		$attributes['headingLevel'] = $accordion->heading_level;
		$attributes['style']        = array_replace_recursive( $attributes['style'] ?? [], $accordion->gap_style() );

		return [ Spec::block( 'outstand/accordion', $attributes, $items ) ];
	}
}
