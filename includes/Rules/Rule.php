<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Converts one Divi module type into block specs.
 */
interface Rule {

	/**
	 * Converts a module node.
	 *
	 * @param Node      $node      Module node.
	 * @param Converter $converter Converter, for child modules and shared settings.
	 * @return array[] Block specs. An empty list drops the module.
	 */
	public function convert( Node $node, Converter $converter ): array;
}
