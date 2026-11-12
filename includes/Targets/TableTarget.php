<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the blocks for a table found in a module's HTML.
 */
interface TableTarget extends Target {

	/**
	 * Returns the block spec for a table.
	 *
	 * @param TableData $table Table.
	 * @return array Block spec.
	 */
	public function build( TableData $table ): array;
}
