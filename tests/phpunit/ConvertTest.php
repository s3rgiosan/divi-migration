<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Content;

/**
 * Tests `wp divi-migration convert`: which posts it skips, the backup it
 * keeps, and what a failed save leaves.
 */
class ConvertTest extends TestCase {

	/**
	 * Divi content with a text module.
	 */
	const DIVI = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Lorem ipsum dolor sit amet.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';

	/**
	 * Divi content with a script in a code module.
	 */
	const SCRIPT_PAGE = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_code]<script>console.log("Lorem ipsum");</script>[/et_pb_code][/et_pb_column][/et_pb_row][/et_pb_section]';

	/**
	 * Classic content: paragraphs separated by blank lines.
	 */
	const CLASSIC = "Lorem ipsum dolor sit amet.\n\nConsectetur <em>adipiscing</em> elit.\n\n<a href=\"https://example.com\">Sed do eiusmod</a>";

	/**
	 * Block markup.
	 */
	const BLOCKS = "<!-- wp:paragraph -->\n<p>Lorem ipsum dolor sit amet.</p>\n<!-- /wp:paragraph -->";

	/**
	 * A post that holds block markup is not Divi content: it is skipped and
	 * keeps its content.
	 *
	 * @return void
	 */
	public function test_convert_skips_block_markup(): void {
		$id = $this->insert_post( self::BLOCKS );

		$statuses = $this->convert( [ $id ] );

		$this->assertSame( 'skipped: not Divi content', $statuses[ $id ] );
		$this->assertSame( self::BLOCKS, $this->stored_content( $id ) );
		$this->assertFalse( $this->has_backup( $id ) );
	}

	/**
	 * With `--classic`, a post that holds block markup is not classic content.
	 *
	 * @return void
	 */
	public function test_convert_classic_skips_block_markup(): void {
		$id = $this->insert_post( self::BLOCKS );

		$statuses = $this->convert( [ $id ], [ 'classic' => true ] );

		$this->assertSame( 'skipped: not classic content', $statuses[ $id ] );
		$this->assertSame( self::BLOCKS, $this->stored_content( $id ) );
	}

	/**
	 * With `--classic`, a classic post converts and keeps a backup, and
	 * `reset` restores the original content byte for byte.
	 *
	 * @return void
	 */
	public function test_convert_classic_then_reset_restores_original(): void {
		$id = $this->insert_post( self::CLASSIC );

		update_post_meta( $id, Content::DIVI_BUILDER_META, 'on' );

		$statuses = $this->convert( [ $id ], [ 'classic' => true ] );
		$stored   = $this->stored_content( $id );

		$this->assertSame( 'converted', $statuses[ $id ] );
		$this->assertTrue( has_blocks( $stored ) );
		$this->assertSame( 3, substr_count( $stored, '<!-- wp:paragraph -->' ) );
		$this->assertStringContainsString( '<p>Lorem ipsum dolor sit amet.</p>', $stored );
		$this->assertStringContainsString( '<em>adipiscing</em>', $stored );
		$this->assertStringContainsString( '<a href="https://example.com">Sed do eiusmod</a>', $stored );
		$this->assertSame( self::CLASSIC, get_post_meta( $id, Content::BACKUP_META, true ) );

		// A classic post never used the builder, so its builder setting stays as it was.
		$this->assertSame( 'on', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );

		$this->reset_posts( [ $id ] );

		$this->assertSame( self::CLASSIC, $this->stored_content( $id ) );
		$this->assertFalse( $this->has_backup( $id ) );
		$this->assertSame( 'on', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );
	}

	/**
	 * A second conversion starts from the backup and keeps it: the backup
	 * still holds the Divi content, not the blocks of the first conversion.
	 *
	 * @return void
	 */
	public function test_convert_twice_keeps_original_backup(): void {
		$id = $this->insert_post( self::DIVI );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$first = $this->stored_content( $id );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertSame( self::DIVI, get_post_meta( $id, Content::BACKUP_META, true ) );
		$this->assertSame( $first, $this->stored_content( $id ) );
	}

	/**
	 * A converted post edited afterwards is skipped and keeps the edits;
	 * `--force` converts it again from the backup.
	 *
	 * @return void
	 */
	public function test_convert_skips_post_edited_after_conversion_unless_forced(): void {
		$id = $this->insert_post( self::DIVI );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$converted = $this->stored_content( $id );

		wp_update_post(
			[
				'ID'           => $id,
				'post_content' => wp_slash( $converted . "\n\n<!-- wp:paragraph -->\n<p>Edited lorem ipsum.</p>\n<!-- /wp:paragraph -->" ),
			]
		);

		$edited = $this->stored_content( $id );

		$this->assertNotSame( $converted, $edited );
		$this->assertSame( 'skipped: edited after conversion', $this->convert( [ $id ] )[ $id ] );
		$this->assertSame( $edited, $this->stored_content( $id ) );

		$this->assertSame( 'converted', $this->convert( [ $id ], [ 'force' => true ] )[ $id ] );
		$this->assertSame( $converted, $this->stored_content( $id ) );
		$this->assertSame( self::DIVI, get_post_meta( $id, Content::BACKUP_META, true ) );
	}

	/**
	 * When `wp_update_post()` fails, the post is not marked as converted and
	 * keeps its content and its Divi Builder setting.
	 *
	 * @return void
	 */
	public function test_failed_save_does_not_mark_post_converted(): void {
		$id = $this->insert_post( self::DIVI );

		update_post_meta( $id, Content::DIVI_BUILDER_META, 'on' );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$statuses = $this->convert( [ $id ] );

		$this->assertStringStartsWith( 'failed', $statuses[ $id ] );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::CONVERTED_META ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::MARKUP_HASH_META ) );
		$this->assertSame( 'on', get_post_meta( $id, Content::DIVI_BUILDER_META, true ) );
		$this->assertSame( [ self::MODIFIED, self::MODIFIED ], $this->stored_modified( $id ) );
	}

	/**
	 * Returns the statuses a post can have before it converts.
	 *
	 * @return array[]
	 */
	public static function data_statuses(): array {
		return [
			'publish' => [ 'publish' ],
			'draft'   => [ 'draft' ],
			'private' => [ 'private' ],
			'pending' => [ 'pending' ],
		];
	}

	/**
	 * A converted post keeps its status, its dates and its modified date.
	 *
	 * @dataProvider data_statuses
	 *
	 * @param string $status Post status.
	 * @return void
	 */
	public function test_convert_keeps_status_dates_and_modified_date( string $status ): void {
		$id = $this->insert_post( self::DIVI );

		$this->set_post_fields(
			$id,
			[
				'post_status'   => $status,
				'post_date'     => '2019-05-06 07:08:09',
				'post_date_gmt' => '2019-05-06 07:08:09',
			]
		);

		$before = $this->post_state( $id );

		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertSame( $before, $this->post_state( $id ) );
		$this->assertSame( $status, $before['post_status'] );
		$this->assertSame( self::MODIFIED, $before['post_modified'] );
	}

	/**
	 * A scheduled post whose date has passed stays scheduled: its status and
	 * dates stay, no post changes to `publish`, and no publish event appears.
	 *
	 * @return void
	 */
	public function test_convert_keeps_overdue_scheduled_post_scheduled(): void {
		$id = $this->insert_post( self::DIVI );

		$this->set_post_fields(
			$id,
			[
				'post_status'   => 'future',
				'post_date'     => '2019-05-06 07:08:09',
				'post_date_gmt' => '2019-05-06 07:08:09',
			]
		);

		$before      = $this->post_state( $id );
		$transitions = $this->spy_transitions();

		$this->assertFalse( wp_next_scheduled( 'publish_future_post', [ $id ] ) );
		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$this->assertSame( 'future', $before['post_status'] );
		$this->assertSame( $before, $this->post_state( $id ) );
		$this->assertNotContains( 'publish', $transitions->getArrayCopy() );
		$this->assertFalse( wp_next_scheduled( 'publish_future_post', [ $id ] ) );
		$this->assertTrue( has_blocks( $this->stored_content( $id ) ) );
	}

	/**
	 * A scheduled post keeps the timestamp of its publish event.
	 *
	 * @return void
	 */
	public function test_convert_keeps_publish_event_timestamp(): void {
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

		$this->assertSame( $time, wp_next_scheduled( 'publish_future_post', [ $id ] ) );
		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );
		$this->assertSame( $time, wp_next_scheduled( 'publish_future_post', [ $id ] ) );
		$this->assertSame( $before, $this->post_state( $id ) );
	}

	/**
	 * With `--dry-run`, no backup, meta, content or date changes.
	 *
	 * @return void
	 */
	public function test_convert_dry_run_changes_nothing(): void {
		$id     = $this->insert_post( self::DIVI );
		$before = $this->post_state( $id );

		$statuses = $this->convert( [ $id ], [ 'dry-run' => true ] );

		$this->assertSame( 'converted', $statuses[ $id ] );
		$this->assertSame( [ 'Would convert 1 posts, skipped 0, failed 0.' ], $this->messages( 'success' ) );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertSame( $before, $this->post_state( $id ) );
		$this->assertFalse( $this->has_backup( $id ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::CONVERTED_META ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::MARKUP_HASH_META ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::DIVI_BUILDER_META ) );
	}

	/**
	 * Returns the blocks a rule can name that the converter cannot write: a
	 * block type that is not registered, and a static block type without a save.
	 *
	 * @return array[]
	 */
	public static function data_missing_blocks(): array {
		return [
			'not registered'      => [ 'example/not-registered', false ],
			'static without save' => [ 'example/without-save', true ],
		];
	}

	/**
	 * A post that needs a missing block is skipped and keeps its content.
	 * With `--force`, the post saves, and a block type that is not registered
	 * becomes a hidden placeholder.
	 *
	 * @dataProvider data_missing_blocks
	 *
	 * @param string $block      Block name.
	 * @param bool   $registered Whether to register the block type.
	 * @return void
	 */
	public function test_convert_skips_post_with_missing_block_unless_forced( string $block, bool $registered ): void {
		if ( $registered ) {
			register_block_type( $block, [ 'attributes' => [] ] );
		}

		try {
			$this->add_rules( [ 'et_pb_code' => $this->rule( fn() => [ [ 'name' => $block ] ] ) ] );

			$source = $this->divi_page( '[et_pb_code]<p>Lorem ipsum</p>[/et_pb_code]' );
			$id     = $this->insert_post( $source );
			$before = $this->post_state( $id );

			$this->assertSame( 'skipped', $this->convert( [ $id ] )[ $id ] );
			$this->assertSame( $block, $this->row( $id )['missing'] );
			$this->assertSame( $source, $this->stored_content( $id ) );
			$this->assertSame( $before, $this->post_state( $id ) );
			$this->assertFalse( $this->has_backup( $id ) );

			$this->assertSame( 'converted', $this->convert( [ $id ], [ 'force' => true ] )[ $id ] );
			$this->assertSame( $block, $this->row( $id )['missing'] );
			$this->assertSame( $source, get_post_meta( $id, Content::BACKUP_META, true ) );

			$stored = $this->stored_content( $id );

			if ( $registered ) {
				$this->assertStringContainsString( '<!-- wp:' . $block . ' ', $stored );
			} else {
				$this->assertStringContainsString( '<!-- wp:html -->', $stored );
				$this->assertStringContainsString( 'hidden', $stored );
				$this->assertStringContainsString( 'data-divi-module-block="' . $block . '"', $stored );
			}
		} finally {
			if ( $registered ) {
				unregister_block_type( $block );
			}
		}
	}

	/**
	 * A post whose conversion is empty is skipped. With `--force`, it saves
	 * the empty content and keeps the original in the backup.
	 *
	 * @return void
	 */
	public function test_convert_skips_empty_result_unless_forced(): void {
		$this->add_rules( [ 'et_pb_section' => $this->rule( fn() => [] ) ] );

		$id = $this->insert_post( self::DIVI );

		$this->assertSame( 'skipped: empty result', $this->convert( [ $id ] )[ $id ] );
		$this->assertSame( self::DIVI, $this->stored_content( $id ) );
		$this->assertFalse( $this->has_backup( $id ) );

		$this->assertSame( 'converted', $this->convert( [ $id ], [ 'force' => true ] )[ $id ] );
		$this->assertSame( '', $this->stored_content( $id ) );
		$this->assertSame( self::DIVI, get_post_meta( $id, Content::BACKUP_META, true ) );
	}

	/**
	 * A post ID whose post type or status is not selected is skipped.
	 *
	 * @return void
	 */
	public function test_convert_skips_explicit_id_that_is_not_selected(): void {
		$page = $this->insert_post( self::DIVI, 0, 'page' );
		$post = $this->insert_post( self::DIVI );

		$this->set_post_fields( $post, [ 'post_status' => 'draft' ] );

		$by_type = $this->convert( [ $page ], [ 'post_type' => 'post' ] );

		$this->assertSame( 'skipped: post type or status not selected', $by_type[ $page ] );
		$this->assertSame( self::DIVI, $this->stored_content( $page ) );
		$this->assertFalse( $this->has_backup( $page ) );

		$by_status = $this->convert( [ $post ], [ 'status' => 'publish' ] );

		$this->assertSame( 'skipped: post type or status not selected', $by_status[ $post ] );
		$this->assertSame( self::DIVI, $this->stored_content( $post ) );
	}

	/**
	 * A revision is always skipped, even when its type and status are listed.
	 *
	 * @return void
	 */
	public function test_convert_skips_revision(): void {
		$id       = $this->insert_post( self::DIVI );
		$revision = _wp_put_post_revision( get_post( $id ) );

		$this->assertIsInt( $revision );
		$this->assertSame( 'revision', get_post_type( $revision ) );
		$this->assertTrue( Content::is_divi( get_post_field( 'post_content', $revision, 'raw' ) ) );

		$statuses = $this->convert(
			[ $revision ],
			[
				'post_type' => 'post,revision',
				'status'    => 'publish,inherit',
			]
		);

		$this->assertSame( 'skipped: post type or status not selected', $statuses[ $revision ] );
		$this->assertSame( self::DIVI, $this->stored_content( $revision ) );
		$this->assertFalse( $this->has_backup( $revision ) );
	}

	/**
	 * A rule that throws fails that post only: the post keeps its content,
	 * the next post converts, and the command halts with code 1 at the end.
	 *
	 * @return void
	 */
	public function test_convert_failed_rule_fails_one_post_and_halts_at_the_end(): void {
		$this->add_rules(
			[
				'et_pb_text' => $this->rule(
					function () {
						throw new \RuntimeException( 'Lorem rule error' );
					}
				),
			]
		);

		$failing = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem ipsum</p>[/et_pb_text]' ) );
		$working = $this->insert_post( $this->divi_page( '[et_pb_code]<p>Dolor sit amet</p>[/et_pb_code]' ) );

		$statuses = $this->convert( [ $failing, $working ] );

		$this->assertSame( 'failed: Lorem rule error', $statuses[ $failing ] );
		$this->assertSame( 'converted', $statuses[ $working ] );
		$this->assertFalse( $this->has_backup( $failing ) );
		$this->assertStringNotContainsString( '[et_pb_text]', $this->stored_content( $working ) );
		$this->assertTrue( has_blocks( $this->stored_content( $working ) ) );
		$this->assertContains( 'Converted 1 posts, skipped 0, failed 1.', $this->messages( 'warning' ) );
		$this->assertSame( 1, $this->halted );
	}

	/**
	 * A command without a failure does not halt.
	 *
	 * @return void
	 */
	public function test_convert_without_failure_does_not_halt(): void {
		$id = $this->insert_post( self::DIVI );

		$this->convert( [ $id ] );

		$this->assertNull( $this->halted );
		$this->assertSame( [ 'Converted 1 posts, skipped 0, failed 0.' ], $this->messages( 'success' ) );
	}

	/**
	 * For an author without `unfiltered_html`, the content saves filtered and
	 * the row notes it; the title and the excerpt stay byte for byte.
	 *
	 * @return void
	 */
	public function test_convert_filters_content_for_author_without_unfiltered_html(): void {
		$author  = self::factory()->user->create( [ 'role' => 'author' ] );
		$id      = $this->insert_post( self::SCRIPT_PAGE, $author );
		$title   = 'Lorem <b onclick="x()">ipsum</b> & dolor';
		$excerpt = '<script>console.log("Lorem");</script> Sit amet';

		$this->set_post_fields(
			$id,
			[
				'post_title'   => $title,
				'post_excerpt' => $excerpt,
			]
		);

		$this->assertFalse( user_can( $author, 'unfiltered_html' ) );
		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$this->assertStringNotContainsString( '<script>', $this->stored_content( $id ) );
		$this->assertStringContainsString( 'filtered on save', $this->row( $id )['notes'] );
		$this->assertSame( $title, get_post_field( 'post_title', $id, 'raw' ) );
		$this->assertSame( $excerpt, get_post_field( 'post_excerpt', $id, 'raw' ) );
		$this->assertSame( self::SCRIPT_PAGE, get_post_meta( $id, Content::BACKUP_META, true ) );
	}

	/**
	 * For an author with `unfiltered_html`, the content keeps its script and
	 * the row has no filter note.
	 *
	 * @return void
	 */
	public function test_convert_keeps_script_for_admin_author(): void {
		$author = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$id     = $this->insert_post( self::SCRIPT_PAGE, $author );

		$this->assertTrue( user_can( $author, 'unfiltered_html' ) );
		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		$this->assertStringContainsString( '<script>console.log("Lorem ipsum");</script>', $this->stored_content( $id ) );
		$this->assertStringNotContainsString( 'filtered on save', $this->row( $id )['notes'] );
	}

	/**
	 * A converted post with no stored markup hash counts as not edited, so
	 * `convert` converts it instead of skipping it.
	 *
	 * @return void
	 */
	public function test_is_edited_is_false_when_no_hash_is_stored(): void {
		$id = $this->insert_post( self::DIVI );

		$this->assertFalse( Content::is_edited( get_post( $id ) ) );

		update_post_meta( $id, Content::CONVERTED_META, gmdate( 'c' ) );
		clean_post_cache( $id );

		$this->assertTrue( Content::is_converted( get_post( $id ) ) );
		$this->assertFalse( metadata_exists( 'post', $id, Content::MARKUP_HASH_META ) );
		$this->assertFalse( Content::is_edited( get_post( $id ) ) );
		$this->assertSame( 'converted', $this->convert( [ $id ] )[ $id ] );

		update_post_meta( $id, Content::MARKUP_HASH_META, '' );
		clean_post_cache( $id );

		$this->assertFalse( Content::is_edited( get_post( $id ) ) );
	}

	/**
	 * A product built only from WooCommerce modules gets its old description
	 * back as classic content. `preview` and `markup` show the content that
	 * `convert` saves.
	 *
	 * @return void
	 */
	public function test_product_layout_keeps_description_as_classic_content(): void {
		$description = "Lorem ipsum dolor sit amet.\r\n\r\n• Consectetur\r\n• Adipiscing elit";
		$id          = $this->insert_post( $this->divi_page( '[et_pb_wc_title][/et_pb_wc_title][et_pb_wc_description][/et_pb_wc_description]' ), 0, 'product' );

		update_post_meta( $id, '_et_pb_old_content', $description );

		$this->command( 'preview', [ (string) $id ] );

		$preview = json_decode( $this->messages( 'line' )[0], true );

		$this->assertSame( [ [ 'classic' => $description ] ], $preview['specs'] );

		$this->command( 'markup', [ (string) $id ] );

		$this->assertSame( [ $description ], $this->messages( 'line' ) );

		$this->assertSame( 'converted', $this->convert( [ $id ], [ 'post_type' => 'product' ] )[ $id ] );
		$this->assertSame( $description, $this->stored_content( $id ) );
	}
}
