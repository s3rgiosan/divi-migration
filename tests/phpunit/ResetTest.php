<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Content;

/**
 * Tests the round trip through `convert` and `reset` for content that the
 * kses filters change: scripts and iframes.
 */
class ResetTest extends TestCase {

	/**
	 * Divi content with a code module that holds a script and an iframe.
	 */
	const DIVI = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Lorem ipsum dolor sit amet.</p>[/et_pb_text][et_pb_code]<script>console.log("Lorem ipsum");</script><iframe src="https://example.com"></iframe>[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]';

	/**
	 * Classic content with an iframe.
	 */
	const CLASSIC = "Lorem ipsum dolor sit amet.\n\n<iframe src=\"https://example.com/embed\" width=\"560\" height=\"315\"></iframe>\n\nConsectetur adipiscing elit.";

	/**
	 * Returns the users that run `reset`: no user, and a user without the
	 * `unfiltered_html` capability.
	 *
	 * @return array[]
	 */
	public static function data_users(): array {
		return [
			'no user'     => [ '' ],
			'author role' => [ 'author' ],
		];
	}

	/**
	 * Divi content converts and restores byte for byte, with the backup
	 * deleted and the modified date kept.
	 *
	 * @dataProvider data_users
	 *
	 * @param string $role Role of the user that runs `reset`, or empty for no user.
	 * @return void
	 */
	public function test_reset_restores_divi_content( string $role ): void {
		$id = $this->insert_post( self::DIVI );

		update_post_meta( $id, Content::DIVI_BUILDER_META, 'on' );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertStringContainsString( '<script>', $this->stored_content( $id ) );
		$this->assertSame( 'off', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );

		$this->set_filtered_user( $role );

		$this->assertSame( 'Restored 1 of 1 posts.', $this->reset_posts( [ $id ] ) );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assert_reset( $id );
		$this->assertSame( 'on', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );
	}

