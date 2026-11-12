<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A set of blocks a module type converts into.
 */
interface Target {

	/**
	 * Returns the block names the target needs, so it is used only when they are registered.
	 *
	 * @return string[]
	 */
	public function blocks(): array;
}
