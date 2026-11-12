<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks a Divi accordion or toggle converts into.
 */
interface AccordionTarget extends Target {

	/**
	 * Returns block specs for an accordion.
	 *
	 * @param AccordionData $accordion Accordion read from the Divi module.
	 * @return array[]
	 */
	public function build( AccordionData $accordion ): array;
}
