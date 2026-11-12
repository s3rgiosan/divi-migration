<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A Divi slider, read into data a carousel target turns into blocks.
 */
final class CarouselData {

	/**
	 * Creates the carousel data.
	 *
	 * @param Slide[] $slides         Slides, in order.
	 * @param bool    $autoplay       Whether the slides advance on their own.
	 * @param int     $interval       Time between slides, in milliseconds.
	 * @param bool    $pause_on_hover Whether autoplay pauses while the pointer is over the carousel.
	 * @param string  $height         Minimum slide height (CSS length), or an empty string.
	 * @param bool    $arrows         Whether previous/next arrows show.
	 * @param bool    $dots           Whether the dot navigation shows.
	 * @param string  $arrow_color    Arrow color (a preset reference or a CSS color), or an empty string.
	 * @param string  $dot_color      Dot color, or an empty string.
	 * @param bool    $fullwidth      Whether the carousel spans the page (Divi's full-width slider).
	 * @param array   $attributes     Shared attributes and styles for the carousel wrapper: `className`, `anchor`, `metadata`, `style`.
	 */
	public function __construct(
		public readonly array $slides,
		public readonly bool $autoplay,
		public readonly int $interval,
		public readonly bool $pause_on_hover,
		public readonly string $height,
		public readonly bool $arrows,
		public readonly bool $dots,
		public readonly string $arrow_color,
		public readonly string $dot_color,
		public readonly bool $fullwidth,
		public readonly array $attributes,
	) {}
}
