<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Content;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Targets\FormData;
use S3S\WP\DiviMigration\Targets\FormTarget;
use WP_Block_Type_Registry;

/**
 * Tests how contact forms and Bloom opt-ins convert without a form plugin,
 * and with a form target that creates forms once.
 */
class FormsTest extends TestCase {

	/**
	 * Divi contact form module.
	 */
	const CONTACT_FORM = '[et_pb_contact_form title="Lorem contact" email="admin@example.com"][et_pb_contact_field field_title="Name" field_type="input"][/et_pb_contact_field][/et_pb_contact_form]';

	/**
	 * Text module that holds a Bloom opt-in shortcode on its own.
	 */
	const BLOOM_OPTIN = '[et_pb_text]<p>[et_bloom_inline optin_id="optin_1"]</p>[/et_pb_text]';

	/**
	 * Fake form target.
	 *
	 * @var object
	 */
	private object $target;

	/**
	 * Saves the Bloom opt-in that the tests use.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		update_option(
			'et_bloom_options',
			[
				'optin_1' => [
					'optin_name'      => 'Lorem optin',
					'optin_title'     => '&lt;h2&gt;Lorem ipsum&lt;/h2&gt;',
					'email_text'      => 'Email',
					'button_text'     => 'Join',
					'success_message' => 'Thank you',
				],
			]
		);
	}

	/**
	 * Without WS Form, a contact form falls back and the row notes why.
	 *
	 * @return void
	 */
	public function test_contact_form_without_form_plugin_keeps_the_module(): void {
		$this->skip_when_ws_form_is_active();

		$id = $this->insert_post( $this->divi_page( self::CONTACT_FORM ) );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$row = $this->row( $id );

		$this->assertSame( 'et_pb_contact_field', $row['fallback'] );
		$this->assertStringContainsString( 'et_pb_contact_form kept: no form plugin', $row['notes'] );
		$this->assertStringNotContainsString( 'wsf-block', $this->stored_content( $id ) );
	}

	/**
	 * Without WS Form, a Bloom shortcode stays in the content and the row
	 * has a note.
	 *
	 * @return void
	 */
	public function test_bloom_without_form_plugin_keeps_the_shortcode(): void {
		$this->skip_when_ws_form_is_active();

		$id = $this->insert_post( $this->divi_page( self::BLOOM_OPTIN ) );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertStringContainsString( 'Bloom opt-in optin_1 kept: no form plugin', $this->row( $id )['notes'] );
		$this->assertStringContainsString( '[et_bloom_inline optin_id="optin_1"]', $this->stored_content( $id ) );
	}

	/**
	 * A Bloom shortcode for an opt-in that does not exist stays, with a note.
	 *
	 * @return void
	 */
	public function test_bloom_with_unknown_optin_keeps_the_shortcode(): void {
		$id = $this->insert_post( $this->divi_page( '[et_pb_text]<p>[et_bloom_inline optin_id="optin_9"]</p>[/et_pb_text]' ) );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertStringContainsString( 'Bloom opt-in optin_9 not found', $this->row( $id )['notes'] );
		$this->assertStringContainsString( '[et_bloom_inline optin_id="optin_9"]', $this->stored_content( $id ) );
	}

	/**
	 * With a form target, the contact form and the Bloom opt-in become its
	 * blocks, and each form is created once.
	 *
	 * @return void
	 */
	public function test_form_target_builds_forms(): void {
		$this->use_form_target();

		$id = $this->insert_post( $this->divi_page( self::CONTACT_FORM . self::BLOOM_OPTIN ) );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$stored = $this->stored_content( $id );

		$this->assertStringContainsString( 'form: 1', $stored );
		$this->assertStringContainsString( 'form: 2', $stored );
		$this->assertStringNotContainsString( '[et_bloom_inline', $stored );
		$this->assertStringNotContainsString( 'kept: no form plugin', $this->row( $id )['notes'] );
		$this->assertCount( 2, $this->target->created );
		$this->assertStringStartsWith( 'divi-contact:', $this->target->created[0] );
		$this->assertSame( 'bloom:optin_1', $this->target->created[1] );
	}

