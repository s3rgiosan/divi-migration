<?php

namespace S3S\WP\DiviMigration\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * A form read from Divi or one of its plugins: its fields, button and
 * messages, for a form target to rebuild.
 */
final class FormData {

	/**
	 * Constructor.
	 *
	 * @param string    $key     Unique key of the source form (`bloom:optin_4`), so a form is created once.
	 * @param string    $label   Form name in the form plugin.
	 * @param array[]   $fields  Fields, each with `type` (`text`, `email`, `textarea`, `select`, `radio`,
	 *                           `checkbox`), `label`, `required` (bool), `placeholder`, `options` (string[])
	 *                           and `width` (share of the row from small screens up; 1 by default).
	 * @param string    $submit  Submit button text.
	 * @param string    $success Message shown after a submission.
	 * @param ?string[] $email Addresses that get each submission by email: an empty list for the
	 *                         site's admin email, null for no email.
	 * @param bool      $labels  Whether fields show their labels; without, placeholders name them.
	 * @param float     $submit_width Share of the row the submit button takes from small screens up.
	 * @param bool      $submit_full  Whether the submit button fills its column.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public array $fields,
		public string $submit,
		public string $success = '',
		public ?array $email = null,
		public bool $labels = true,
		public float $submit_width = 1.0,
		public bool $submit_full = true,
	) {}
}
