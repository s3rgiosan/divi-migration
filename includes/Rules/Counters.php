<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts bar counters into static bars. Each counter becomes a Group with
 * the title above a track Group, and a filled Group sized to the percentage
 * inside the track, holding the percentage. A percentage wider than its
 * filled Group is cut off and does not wrap.
 *
 * Divi animates the bars as they scroll into view; the bars here show their
 * final width.
 */
class Counters extends Module {

	/**
	 * Divi's counter title size and line height.
	 */
	const TITLE_TYPOGRAPHY = [
		'fontSize'   => '12px',
		'lineHeight' => '1.6',
	];

	/**
	 * Divi's counter title color on a light background.
	 */
	const LIGHT_TITLE_COLOR = '#666666';

	/**
	 * Divi's style of the percentage inside a bar.
	 */
	const VALUE_TYPOGRAPHY = [
		'fontSize'   => '12px',
		'fontWeight' => '600',
		'lineHeight' => '1.6',
		'textAlign'  => 'right',
	];

	/**
	 * Divi's space below a bar, except the last one.
	 */
	const BAR_GAP = '10px';

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets  = $converter->presets;
		$counters = array_values( array_filter( $node->children, fn( Node $child ) => 'et_pb_counter' === $child->tag ) );

		if ( [] === $counters ) {
			return [];
		}

		$track_color = $presets->color( $node->attr( 'background_color', '#dddddd' ) );
		$bar_color   = $presets->color( $node->attr( 'bar_bg_color', '#7ebec5' ) );
		$show_value  = 'off' !== $node->attr( 'use_percentages', 'on' );

		$title_style = new StyleSet();

		foreach ( self::TITLE_TYPOGRAPHY as $property => $value ) {
			$title_style->set_value( [ 'typography', $property ], $value );
		}

		$layout = $node->attr( 'background_layout', 'light' );

		if ( 'dark' !== $layout ) {
			$title_style->set_value( [ 'color', 'text' ], $presets->color( self::LIGHT_TITLE_COLOR ) );
		}

		$this->typography( $node, 'title', $title_style, $presets );

		$bars = [];
		$last = count( $counters ) - 1;

		foreach ( $counters as $index => $counter ) {
			$percent = max( 0, min( 100, (int) $counter->attr( 'percent', '0' ) ) );
			$track   = $presets->color( $counter->attr( 'background_color' ) );
			$fill_bg = $presets->color( $counter->attr( 'bar_background_color' ) );
			$title   = esc_html( trim( wp_strip_all_tags( $counter->content ) ) );

			$value = $show_value
				? [
					Spec::block(
						'core/paragraph',
						[
							'content' => $percent . '%',
							'style'   => [
								'typography' => self::VALUE_TYPOGRAPHY,
								'spacing'    => [
									'padding' => [
										'left'  => '10px',
										'right' => '10px',
									],
								],
							],
						]
					),
				]
				: [];

			$fill = Spec::block(
				'core/group',
				[
					'layout' => [ 'type' => 'default' ],
					'style'  => [
						'color'      => [ 'background' => '' !== $fill_bg ? $fill_bg : $bar_color ],
						'spacing'    => [
							'padding' => [
								'top'    => '1px',
								'right'  => '1px',
								'bottom' => '1px',
								'left'   => '1px',
							],
						],
						'dimensions' => [ 'minHeight' => '6px' ],
						'css'        => 'width: ' . $percent . '%; overflow: hidden; white-space: nowrap;',
					],
				],
				$value
			);

			$track_block = Spec::block(
				'core/group',
				[
					'layout' => [ 'type' => 'default' ],
					'style'  => array_filter(
						[
							'color'   => [
								'background' => '' !== $track ? $track : $track_color,
								'text'       => $presets->color( '#ffffff' ),
							],
							'spacing' => $index < $last ? [ 'margin' => [ 'bottom' => self::BAR_GAP ] ] : [],
						]
					),
				],
				[ $fill ]
			);

			$inner = '' !== $title
				? [
					Spec::block(
						'core/paragraph',
						[
							'content' => $title,
							'style'   => $title_style->to_style(),
						]
					),
					$track_block,
				]
				: [ $track_block ];

			$bars[] = Spec::block( 'core/group', [ 'layout' => [ 'type' => 'default' ] ], $inner );
		}

		$style = new StyleSet();
		$this->design( $node, $style, $presets, [ 'background' ], $converter );
		$this->background_layout( $node, $style, $presets );

		$attributes = $this->group_attributes( $node, $style );

		return [ Spec::block( 'core/group', $attributes, $bars ) ];
	}
}
