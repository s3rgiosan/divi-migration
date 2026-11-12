<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;
use S3S\WP\DiviMigration\Targets\AccordionData;
use S3S\WP\DiviMigration\Targets\AccordionItem;
use S3S\WP\DiviMigration\Targets\AccordionTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an accordion, or a single toggle, and hands it to the accordion target.
 *
 * Divi applies the module's border to each toggle, pads each toggle, and
 * gives closed and open toggles their own background and title color.
 */
class Accordion extends Module {

	/**
	 * Divi's padding inside each toggle, and above its content.
	 */
	const ITEM_PADDING = '20px';

	/**
	 * Divi's space between toggles.
	 */
	const ITEM_GAP = '10px';

	/**
	 * Divi's background for closed and open toggles when the module sets none.
	 */
	const CLOSED_BACKGROUND = '#f4f4f4';
	const OPEN_BACKGROUND   = '#ffffff';

	/**
	 * Divi's border around each toggle when the module sets none.
	 */
	const BORDER_WIDTH = '1px';
	const BORDER_COLOR = '#d9d9d9';
	const BORDER_STYLE = 'solid';

	/**
	 * Constructor.
	 *
	 * @param Rule $fallback Rule for an accordion without an accordion target.
	 */
	public function __construct( private Rule $fallback = new Fallback() ) {}

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$is_toggle = 'et_pb_toggle' === $node->tag;
		$nodes     = $is_toggle
			? [ $node ]
			: array_values( array_filter( $node->children, fn( Node $child ) => 'et_pb_accordion_item' === $child->tag ) );

		if ( [] === $nodes ) {
			return [];
		}

		$target = $converter->target( 'accordion', $node->tag );

		if ( ! $target instanceof AccordionTarget ) {
			$converter->note( $node->tag, 'kept: no accordion plugin' );
			return $this->fallback->convert( $node, $converter );
		}

		$presets = $converter->presets;

		// A toggle names its title settings `title_*`, an accordion `toggle_*`.
		$title_prefix = $is_toggle ? 'title' : 'toggle';
		$level        = $this->heading_level( $node, $title_prefix . '_level', 5 );

		$items = [];

		foreach ( $nodes as $index => $item ) {
			$items[] = new AccordionItem(
				title: esc_html( $item->attr( 'title' ) ),
				// Divi opens an accordion's first item and closes the rest; a toggle keeps its own setting.
				open: $is_toggle ? 'on' === $item->attr( 'open' ) : 0 === $index,
				blocks: [ Spec::raw( wpautop( trim( $item->content ) ) ) ],
			);
		}

		$item_style = new StyleSet();
		$this->border_sides( $node, $item_style, $converter );
		$item_style->set_value( [ 'spacing', 'padding' ], array_fill_keys( [ 'top', 'right', 'bottom', 'left' ], self::ITEM_PADDING ) );

		$header_style = new StyleSet();
		$this->typography( $node, $title_prefix, $header_style, $presets );

		$panel_style = new StyleSet();
		$this->typography( $node, 'body', $panel_style, $presets );
		$panel_style->set_value( [ 'spacing', 'padding', 'top' ], self::ITEM_PADDING );

		$title_color = $node->attr( $title_prefix . '_text_color' );

		$style = new StyleSet();
		$this->design( $node, $style, $presets, [ 'border' ], $converter );

		$attributes          = $this->common_attributes( $node );
		$attributes['style'] = $style->to_style();

		// In a column, Divi spaces the items like modules, over the accordion's own 10px.
		$module_gap = $converter->module_gap();

		return $target->build(
			new AccordionData(
				items: $items,
				heading_level: $level,
				autoclose: ! $is_toggle,
				item_style: $item_style->to_style(),
				header_style: $this->without_color( $header_style->to_style() ),
				panel_style: $panel_style->to_style(),
				panel_class: $converter->settings->class_name( 'text' ),
				closed_background: $presets->color( $node->attr( 'closed_toggle_background_color', self::CLOSED_BACKGROUND ) ),
				open_background: $presets->color( $node->attr( 'open_toggle_background_color', self::OPEN_BACKGROUND ) ),
				closed_title: $presets->color( $node->attr( 'closed_toggle_text_color', $title_color ) ),
				open_title: $presets->color( $node->attr( 'open_toggle_text_color', $title_color ) ),
				icon_color: $presets->color( $node->attr( 'icon_color' ) ),
				gap: $module_gap['desktop'] ?? self::ITEM_GAP,
				attributes: $attributes,
				viewport_gaps: $module_gap ?? [],
			)
		);
	}

	/**
	 * Removes the text color from a style attribute: the title color changes
	 * with the item's state, so the target sets it.
	 *
	 * @param array $style Style attribute.
	 * @return array
	 */
	private function without_color( array $style ): array {
		unset( $style['color']['text'] );

		if ( [] === ( $style['color'] ?? null ) ) {
			unset( $style['color'] );
		}

		return $style;
	}

	/**
	 * Adds the module's border, set for all sides or per side, to a style set.
	 *
	 * @param Node      $node      Accordion node.
	 * @param StyleSet  $style     Item styles.
	 * @param Converter $converter Converter.
	 * @return void
	 */
	private function border_sides( Node $node, StyleSet $style, Converter $converter ): void {
		$all_width = $node->attr( 'border_width_all', self::BORDER_WIDTH );
		$all_style = $node->attr( 'border_style_all', self::BORDER_STYLE );
		$all_color = $converter->presets->color( $node->attr( 'border_color_all', self::BORDER_COLOR ) );

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$width = $node->attr( 'border_width_' . $side, $all_width );
			$size  = (float) $width;

			if ( ! Values::is_length( $width ) || 0.0 === $size ) {
				continue;
			}

			$color = $converter->presets->color( $node->attr( 'border_color_' . $side ) );

			$style->set_value( [ 'border', $side, 'width' ], $width );
			$style->set_value( [ 'border', $side, 'style' ], $node->attr( 'border_style_' . $side, $all_style ) );
			$style->set_value( [ 'border', $side, 'color' ], '' !== $color ? $color : $all_color );
		}
	}
}
