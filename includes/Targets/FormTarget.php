<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds a form in a form plugin and returns the block that shows it.
 */
interface FormTarget extends Target {

	/**
	 * Returns the block spec that shows a form, creating the form the first
	 * time its key is seen.
	 *
	 * @param FormData $form   Form.
	 * @param bool     $create Whether to create a form that does not exist yet; without, the block has no form.
	 * @return array|null Block spec, or null when the form cannot be created.
	 */
	public function build( FormData $form, bool $create = true ): ?array;

	/**
	 * Returns why the last form could not be created.
	 *
	 * @return string
	 */
	public function error(): string;
}
