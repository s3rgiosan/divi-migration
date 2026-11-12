<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Settings;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a row (or inner row) into a Columns block, or into a Group when
 * the row has a single column.
 *
 * Column widths follow Divi's grid: a k/n column spans k of n equal units
 * and the k - 1 gutters between them, so its width is f × (100% + gap) - gap.
 * With the gutter as the Columns block's gap, the columns and gaps add up to
 * the full row width.
 */
class Row extends Module {

	/**
	 * Divi's space between columns stacked on tablets and phones.
	 */
	const STACKED_GAP = '30px';

	/**
	 * Divi column types, mapped to their share of the row.
	 *
	 * The keys are strings; the sniff reads them as numbers with underscore separators.
	 */
	// phpcs:disable Universal.Arrays.DuplicateArrayKey.Found
	const FRACTIONS = [
		'4_4' => 1,
		'1_2' => 1 / 2,
		'1_3' => 1 / 3,
		'2_3' => 2 / 3,
		'1_4' => 1 / 4,
		'3_4' => 3 / 4,
		'1_5' => 1 / 5,
		'2_5' => 2 / 5,
		'3_5' => 3 / 5,
		'4_5' => 4 / 5,
		'1_6' => 1 / 6,
		'5_6' => 5 / 6,
	];
	// phpcs:enable

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$role  = 'et_pb_row_inner' === $node->tag ? 'row_inner' : 'row';
		$style = new StyleSet();

		$this->design( $node, $style, $converter->presets, [], $converter );
		$this->legacy_bottom_padding( $node, $style, $converter->settings );

		$attributes = $this->common_attributes( $node, $converter->settings->class_name( $role ) );
		$columns    = array_values( array_filter( $node->children, fn( Node $child ) => str_starts_with( $child->tag, 'et_pb_column' ) ) );

		$column_count = count( $columns );

		if ( 1 === $column_count && ! $this->has_design( $columns[0] ) ) {
			// The group stands for the column too, so it keeps the column's class, ID, label and visibility.
			$column_attributes   = $this->common_attributes( $columns[0] );
			$class_name          = trim( ( $attributes['className'] ?? '' ) . ' ' . ( $column_attributes['className'] ?? '' ) );
			$attributes          = array_replace_recursive( $column_attributes, $attributes );
			$attributes['style'] = $style->to_style();

			if ( '' !== $class_name ) {
				$attributes['className'] = $class_name;
			}

			$gap   = self::gutter_gap( $node, $converter );
			$inner = $converter->with_module_gap( self::module_gap( $gap, 100 ), fn() => $converter->convert_children( $columns[0] ) );

			return [ Spec::block( 'core/group', $this->sized( $node, $attributes ), $inner ) ];
		}

		$gap     = self::gutter_gap( $node, $converter );
		$columns = $this->columns( $node, $converter, $gap );
		$sized   = $this->sized( $node, $attributes );

		if ( $sized === $attributes ) {
			foreach ( self::gap( $gap ) as $side => $value ) {
				$style->set_value( [ 'spacing', 'blockGap', $side ], $value );
			}

			$attributes['style'] = $style->to_style();

			return [ Spec::block( 'core/columns', $attributes, $columns ) ];
		}

		// A row with its own width wraps its columns in a Group that holds the size.
		$sized['style'] = array_replace_recursive( $style->to_style(), $sized['style'] ?? [] );

		$columns_block = Spec::block( 'core/columns', [ 'style' => [ 'spacing' => [ 'blockGap' => self::gap( $gap ) ] ] ], $columns );