	/**
	 * `reset` restores the original builder meta value.
	 *
	 * @return void
	 */
	public function test_reset_restores_the_original_builder_meta(): void {
		$id = $this->insert_post( self::DIVI );

		update_post_meta( $id, Content::DIVI_BUILDER_META, 'custom' );

		$this->convert( [ $id ] );
		$this->assertSame( 'off', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );

		$this->reset_posts( [ $id ] );

		$this->assertSame( 'custom', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::BUILDER_BACKUP_META ) );
	}

	/**
	 * `reset` deletes the builder meta when the post had none.
	 *
	 * @return void
	 */
	public function test_reset_deletes_builder_meta_the_post_did_not_have(): void {
		$id = $this->insert_post( self::DIVI );

		delete_post_meta( $id, Content::DIVI_BUILDER_META );

		$this->convert( [ $id ] );
		$this->assertSame( 'off', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );

		$this->reset_posts( [ $id ] );

		$this->assertFalse( metadata_exists( 'post', $id, Content::DIVI_BUILDER_META ) );
	}

	/**
	 * A post converted before the builder meta was backed up gets `on`.
	 *
	 * @return void
	 */
	public function test_reset_without_a_builder_backup_sets_the_builder_on(): void {
		$id = $this->insert_post( self::DIVI );

		$this->convert( [ $id ] );
		delete_post_meta( $id, Content::BUILDER_BACKUP_META );

		$this->reset_posts( [ $id ] );

		$this->assertSame( 'on', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );
	}

	/**
	 * A post converted before the builder backup existed keeps the `on`
	 * fallback after a forced conversion.
	 *
	 * @return void
	 */
	public function test_forced_reconversion_of_a_legacy_post_keeps_the_on_fallback(): void {
		$id = $this->insert_post( self::DIVI );

		$this->convert( [ $id ] );
		delete_post_meta( $id, Content::BUILDER_BACKUP_META );
		update_post_meta( $id, Content::DIVI_BUILDER_META, 'off' );

		$this->convert( [ $id ], [ 'force' => true ] );

		$this->assertFalse( metadata_exists( 'post', $id, Content::BUILDER_BACKUP_META ) );

		$this->reset_posts( [ $id ] );

		$this->assertSame( 'on', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );
	}

	/**
	 * Classic content converts and restores byte for byte, with the backup
	 * deleted and the modified date kept.
	 *
	 * @dataProvider data_users
	 *
	 * @param string $role Role of the user that runs `reset`, or empty for no user.
	 * @return void
	 */
	public function test_reset_restores_classic_content( string $role ): void {
		$id = $this->insert_post( self::CLASSIC );

		$this->assertSame( 'converted', $this->convert( [ $id ], [ 'classic' => true ] )[ $id ] );
		$this->assertStringContainsString( '<iframe', $this->stored_content( $id ) );

		$this->set_filtered_user( $role );

		$this->assertSame( 'Restored 1 of 1 posts.', $this->reset_posts( [ $id ] ) );
		$this->assertSame( self::CLASSIC, $this->stored_content( $id ) );
		$this->assert_reset( $id );
	}

	/**
	 * `reset --all` restores every post with a backup.
	 *
	 * @return void
	 */
	public function test_reset_all_restores_divi_and_classic_posts(): void {
		$divi    = $this->insert_post( self::DIVI );
		$classic = $this->insert_post( self::CLASSIC );

		$this->convert( [ $divi ] );
		$this->convert( [ $classic ], [ 'classic' => true ] );

		$this->assertSame( 'Restored 2 of 2 posts.', $this->reset_posts( [], [ 'all' => true ] ) );
		$this->assertSame( self::DIVI, $this->stored_content( $divi ) );
		$this->assertSame( self::CLASSIC, $this->stored_content( $classic ) );
	}

	/**
	 * A post edited after its conversion is skipped and keeps the edits;
	 * `--force` restores the original content.
	 *
	 * @return void
	 */
	public function test_reset_skips_edited_post_unless_forced(): void {
		$id = $this->insert_post( self::DIVI );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $this->stored_content( $id ) . "\n\n<!-- wp:paragraph -->\n<p>Edited lorem ipsum.</p>\n<!-- /wp:paragraph -->" ),
			]
		);

		$edited = $this->stored_content( $id );

		$this->assertSame( 'Restored 0 of 1 posts.', $this->reset_posts( [ $id ] ) );
		$this->assertContains( sprintf( 'Post %d: skipped: edited after conversion', $id ), $this->messages( 'warning' ) );
		$this->assertNull( $this->halted );
		$this->assertSame( $edited, $this->stored_content( $id ) );
		$this->assertTrue( $this->has_backup( $id ) );

		$this->assertSame( 'Restored 1 of 1 posts.', $this->reset_posts( [ $id ], [ 'force' => true ] ) );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertFalse( $this->has_backup( $id ) );
	}

	/**
	 * Without post IDs and without `--all`, the command stops with an error.
	 *
	 * @return void
	 */
	public function test_reset_without_ids_or_all_is_an_error(): void {
		$id = $this->insert_post( self::DIVI );

		$this->convert( [ $id ] );

		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Pass the IDs of the posts to restore, or --all to restore every post with a backup: wp divi-migration reset <id>... | --all' );

		$this->reset_posts( [] );
	}

	/**
	 * A restore that fails keeps the backup and the converted content, and
	 * the command halts with code 1 after the summary.
	 *
	 * @return void
	 */
	public function test_reset_halts_when_a_restore_fails(): void {
		$id = $this->insert_post( self::DIVI );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$converted = $this->stored_content( $id );

		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$this->assertSame( 'Restored 0 of 1 posts.', $this->reset_posts( [ $id ] ) );

		$warnings = $this->messages( 'warning' );

		$this->assertStringStartsWith( sprintf( 'Post %d: failed: ', $id ), $warnings[0] );
		$this->assertSame( 1, $this->halted );
		$this->assertSame( $converted, $this->stored_content( $id ) );
		$this->assertTrue( $this->has_backup( $id ) );
	}

	/**
	 * A post that is not found fails, and the other posts still restore.
	 *
	 * @return void
	 */
	public function test_reset_reports_a_missing_post_and_restores_the_others(): void {
		$id = $this->insert_post( self::DIVI );

		$this->convert( [ $id ] );

		$this->assertSame( 'Restored 1 of 2 posts.', $this->reset_posts( [ 999999, $id ] ) );
		$this->assertContains( 'Post 999999: failed: not found.', $this->messages( 'warning' ) );
		$this->assertSame( 1, $this->halted );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
	}

	/**
	 * A reset post keeps its status, its dates and its modified date, also for
	 * a scheduled post whose date has passed: no post changes to `publish`
	 * and no publish event appears.
	 *
	 * @return void
	 */
	public function test_reset_keeps_overdue_scheduled_post_scheduled(): void {
		$id = $this->insert_post( self::DIVI );

		$this->set_post_fields(
			$id,
			[
				'post_status'   => 'future',
				'post_date'     => '2019-05-06 07:08:09',
				'post_date_gmt' => '2019-05-06 07:08:09',
			]
		);

		$before = $this->post_state( $id );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$transitions = $this->spy_transitions();

		$this->assertSame( 'Restored 1 of 1 posts.', $this->reset_posts( [ $id ] ) );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertSame( 'future', $before['post_status'] );
		$this->assertSame( $before, $this->post_state( $id ) );
		$this->assertNotContains( 'publish', $transitions->getArrayCopy() );
		$this->assertFalse( wp_next_scheduled( 'publish_future_post', [ $id ] ) );
	}

	/**
	 * A reset post keeps the timestamp of its publish event.
	 *
	 * @return void
	 */
	public function test_reset_keeps_publish_event_timestamp(): void {
		$id   = $this->insert_post( self::DIVI );
		$time = time() + DAY_IN_SECONDS;

		$this->set_post_fields(
			$id,
			[
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', $time ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $time ),
			]
		);

		wp_schedule_single_event( $time, 'publish_future_post', [ $id ] );

		$before = $this->post_state( $id );

		$this->convert( [ $id ] );

		$this->assertSame( 'Restored 1 of 1 posts.', $this->reset_posts( [ $id ] ) );
		$this->assertSame( $time, wp_next_scheduled( 'publish_future_post', [ $id ] ) );
		$this->assertSame( $before, $this->post_state( $id ) );
	}

	/**
	 * Sets the current user, and checks that the kses filters are on, as core
	 * sets them for a user without `unfiltered_html`.
	 *
	 * @param string $role Role, or empty for no user.
	 * @return void
	 */
	private function set_filtered_user( string $role ): void {
		$user_id = '' === $role ? 0 : self::factory()->user->create( [ 'role' => $role ] );

		wp_set_current_user( $user_id );
		kses_init();

		$this->assertFalse( current_user_can( 'unfiltered_html' ) );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	/**
	 * Checks that a reset post has no conversion meta and kept its modified
	 * date.
	 *
	 * @param int $id Post ID.
	 * @return void
	 */
	private function assert_reset( int $id ): void {
		$this->assertFalse( $this->has_backup( $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::CONVERTED_META ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::MARKUP_HASH_META ) );
		$this->assertSame( [ self::MODIFIED, self::MODIFIED ], $this->stored_modified( $id ) );
	}
}
