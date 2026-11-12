<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Content;
use WP_Error;

/**
 * Tests the save that `convert` and `reset` run: page templates, ping
 * hooks, form creation, missing posts, and the status and date restore.
 */
class ConvertSaveTest extends TestCase {

	/**
	 * Divi content with a text module.
	 */
	const DIVI = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Lorem ipsum dolor sit amet.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';

	/**
	 * Page template that the active theme does not have.
	 */
	const MISSING_TEMPLATE = 'missing-template.php';

	/**
	 * A page whose template is not in the active theme converts and resets,
	 * and keeps its template meta.
	 *
	 * @return void
	 */
	public function test_convert_and_reset_keep_a_page_template_that_the_theme_lacks(): void {
		$id = $this->insert_post( self::DIVI, 0, 'page' );

		update_post_meta( $id, '_wp_page_template', self::MISSING_TEMPLATE );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertNotSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertSame( self::MISSING_TEMPLATE, get_post_meta( $id, '_wp_page_template', true ) );

		$this->assertSame( 'Restored 1 of 1 posts.', $this->reset_posts( [ $id ] ) );
		$this->assertNull( $this->halted );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertSame( self::MISSING_TEMPLATE, get_post_meta( $id, '_wp_page_template', true ) );
	}

	/**
	 * The template filter is removed after the save.
	 *
	 * @return void
	 */
	public function test_save_removes_the_template_filter(): void {
		$id = $this->insert_post( self::DIVI, 0, 'page' );

		update_post_meta( $id, '_wp_page_template', self::MISSING_TEMPLATE );

		$this->convert( [ $id ] );

		$templates = wp_get_theme()->get_page_templates( get_post( $id ) );

		$this->assertArrayNotHasKey( self::MISSING_TEMPLATE, $templates );
	}

	/**
	 * A converted published post gets no ping or enclosure meta, and the
	 * core hook is back after the save.
	 *
	 * @return void
	 */
	public function test_convert_does_not_schedule_pings(): void {
		update_option( 'default_pingback_flag', 1 );

		$id = $this->insert_post( self::DIVI );

		delete_post_meta( $id, '_pingme' );
		delete_post_meta( $id, '_encloseme' );
		delete_post_meta( $id, '_trackbackme' );

		$priority = has_action( 'publish_post', '_publish_post_hook' );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertFalse( metadata_exists( 'post', $id, '_pingme' ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_encloseme' ) );
		$this->assertSame( $priority, has_action( 'publish_post', '_publish_post_hook' ) );
	}

	/**
	 * A post that `convert` skips for a missing block creates no forms: the
	 * conversion runs only in report mode. A post that saves converts again
	 * with form creation on.
	 *
	 * @return void
	 */
	public function test_convert_creates_forms_only_for_saved_posts(): void {
		$modes = [];

		$this->add_rules(
			[
				'et_pb_code' => $this->rule(
					function ( $node, $converter ) use ( &$modes ) {
						$modes[] = $converter->dry_run;

						return [ [ 'name' => 'example/not-registered' ] ];
					}
				),
			]
		);

		$id = $this->insert_post( $this->divi_page( '[et_pb_code]<p>Lorem ipsum</p>[/et_pb_code]' ) );

		$this->assertSame( 'skipped', $this->convert( [ $id ] )[ $id ] );
		$this->assertNotEmpty( $modes );
		$this->assertSame( [ true ], array_values( array_unique( $modes ) ) );

		$modes = [];

		$this->assertSame( 'converted', $this->convert( [ $id ], [ 'force' => true ] )[ $id ] );
		$this->assertContains( false, $modes );
	}

	/**
	 * A post ID that does not exist gets a failed row, and the command halts
	 * with code 1.
	 *
	 * @return void
	 */
	public function test_convert_reports_a_post_that_is_not_found(): void {
		$id = $this->insert_post( self::DIVI );

		$statuses = $this->convert( [ 999999, $id ] );

		$this->assertSame( 'failed: not found', $statuses[999999] );
		$this->assertSame( 'converted', $statuses[ $id ] );
		$this->assertSame( 1, $this->halted );
	}

	/**
	 * A failed update of the status and dates returns an error with its own
	 * code.
	 *
	 * @return void
	 */
	public function test_keep_dates_and_status_returns_an_error_when_the_update_fails(): void {
		global $wpdb;

		$post = get_post( $this->insert_post( self::DIVI ) );

		$this->fail_keep_dates_update();

		$suppressed = $wpdb->suppress_errors( true );
		$result     = Content::keep_dates_and_status( $post );

		$wpdb->suppress_errors( $suppressed );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 's3s_divi_migration_keep_dates', $result->get_error_code() );
	}

	/**
	 * `convert` saves the post when the update of its dates fails, marks it
	 * as converted and adds a note to the row.
	 *
	 * @return void
	 */
	public function test_convert_notes_a_failed_dates_update(): void {
		global $wpdb;

		$id = $this->insert_post( self::DIVI );

		$this->fail_keep_dates_update();

		$suppressed = $wpdb->suppress_errors( true );
		$statuses   = $this->convert( [ $id ] );

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 'converted', $statuses[ $id ] );
		$this->assertStringContainsString( 'The status and dates of the post could not be put back.', $this->row( $id )['notes'] );
		$this->assertTrue( Content::is_converted( get_post( $id ) ) );
	}

	/**
	 * `reset` fails a post when the update of its dates fails, and keeps the
	 * backup.
	 *
	 * @return void
	 */
	public function test_reset_fails_when_the_dates_update_fails(): void {
		global $wpdb;

		$id = $this->insert_post( self::DIVI );

		$this->convert( [ $id ] );
		$this->fail_keep_dates_update();

		$suppressed = $wpdb->suppress_errors( true );
		$summary    = $this->reset_posts( [ $id ] );

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 'Restored 0 of 1 posts.', $summary );
		$this->assertSame( 1, $this->halted );
		$this->assertTrue( $this->has_backup( $id ) );
	}

	/**
	 * A post without a backup returns the prefixed error code.
	 *
	 * @return void
	 */
	public function test_restore_without_a_backup_returns_the_prefixed_error_code(): void {
		$post   = get_post( $this->insert_post( self::DIVI ) );
		$result = Content::restore( $post );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 's3s_divi_migration_no_backup', $result->get_error_code() );
	}

	/**
	 * Makes the query that puts back the status and dates fail. The save
	 * query, which writes the content too, runs as usual.
	 *
	 * @return void
	 */
	private function fail_keep_dates_update(): void {
		add_filter(
			'query',
			function ( string $query ): string {
				$is_keep_dates = str_starts_with( $query, 'UPDATE ' )
					&& str_contains( $query, 'post_modified_gmt' )
					&& ! str_contains( $query, 'post_content' );

				return $is_keep_dates ? 'UPDATE s3s_divi_migration_missing_table SET a = 1' : $query;
			}
		);
	}
}