		return [ Spec::block( 'core/group', $sized, [ $columns_block ] ) ];
	}

	/**
	 * Returns the column gap of a row or specialty section.
	 *
	 * A custom gutter width of 0 counts as 1. Without a custom gutter, the
	 * site's gutter width applies.
	 *
	 * @param Node      $node      Row or section node.
	 * @param Converter $converter Converter.
	 * @return string Column gap, as a percentage of the row.
	 */
	public static function gutter_gap( Node $node, Converter $converter ): string {
		$width = 0;

		$gutter_width = $node->attr( 'gutter_width' );

		if ( $node->is_on( 'use_custom_gutter' ) && '' !== $gutter_width ) {
			$width = max( 1, (int) $gutter_width );
		}

		return $converter->settings->gutter( $width );
	}

	/**
	 * Returns the block gap of a Columns block: the column gap, and Divi's
	 * space between columns once they stack.
	 *
	 * @param string $gap Column gap, as a percentage of the row.
	 * @return array{top: string, left: string}
	 */
	public static function gap( string $gap ): array {
		return [
			'top'  => self::STACKED_GAP,
			'left' => $gap,
		];
	}

	/**
	 * Returns the space Divi leaves between the modules of a column.
	 *
	 * On desktop it is half the column gap of the row, written as a share of
	 * the column width. At 980px and below it is 30px, or none with no gap.
	 *
	 * @param string $gap   Column gap, as a percentage of the row.
	 * @param float  $width Column width, as a percentage of the row.
	 * @return array{desktop: string, tablet: string, phone: string}
	 */
	public static function module_gap( string $gap, float $width ): array {
		$half = (float) $gap / 2;

		if ( $half <= 0 || $width <= 0 ) {
			return array_fill_keys( [ 'desktop', 'tablet', 'phone' ], '0px' );
		}

		return [
			'desktop' => round( $half / $width * 100, 3 ) . '%',
			'tablet'  => self::STACKED_GAP,
			'phone'   => self::STACKED_GAP,
		];
	}

	/**
	 * Converts a node's column children into Column blocks.
	 *
	 * @param Node      $node      Row or specialty section node.
	 * @param Converter $converter Converter.
	 * @param string    $gap       Column gap, as a percentage of the row.
	 * @return array[]
	 */
	public function columns( Node $node, Converter $converter, string $gap = '' ): array {
		$columns = array_values( array_filter( $node->children, fn( Node $child ) => str_starts_with( $child->tag, 'et_pb_column' ) ) );
		$gap     = '' === $gap ? $converter->settings->gutter( 0 ) : $gap;
		$specs   = [];

		foreach ( $columns as $index => $column ) {
			$fraction = self::FRACTIONS[ $column->attr( 'type', '4_4' ) ] ?? 1;
			$width    = round( $fraction * ( 100 + (float) $gap ) - (float) $gap, 4 ) . '%';
			$padding  = $this->legacy_column_padding( $node, $index + 1 );

			$column_specs = $converter->with_module_gap( self::module_gap( $gap, (float) $width ), fn() => $converter->convert_node( $column ) );

			foreach ( $column_specs as &$spec ) {
				if ( 'core/column' === ( $spec['name'] ?? '' ) ) {
					$spec['attributes']['width'] = $width;

					if ( [] !== $padding ) {
						$spec['attributes']['style']['spacing']['padding'] = array_merge( $padding, $spec['attributes']['style']['spacing']['padding'] ?? [] );
					}
				}
			}
			unset( $spec );

			array_push( $specs, ...$column_specs );
		}

		return $specs;
	}

	/**
	 * Adds a row's own width to its attributes.
	 *
	 * The row spans its section and holds the width as its content size, so
	 * its columns or modules take that width per viewport. Without a width,
	 * the row keeps the section's content size and the attributes are unchanged.
	 *
	 * @param Node  $node       Row node.
	 * @param array $attributes Row attributes.
	 * @return array
	 */
	private function sized( Node $node, array $attributes ): array {
		$layout = $this->sized_layout( $node, true );

		if ( [] === $layout ) {
			return $attributes;
		}

		$attributes['align']  = 'full';
		$attributes['layout'] = $layout['base'];

		foreach ( $layout['viewports'] as $state => $viewport_layout ) {
			$attributes['style'][ $state ]['layout'] = $viewport_layout;
		}

		return $attributes;
	}

	/**
	 * Returns a column's padding set on its row in Divi's older format,
	 * `padding_<side>_<column number>`, with values in pixels.
	 *
	 * @param Node $row    Row node.
	 * @param int  $number Column number, from 1.
	 * @return array<string, string>
	 */
	private function legacy_column_padding( Node $row, int $number ): array {
		$padding = [];

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$value = $row->attr( 'padding_' . $side . '_' . $number );

			if ( '' === $value ) {
				continue;
			}

			$padding[ $side ] = is_numeric( $value ) ? $value . 'px' : $value;
		}

		return $padding;
	}

	/**
	 * Adds the bottom padding of a row's padding in Divi's older two-part
	 * format, `top|bottom`.
	 *
	 * Divi renders a row's custom padding twice. Its shared padding rule reads
	 * the parts as top, right, bottom and left, so two parts set the top and
	 * right padding at every width. Its row rule reads two parts as the top
	 * and bottom padding, which adds the bottom padding. Older content sets
	 * `padding_mobile="off"` on a row: when the row has no tablet or phone
	 * padding, the row rule applies at 981px and up only, and at 980px and
	 * below the row gets Divi's row height at the bottom.
	 *
	 * @param Node     $node     Row node.
	 * @param StyleSet $style    Row styles.
	 * @param Settings $settings Converter settings.
	 * @return void
	 */
	private function legacy_bottom_padding( Node $node, StyleSet $style, Settings $settings ): void {
		$parts      = explode( '|', $node->attr( 'custom_padding' ) );
		$part_count = count( $parts );

		if ( 2 !== $part_count ) {
			return;
		}

		$bottom = Values::spacing( '||' . $parts[1] )['bottom'] ?? '';

		if ( '' === $bottom ) {
			return;
		}

		$is_responsive   = str_starts_with( $node->attr( 'custom_padding_last_edited' ), 'on' );
		$mobile_padding  = $node->attr( 'padding_mobile' );
		$is_desktop_only = 'off' === $mobile_padding && ! $is_responsive;
		$path            = [ 'spacing', 'padding', 'bottom' ];

		if ( ! $is_desktop_only ) {
			$style->set_value( $path, $bottom );
			return;
		}

		$style->set(
			$path,
			[
				'desktop' => $bottom,
				'tablet'  => $settings->row_heights['tablet'] . 'px',
				'phone'   => $settings->row_heights['phone'] . 'px',
			]
		);
	}

	/**
	 * Whether a column has its own background, spacing or border.
	 *
	 * @param Node $column Column node.
	 * @return bool
	 */
	private function has_design( Node $column ): bool {
		$style = new StyleSet();
		$this->design( $column, $style, new Presets() );
		return ! $style->is_empty();
	}
}
