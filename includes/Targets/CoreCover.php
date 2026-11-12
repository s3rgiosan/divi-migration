<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Core blocks without a carousel: each slide becomes a Cover block, stacked.
 *
 * Every slide keeps its background and content, so nothing is lost on a site
 * without a carousel block; the slides show one below the other.
 */
class CoreCover implements CarouselTarget {

	/**
	 * Background position keywords, mapped to Cover focal point coordinates.
	 */
	const FOCAL_POINTS = [
		'left'   => 0,
		'top'    => 0,
		'center' => 0.5,
		'right'  => 1,
		'bottom' => 1,
	];

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'core/cover', 'core/group' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( CarouselData $carousel ): array {
		$covers = [];

		foreach ( $carousel->slides as $slide ) {
			$covers[] = $this->cover( $slide, $carousel->height );
		}

		$attributes = array_merge(
			$carousel->attributes,
			[
				'align'  => $carousel->fullwidth ? 'full' : '',
				'layout' => [ 'type' => 'default' ],
			]
		);

		return [ Spec::block( 'core/group', $attributes, $covers ) ];
	}

	/**
	 * Returns the Cover spec for one slide.
	 *
	 * @param Slide  $slide  Slide.
	 * @param string $height Minimum height (CSS length), or an empty string.
	 * @return array
	 */
	private function cover( Slide $slide, string $height ): array {
		$attributes = array_merge(
			$slide->attributes,
			[
				'align'           => 'full',
				'contentPosition' => 'center center',
				'style'           => $slide->style,
			]
		);

		$image = $slide->background['image'] ?? '';
		$color = $slide->background['color'] ?? '';

		if ( '' !== $image ) {
			$attributes['url']      = $image;
			$attributes['dimRatio'] = 0;

			if ( ! empty( $slide->background['id'] ) ) {
				$attributes['id'] = $slide->background['id'];
			}

			$focal_point = $this->focal_point( $slide->background['position'] ?? '' );

			if ( null !== $focal_point ) {
				$attributes['focalPoint'] = $focal_point;
			}
		} elseif ( '' !== $color ) {
			$attributes['dimRatio'] = 100;

			if ( str_starts_with( $color, Css::PRESET_PREFIX ) ) {
				$attributes['overlayColor'] = substr( $color, strlen( Css::PRESET_PREFIX ) );
			} else {
				$attributes['customOverlayColor'] = $color;
			}
		}

		$found = preg_match( '/^(\d*\.?\d+)(px|vh|vw|em|rem|%)$/', $height, $matches );

		if ( 1 === $found ) {
			$attributes['minHeight']     = (float) $matches[1];
			$attributes['minHeightUnit'] = $matches[2];
		}

		return Spec::block( 'core/cover', $attributes, $slide->blocks );
	}

	/**
	 * Converts a CSS background position, such as `right center`, into a focal point.
	 *
	 * @param string $position Background position keywords.
	 * @return array{x: float, y: float}|null
	 */
	private function focal_point( string $position ): ?array {
		$parts = explode( ' ', trim( $position ) );
		$count = count( $parts );

		if ( 2 !== $count || ! isset( self::FOCAL_POINTS[ $parts[0] ], self::FOCAL_POINTS[ $parts[1] ] ) ) {
			return null;
		}

		return [
			'x' => self::FOCAL_POINTS[ $parts[0] ],
			'y' => self::FOCAL_POINTS[ $parts[1] ],
		];
	}
}
