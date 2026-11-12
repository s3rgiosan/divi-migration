<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks a Divi slider converts into.
 */
interface CarouselTarget extends Target {

	/**
	 * Returns block specs for a carousel.
	 *
	 * @param CarouselData $carousel Carousel read from the Divi slider.
	 * @return array[]
	 */
	public function build( CarouselData $carousel ): array;
}
