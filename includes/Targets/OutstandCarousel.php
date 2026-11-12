<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Outstand Carousel: a looping carousel with slides, arrows and dots.
 */
class OutstandCarousel implements CarouselTarget {

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'outstand/carousel', 'outstand/slides', 'outstand/slide' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( CarouselData $carousel ): array {
		$slides = array_map( [ $this, 'slide' ], $carousel->slides );
		$inner  = [ Spec::block( 'outstand/slides', [], $slides ) ];
		$count  = count( $slides );

		if ( $carousel->arrows && $count > 1 ) {
			$inner[] = Spec::block( 'outstand/carousel-navigation', [ 'style' => [ 'color' => [ 'text' => $carousel->arrow_color ] ] ] );
		}

		if ( $carousel->dots && $count > 1 ) {
			$inner[] = Spec::block( 'outstand/carousel-pagination', [ 'style' => [ 'color' => [ 'text' => $carousel->dot_color ] ] ] );
		}

		$attributes = array_merge(
			$carousel->attributes,
			[
				'type'         => 'loop',
				'height'       => $carousel->height,
				'autoplay'     => $carousel->autoplay,
				'interval'     => $carousel->interval,
				'pauseOnHover' => $carousel->pause_on_hover,
				'align'        => $carousel->fullwidth ? 'full' : '',
			]
		);

		return [ Spec::block( 'outstand/carousel', $attributes, $inner ) ];
	}

	/**
	 * Returns the spec for one slide: a vertical flex container with the
	 * slide's background and content.
	 *
	 * @param Slide $slide Slide.
	 * @return array
	 */
	private function slide( Slide $slide ): array {
		$style = $slide->style;

		if ( '' !== ( $slide->background['color'] ?? '' ) ) {
			$style['color']['background'] = $slide->background['color'];
		}

		if ( '' !== ( $slide->background['image'] ?? '' ) ) {
			$style['background'] = array_filter(
				[
					'backgroundImage'    => array_filter(
						[
							'url'    => $slide->background['image'],
							'id'     => $slide->background['id'] ?? 0,
							'source' => 'file',
						]
					),
					'backgroundPosition' => $slide->background['position'] ?? '',
					'backgroundSize'     => $slide->background['size'] ?? 'cover',
					'backgroundRepeat'   => $slide->background['repeat'] ?? '',
				]
			);
		}

		$attributes = array_merge(
			$slide->attributes,
			[
				'layout' => [
					'type'              => 'flex',
					'orientation'       => 'vertical',
					'justifyContent'    => 'center',
					'verticalAlignment' => 'center',
				],
				'style'  => $style,
			]
		);

		return Spec::block( 'outstand/slide', $attributes, $slide->blocks );
	}
}
