<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * One slide read from a Divi slider, ready for a carousel target.
 */
final class Slide {

	/**
	 * Creates a slide.
	 *
	 * @param array[] $blocks     Block specs for the slide's content: heading, text, button, image.
	 * @param array   $background Background: `image` (URL), `id` (attachment ID or 0), `position`
	 *                            (CSS, such as `right center`), `size`, `repeat` and `color`
	 *                            (a preset reference or a CSS color). Empty values are left out.
	 * @param array   $style      Other styles for the slide container: padding, border, text color.
	 * @param array   $attributes Shared attributes: `className`, `anchor`, `metadata`.
	 */
	public function __construct(
		public readonly array $blocks,
		public readonly array $background,
		public readonly array $style,
		public readonly array $attributes,
	) {}
}
