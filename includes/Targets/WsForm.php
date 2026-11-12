<?php

namespace S3S\WP\DiviMigration\Targets;

use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds forms in WS Form and shows them with its block.
 *
 * Each form is created once, from WS Form's JSON form definition, then
 * adjusted: the consent checkbox WS Form adds is removed, the submit button
 * gets its text and width, the success message is set, and the email action goes to
 * the form's addresses or is removed. The form ID per source key is kept in the
 * `s3s_divi_migration_forms` option, so converting again reuses the form.
 *
 * WS Form checks the current user's capabilities: run WP-CLI with `--user`.
 */
class WsForm implements FormTarget {

	/**
	 * Option that holds the WS Form form ID per source form key.
	 */
	const OPTION = 's3s_divi_migration_forms';

	/**
	 * Why the last form could not be created.
	 *
	 * @var string
	 */
	private string $error = '';

	/**
	 * {@inheritDoc}
	 */
	public function blocks(): array {
		return [ 'wsf-block/form-add' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function build( FormData $form, bool $create = true ): ?array {
		$this->error = '';

		$forms = get_option( self::OPTION, [] );
		$forms = is_array( $forms ) ? $forms : [];
		$saved = $forms[ $form->key ] ?? 0;

		// Only an integer or a digit string is a form ID.
		$form_id = is_int( $saved ) || ( is_string( $saved ) && ctype_digit( $saved ) ) ? (int) $saved : 0;

		if ( ! $this->exists( $form_id ) ) {
			$form_id = 0;

			if ( $create ) {
				$form_id = $this->create( $form );

				if ( 0 === $form_id ) {
					return null;
				}

				$forms[ $form->key ] = $form_id;
				update_option( self::OPTION, $forms, false );
			}
		}

		return Spec::block( 'wsf-block/form-add', [ 'form_id' => 0 === $form_id ? '' : (string) $form_id ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Whether a WS Form form exists and is not in the trash.
	 *
	 * @param int $form_id Form ID.
	 * @return bool
	 */
	private function exists( int $form_id ): bool {
		global $wpdb;

		if ( $form_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WS Form keeps forms in its own table.
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wsf_form WHERE id = %d", $form_id ) );

		return null !== $status && 'trash' !== $status;
	}

	/**
	 * Creates and publishes a form, and returns its ID, or 0 on failure.
	 *
	 * @param FormData $form Form.
	 * @return int
	 */
	private function create( FormData $form ): int {
		if ( ! class_exists( 'WS_Form_Form_AI' ) ) {
			$this->error = 'WS Form is not active.';
			return 0;
		}

		$form_id = 0;
		$creator = new \WS_Form_Form_AI();

		try {
			$form_id = (int) $creator->form_create_json( (string) wp_json_encode( $this->definition( $form ) ) );

			$ws_form     = new \WS_Form_Form();
			$ws_form->id = $form_id;
			$form_object = $ws_form->db_read( true, true );

			$this->adjust_fields( $form_object, $form );
			$this->adjust_actions( $form_object, $form );

			$ws_form->db_publish();
		} catch ( \Throwable $exception ) {
			$this->error = $exception->getMessage();

			// A throw inside `form_create_json` leaves the new form's ID on the creator only.
			$created = max( $form_id, (int) ( $creator->id ?? 0 ) );

			if ( $created > 0 ) {
				$this->discard( $created );
			}

			return 0;
		}

		return $form_id;
	}

	/**
	 * Returns the grid of the active WS Form framework: the ID of the
	 * breakpoint that follows the smallest one (the smallest when it is the
	 * only one, null when there are none) and the column count.
	 *
	 * @return array{0: int|null, 1: int}
	 */
	private function grid(): array {
		$config      = ( new \WS_Form_Form_AI() )->breakpoint_framework();
		$breakpoints = (array) $config['breakpoints'];

		ksort( $breakpoints );

		$ids = array_keys( $breakpoints );
		$id  = $ids[1] ?? $ids[0] ?? null;

		return [ null === $id ? null : (int) $id, max( 1, (int) $config['column_count'] ) ];
	}

	/**
	 * Permanently deletes a form that was created but not finished.
	 *
	 * @param int $form_id Form ID.
	 * @return void
	 */
	private function discard( int $form_id ): void {
		try {
			$ws_form     = new \WS_Form_Form();
			$ws_form->id = $form_id;
			$ws_form->db_delete( true );
		} catch ( \Throwable $exception ) {
			$this->error .= ' The unfinished form ' . $form_id . ' could not be deleted: ' . $exception->getMessage();
		}
	}

	/**
	 * Returns WS Form's JSON form definition for a form.
	 *
	 * @param FormData $form Form.
	 * @return array
	 */
	private function definition( FormData $form ): array {
		$fields         = [];
		[ $breakpoint ] = $this->grid();

		foreach ( array_values( $form->fields ) as $index => $field ) {
			$meta = array_filter(
				[
					'required'    => ! empty( $field['required'] ) ? 'on' : '',
					'placeholder' => (string) ( $field['placeholder'] ?? '' ),
					'widths'      => null !== $breakpoint && (float) ( $field['width'] ?? 1 ) < 1 ? [ (string) $breakpoint => (float) $field['width'] ] : null,
					'options'     => array_map(
						fn( string $option ) => [
							'value' => $option,
							'label' => $option,
						],
						$field['options'] ?? []
					),
				],
				fn( $value ) => '' !== $value && null !== $value && [] !== $value
			);

			$fields[] = [
				'id'    => $index + 1,
				'label' => (string) $field['label'],
				'type'  => (string) $field['type'],
				'meta'  => (object) $meta,
			];
		}

		return [
			'label'  => $form->label,
			'groups' => [
				[
					'id'       => 1,
					'label'    => $form->label,
					'sections' => [
						[
							'id'     => 1,
							'label'  => $form->label,
							'fields' => $fields,
						],
					],
				],
			],
		];
	}

	/**
	 * Removes the consent checkbox WS Form adds, names the submit button, and
	 * hides the labels when placeholders name the fields.
	 *
	 * @param object   $form_object WS Form form object.
	 * @param FormData $form   Form.
	 * @return void
	 */
	private function adjust_fields( object $form_object, FormData $form ): void {
		[ $breakpoint, $columns ] = $this->grid();

		$checkboxes = count( array_filter( $form->fields, fn( array $field ) => 'checkbox' === $field['type'] ) );

		foreach ( $form_object->groups as $group ) {
			foreach ( $group->sections as $section ) {
				$seen = 0;

				foreach ( $section->fields as $field ) {
					$ws_field          = new \WS_Form_Field();
					$ws_field->id      = $field->id;
					$ws_field->form_id = $form_object->id;

					// The consent checkbox follows the form's own fields.
					if ( 'checkbox' === $field->type && ++$seen > $checkboxes ) {
						$ws_field->db_delete();
						continue;
					}

					if ( 'submit' === $field->type ) {
						$field->label = $form->submit;

						if ( null !== $breakpoint && $form->submit_width < 1 ) {
							$size_key = 'breakpoint_size_' . $breakpoint;

							$field->meta->$size_key = (string) max( 1, (int) round( $columns * $form->submit_width ) );
						}

						if ( ! $form->submit_full ) {
							$field->meta->class_field_full_button_remove = 'on';
						}
					} elseif ( ! $form->labels ) {
						$field->meta->label_render = '';
					}

					$ws_field->db_update_from_object( $field );
				}
			}
		}
	}

	/**
	 * Sets the success message, and points the email action at the form's
	 * addresses or removes it.
	 *
	 * @param object   $form_object WS Form form object.
	 * @param FormData $form   Form.
	 * @return void
	 */
	private function adjust_actions( object $form_object, FormData $form ): void {
		$action = $form_object->meta->action ?? null;

		if ( ! is_object( $action ) || empty( $action->groups[0]->rows ) ) {
			return;
		}

		$rows = [];

		foreach ( $action->groups[0]->rows as $row ) {
			$data = json_decode( (string) ( $row->data[1] ?? '' ) );

			if ( ! is_object( $data ) ) {
				$rows[] = $row;
				continue;
			}

			switch ( $data->id ?? '' ) {
				case 'email':
					if ( null === $form->email ) {
						continue 2;
					}

					if ( [] !== $form->email ) {
						$data->meta->action_email_to = array_map(
							fn( string $address ) => (object) [
								'action_email_email' => $address,
								'action_email_name'  => '',
							],
							$form->email
						);
					}
					break;
				case 'message':
					if ( '' !== $form->success ) {
						$data->meta->action_message_message = $form->success;
					}
					break;
				default:
					break;
			}

			$row->data[1] = (string) wp_json_encode( $data );
			$rows[]       = $row;
		}

		$action->groups[0]->rows = $rows;

		$meta            = new \WS_Form_Meta();
		$meta->object    = 'form';
		$meta->parent_id = $form_object->id;
		$meta->db_update_from_object( (object) [ 'action' => $action ] );
	}
}