	/**
	 * A second run reuses the stored forms.
	 *
	 * @return void
	 */
	public function test_second_run_reuses_the_stored_form_ids(): void {
		$this->use_form_target();

		$id = $this->insert_post( $this->divi_page( self::CONTACT_FORM . self::BLOOM_OPTIN ) );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$first = $this->stored_content( $id );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertSame( $first, $this->stored_content( $id ) );
		$this->assertCount( 2, $this->target->created );
		$this->assertSame( $this->divi_page( self::CONTACT_FORM . self::BLOOM_OPTIN ), get_post_meta( $id, Content::BACKUP_META, true ) );
	}

	/**
	 * With `--dry-run`, no form is created and the post stays as it is.
	 *
	 * @return void
	 */
	public function test_dry_run_creates_no_forms(): void {
		$this->use_form_target();

		$source = $this->divi_page( self::CONTACT_FORM . self::BLOOM_OPTIN );
		$id     = $this->insert_post( $source );

		$this->assertSame( 'converted', $this->convert( [ $id ], [ 'dry-run' => true ] )[ $id ] );
		$this->assertSame( [], $this->target->created );
		$this->assertCount( 2, $this->target->builds );
		$this->assertStringStartsWith( 'divi-contact:', $this->target->builds[0][0] );
		$this->assertSame( 'bloom:optin_1', $this->target->builds[1][0] );
		$this->assertSame( [ false ], array_values( array_unique( array_column( $this->target->builds, 1 ) ) ) );
		$this->assertFalse( get_option( 'lorem_forms' ) );
		$this->assertSame( $source, $this->stored_content( $id ) );
	}

	/**
	 * The preview creates no forms.
	 *
	 * @return void
	 */
	public function test_preview_creates_no_forms(): void {
		$this->use_form_target();

		$id = $this->insert_post( $this->divi_page( self::CONTACT_FORM . self::BLOOM_OPTIN ) );

		$this->command( 'preview', [ (string) $id ], [] );

		$output = json_decode( $this->messages( 'line' )[0], true );

		$this->assertIsArray( $output );
		$this->assertArrayHasKey( 'specs', $output );
		$this->assertSame( [], $this->target->created );
		$this->assertFalse( get_option( 'lorem_forms' ) );
	}

	/**
	 * Skips the test when a WS Form block is registered.
	 *
	 * @return void
	 */
	private function skip_when_ws_form_is_active(): void {
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'wsf-block/form-add' ) ) {
			$this->markTestSkipped( 'WS Form is active.' );
		}
	}

	/**
	 * Registers a form target that stores its form IDs in an option, so each
	 * form is created once.
	 *
	 * @return void
	 */
	private function use_form_target(): void {
		$this->target = new class() implements FormTarget {

			/**
			 * Keys of the forms that the target created.
			 *
			 * @var string[]
			 */
			public array $created = [];

			/**
			 * Calls to `build()`: the form key and whether to create.
			 *
			 * @var array[]
			 */
			public array $builds = [];

			/**
			 * {@inheritDoc}
			 *
			 * @return string[]
			 */
			public function blocks(): array {
				return [ 'core/paragraph' ];
			}

			/**
			 * {@inheritDoc}
			 *
			 * @param FormData $form   Form.
			 * @param bool     $create Whether to create a missing form.
			 * @return array|null
			 */
			public function build( FormData $form, bool $create = true ): ?array {
				$this->builds[] = [ $form->key, $create ];

				$forms = get_option( 'lorem_forms', [] );

				if ( ! isset( $forms[ $form->key ] ) ) {
					if ( ! $create ) {
						return Spec::block( 'core/paragraph', [ 'content' => 'form: none' ] );
					}

					$forms[ $form->key ] = count( $forms ) + 1;
					$this->created[]     = $form->key;

					update_option( 'lorem_forms', $forms );
				}

				return Spec::block( 'core/paragraph', [ 'content' => 'form: ' . $forms[ $form->key ] ] );
			}

			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function error(): string {
				return '';
			}
		};

		$target = $this->target;

		add_filter(
			's3s_divi_migration_targets',
			function ( array $targets ) use ( $target ) {
				$targets['form'] = [ 'lorem-form' => $target ];

				return $targets;
			}
		);
	}
}
