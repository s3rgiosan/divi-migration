<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * A Divi accordion or toggle, read into data an accordion target turns into blocks.
 *
 * Colors are preset references (`var:preset|color|<slug>`) or CSS colors;
 * `Css::color()` turns either into a CSS value.
 */
final class AccordionData {

	/**
	 * Creates the accordion data.
	 *
	 * @param AccordionItem[] $items             Items, in order.
	 * @param int             $heading_level     Heading level of the titles, 1 to 6.
	 * @param bool            $autoclose         Whether opening an item closes the others (Divi accordions do; toggles don't).
	 * @param array           $item_style        Style attribute for each item: border and padding.
	 * @param array           $header_style      Style attribute for each title: typography, without color.
	 * @param array           $panel_style       Style attribute for each panel: typography and top padding.
	 * @param string          $panel_class       Class name for a Group around the blocks of each panel, which gives them the text spacing.
	 * @param string          $closed_background Background of a closed item, or an empty string.
	 * @param string          $open_background   Background of an open item, or an empty string.
	 * @param string          $closed_title      Title color of a closed item, or an empty string.
	 * @param string          $open_title        Title color of an open item, or an empty string.
	 * @param string          $icon_color        Icon color, or an empty string.
	 * @param string          $gap               Space between items (CSS length).
	 * @param array           $attributes        Shared attributes and styles for the accordion wrapper.
	 * @param array           $viewport_gaps     Space between items on tablets and phones, keyed `tablet` and `phone`, when it differs from `$gap`.
	 */
	public function __construct(
		public readonly array $items,
		public readonly int $heading_level,
		public readonly bool $autoclose,
		public readonly array $item_style,
		public readonly array $header_style,
		public readonly array $panel_style,
		public readonly string $panel_class,
		public readonly string $closed_background,
		public readonly string $open_background,
		public readonly string $closed_title,
		public readonly string $open_title,
		public readonly string $icon_color,
		public readonly string $gap,
		public readonly array $attributes,
		public readonly array $viewport_gaps = [],
	) {}

	/**
	 * Returns the style attribute part that spaces the items, per viewport.
	 *
	 * @return array
	 */
	public function gap_style(): array {
		$style = [ 'spacing' => [ 'blockGap' => $this->gap ] ];

		$keys = [
			'tablet' => '@tablet',
			'phone'  => '@mobile',
		];

		foreach ( $keys as $viewport => $key ) {
			$gap = $this->viewport_gaps[ $viewport ] ?? '';

			if ( '' !== $gap && $gap !== $this->gap ) {
				$style[ $key ] = [ 'spacing' => [ 'blockGap' => $gap ] ];
			}
		}

		return $style;
	}

	/**
	 * Returns the blocks of an item's panel. With a panel class, they sit in a
	 * Group that has the class, because the class is a Group block style.
	 *
	 * @param AccordionItem $item Item.
	 * @return array[]
	 */
	public function panel_blocks( AccordionItem $item ): array {
		if ( '' === $this->panel_class ) {
			return $item->blocks;
		}

		return [
			Spec::block(
				'core/group',
				[
					'className' => $this->panel_class,
					'layout'    => [ 'type' => 'default' ],
				],
				$item->blocks
			),
		];
	}
}
