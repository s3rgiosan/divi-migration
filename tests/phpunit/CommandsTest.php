<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\DiviVersion;
use WP_CLI;

/**
 * Tests the read-only commands with the options that WP-CLI passes after it
 * applies the defaults in each command's documentation.
 */
class CommandsTest extends TestCase {

	/**
	 * Default `--post_type` of `analyze`.
	 */
	const ANALYZE_TYPES = 'post,page,product,et_pb_layout,et_header_layout,et_body_layout,et_footer_layout';

	/**
	 * Default `--post_type` of `list`.
	 */
	const LIST_TYPES = 'post,page,product';

	/**
	 * Default `--status`.
	 */
	const STATUSES = 'publish,draft,private,future,pending';

	/**
	 * Temporary files that the test created.
	 *
	 * @var string[]
	 */
	private array $files = [];

	/**
	 * Removes the temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}

		$this->files = [];

		parent::tear_down();
	}

	/**
	 * `analyze` counts the modules, the posts and the settings of each module
	 * type, most used first, and prints the Divi versions.
	 *
	 * @return void
	 */
	public function test_analyze_counts_modules(): void {
		$this->insert_post( $this->divi_page( '[et_pb_text _builder_version="4.9.11" text_orientation="center"]<p>Lorem</p>[/et_pb_text]' ) );
		$this->insert_post( $this->divi_page( '[et_pb_text]<p>Ipsum</p>[/et_pb_text][et_pb_code]<p>Dolor</p>[/et_pb_code]' ) );
		$this->insert_post( "<!-- wp:paragraph -->\n<p>Sit amet.</p>\n<!-- /wp:paragraph -->" );

		$this->command(
			'analyze',
			[],
			[
				'post_type' => self::ANALYZE_TYPES,
				'format'    => 'table',
			]
		);

		$this->assertSame( [ 'module', 'uses', 'posts', 'settings' ], WP_CLI::$fields );

		$rows = array_column( WP_CLI::$rows, null, 'module' );

		$this->assertSame(
			[
				'module'   => 'et_pb_section',
				'uses'     => 2,
				'posts'    => 2,
				'settings' => 0,
			],
			$rows['et_pb_section']
		);
		$this->assertSame( 2, $rows['et_pb_text']['uses'] );
		$this->assertSame( 2, $rows['et_pb_text']['settings'] );
		$this->assertSame( 1, $rows['et_pb_code']['uses'] );
		$this->assertSame( 1, $rows['et_pb_code']['posts'] );
		$this->assertSame( [], array_diff( array_keys( $rows ), [ 'et_pb_section', 'et_pb_row', 'et_pb_column', 'et_pb_text', 'et_pb_code' ] ) );

		$uses = array_column( WP_CLI::$rows, 'uses' );

		$sorted = $uses;

		rsort( $sorted );

		$this->assertSame( $sorted, $uses );
		$this->assertContains( 'Divi installed: not found', $this->messages( 'log' ) );
		$this->assertContains( 'Divi tested: ' . DiviVersion::TESTED, $this->messages( 'log' ) );
		$this->assertContains( 'Content builder versions (modules): 4.9.11 (1)', $this->messages( 'log' ) );
	}

	/**
	 * With another format than `table`, `analyze` prints no version lines.
	 *
	 * @return void
	 */
	public function test_analyze_with_json_format_prints_no_version_lines(): void {
		$this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' ) );

		$this->command(
			'analyze',
			[],
			[
				'post_type' => self::ANALYZE_TYPES,
				'format'    => 'json',
			]
		);

		$this->assertSame( [], $this->messages( 'log' ) );
		$this->assertEqualsCanonicalizing(
			[
				'et_pb_section' => 1,
				'et_pb_row'     => 1,
				'et_pb_column'  => 1,
				'et_pb_text'    => 1,
			],
			array_column( WP_CLI::$rows, 'uses', 'module' )
		);
		$this->assertSame( [ 1 ], array_values( array_unique( array_column( WP_CLI::$rows, 'posts' ) ) ) );
	}

	/**
	 * `analyze` prints an empty table when no post has Divi content.
	 *
	 * @return void
	 */
	public function test_analyze_without_divi_content_prints_no_rows(): void {
		$this->insert_post( "<!-- wp:paragraph -->\n<p>Lorem.</p>\n<!-- /wp:paragraph -->" );

		$this->command(
			'analyze',
			[],
			[
				'post_type' => self::ANALYZE_TYPES,
				'format'    => 'table',
			]
		);

		$this->assertSame( [], WP_CLI::$rows );
		$this->assertContains( 'Content builder versions (modules): none', $this->messages( 'log' ) );
	}

	/**
	 * `shortcodes` lists the tags in posts and widgets, without Divi module
	 * tags, with the code that registers each one.
	 *
	 * @return void
	 */
	public function test_shortcodes_lists_tags_and_owners(): void {
		add_shortcode( 'lorem_tag', '__return_empty_string' );

		$post_id = $this->insert_post( '[et_pb_section][et_pb_text][lorem_tag a="b"] [ipsum_tag][/et_pb_text][/et_pb_section] [lorem_tag]', 0, 'page' );
		update_option( 'widget_text', [ 2 => [ 'text' => '[ipsum_tag] [/ipsum_tag] [1] <![CDATA[' ] ] );

		$this->command(
			'shortcodes',
			[],
			[
				'post_type' => 'page',
				'status'    => 'publish',
				'format'    => 'table',
			]
		);

		remove_shortcode( 'lorem_tag' );

		$rows = array_column( WP_CLI::$rows, null, 'tag' );

		$this->assertSame( [ 'lorem_tag', 'ipsum_tag' ], array_keys( $rows ) );
		$this->assertSame( 2, $rows['lorem_tag']['uses'] );
		$this->assertSame( (string) $post_id, $rows['lorem_tag']['places'] );
		$this->assertSame( 'core', $rows['lorem_tag']['registered_by'] );
		$this->assertSame( $post_id . ',widget:text-2', $rows['ipsum_tag']['places'] );
		$this->assertSame( '', $rows['ipsum_tag']['registered_by'] );
	}

	/**
	 * `list` shows the posts that hold Divi content, and the converted
	 * posts, with the converted flag.
	 *
	 * @return void
	 */
	public function test_list_shows_divi_and_converted_posts(): void {
		$plain     = $this->insert_post( "<!-- wp:paragraph -->\n<p>Lorem.</p>\n<!-- /wp:paragraph -->" );
		$divi      = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' ) );
		$converted = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Ipsum</p>[/et_pb_text]' ) );

		$this->convert( [ $converted ] );

		$this->command(
			'list',
			[],
			[
				'post_type' => self::LIST_TYPES,
				'status'    => self::STATUSES,
				'format'    => 'table',
			]
		);

		$this->assertSame( [ 'ID', 'post_type', 'status', 'title', 'converted' ], WP_CLI::$fields );
		$this->assertSame(
			[
				[
					'ID'        => $divi,
					'post_type' => 'post',
					'status'    => 'publish',
					'title'     => 'Lorem ipsum',
					'converted' => 'no',
				],
				[
					'ID'        => $converted,
					'post_type' => 'post',
					'status'    => 'publish',
					'title'     => 'Lorem ipsum',
					'converted' => 'yes',
				],
			],
			WP_CLI::$rows
		);
		$this->assertNotContains( $plain, array_column( WP_CLI::$rows, 'ID' ) );
	}

	/**
	 * `list` leaves out post types and statuses that are not selected.
	 *
	 * @return void
	 */
	public function test_list_respects_post_type_and_status(): void {
		$post  = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' ) );
		$page  = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Ipsum</p>[/et_pb_text]' ), 0, 'page' );
		$draft = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Dolor</p>[/et_pb_text]' ) );

		$this->set_post_fields( $draft, [ 'post_status' => 'draft' ] );

		$this->command(
			'list',
			[],
			[
				'post_type' => 'page',
				'status'    => self::STATUSES,
				'format'    => 'table',
			]
		);

		$this->assertSame( [ $page ], array_column( WP_CLI::$rows, 'ID' ) );

		$this->command(
			'list',
			[],
			[
				'post_type' => self::LIST_TYPES,
				'status'    => 'publish',
				'format'    => 'table',
			]
		);

		$this->assertSame( [ $post, $page ], array_column( WP_CLI::$rows, 'ID' ) );
	}

	/**
	 * `classes` counts the classes that the converted markup keeps, for
	 * Divi and classic content, with the posts that use them.
	 *
	 * @return void
	 */
	public function test_classes_counts_classes_of_divi_and_classic_content(): void {
		$divi    = $this->insert_post( $this->divi_page( '[et_pb_text]<p class="lorem-note">Lorem ipsum.</p>[/et_pb_text]' ) );
		$classic = $this->insert_post( "Dolor sit amet.\n\n<p class=\"lorem-note\">Consectetur.</p>\n\n<p class=\"ipsum-box\">Adipiscing.</p>" );

		$this->command(
			'classes',
			[],
			[
				'status' => self::STATUSES,
				'format' => 'table',
			]
		);

		$this->assertSame( [ 'class', 'uses', 'posts' ], WP_CLI::$fields );

		$rows = array_column( WP_CLI::$rows, null, 'class' );

		$this->assertSame(
			[
				'class' => 'lorem-note',
				'uses'  => 2,
				'posts' => $divi . ',' . $classic,
			],
			$rows['lorem-note']
		);
		$this->assertSame( 1, $rows['ipsum-box']['uses'] );
		$this->assertSame( (string) $classic, $rows['ipsum-box']['posts'] );
		$this->assertSame( 'lorem-note', WP_CLI::$rows[0]['class'] );
	}

	/**
	 * `classes` leaves out the classes that the map translates.
	 *
	 * @return void
	 */
	public function test_classes_leaves_out_mapped_classes(): void {
		$this->insert_post( $this->divi_page( '[et_pb_text]<p class="lorem-note ipsum-box">Lorem ipsum.</p>[/et_pb_text]' ) );

		$this->command(
			'classes',
			[],
			[
				'status' => self::STATUSES,
				'format' => 'table',
				'map'    => $this->temp_file( '{ "classes": { "lorem-note": "is-style-note" } }' ),
			]
		);

		$this->assertSame( [ 'ipsum-box' ], array_column( WP_CLI::$rows, 'class' ) );
	}

	/**
	 * `classes` warns about a post ID whose type is not selected.
	 *
	 * @return void
	 */
	public function test_classes_warns_about_a_post_that_is_not_selected(): void {
		$page = $this->insert_post( $this->divi_page( '[et_pb_text]<p class="lorem-note">Lorem.</p>[/et_pb_text]' ), 0, 'page' );

		$this->command(
			'classes',
			[ (string) $page ],
			[
				'post_type' => 'post',
				'status'    => self::STATUSES,
				'format'    => 'table',
			]
		);

		$this->assertSame( [ sprintf( 'Post %d: skipped: post type or status not selected', $page ) ], $this->messages( 'warning' ) );
		$this->assertSame( [], WP_CLI::$rows );
	}

	/**
	 * `markup --content` prints the block markup of a file with Divi content.
	 *
	 * @return void
	 */
	public function test_markup_prints_markup_for_a_content_file(): void {
		$this->command( 'markup', [], [ 'content' => $this->temp_file( '[et_pb_text]<p>Lorem ipsum.</p>[/et_pb_text]' ) ] );

		$lines = $this->messages( 'line' );

		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $lines[0] );
		$this->assertStringContainsString( '<p>Lorem ipsum.</p>', $lines[0] );
	}

	/**
	 * `markup` prints the block markup of a post.
	 *
	 * @return void
	 */
	public function test_markup_prints_markup_for_a_post(): void {
		$id = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem ipsum.</p>[/et_pb_text]' ) );

		$this->command( 'markup', [ (string) $id ], [] );

		$this->assertStringContainsString( '<p>Lorem ipsum.</p>', $this->messages( 'line' )[0] );
		$this->assertSame( $this->divi_page( '[et_pb_text]<p>Lorem ipsum.</p>[/et_pb_text]' ), $this->stored_content( $id ) );
	}

	/**
	 * Without a post ID, `--content`, `--widget-area` or `--specs`, `markup`
	 * stops with an error.
	 *
	 * @return void
	 */
	public function test_markup_without_a_source_is_an_error(): void {
		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Pass a post ID, --content or --widget-area.' );

		$this->command( 'markup', [], [] );
	}

	/**
	 * A content file that cannot be read is an error.
	 *
	 * @return void
	 */
	public function test_markup_with_unreadable_content_file_is_an_error(): void {
		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Cannot read /nonexistent/divi-content.txt.' );

		$this->command( 'markup', [], [ 'content' => '/nonexistent/divi-content.txt' ] );
	}

	/**
	 * A widget area that does not exist is an error.
	 *
	 * @return void
	 */
	public function test_markup_with_missing_widget_area_is_an_error(): void {
		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Widget area missing not found.' );

		$this->command( 'markup', [], [ 'widget-area' => 'missing' ] );
	}

	/**
	 * `markup --widget-area` prints the markup of a widget area, and an area
	 * without widgets is an error.
	 *
	 * @return void
	 */
	public function test_markup_prints_markup_for_a_widget_area(): void {
		add_filter(
			'sidebars_widgets',
			fn() => [
				'sidebar-1' => [ 'text-3' ],
				'empty'     => [],
			]
		);
		update_option(
			'widget_text',
			[
				3 => [
					'title' => 'Lorem title',
					'text'  => '<p>Ipsum text.</p>',
				],
			]
		);

		$this->command( 'markup', [], [ 'widget-area' => 'sidebar-1' ] );

		$markup = $this->messages( 'line' )[0];

		$this->assertStringContainsString( 'Lorem title', $markup );
		$this->assertStringContainsString( '<p>Ipsum text.</p>', $markup );

		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Widget area empty has no widgets.' );

		$this->command( 'markup', [], [ 'widget-area' => 'empty' ] );
	}

	/**
	 * `markup --specs` prints a JSON list with the markup of each spec.
	 *
	 * @return void
	 */
	public function test_markup_with_specs_prints_a_list_of_markup(): void {
		$specs = $this->temp_file(
			(string) wp_json_encode(
				[
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Lorem ipsum.' ],
					],
					[
						'name'       => 'core/paragraph',
						'attributes' => [ 'content' => 'Dolor sit.' ],
					],
				]
			)
		);

		$this->command( 'markup', [], [ 'specs' => $specs ] );

		$markup = json_decode( $this->messages( 'line' )[0], true );

		$this->assertCount( 2, $markup );
		$this->assertStringContainsString( '<p>Lorem ipsum.</p>', $markup[0] );
		$this->assertStringContainsString( '<p>Dolor sit.</p>', $markup[1] );
		$this->assertSame( [], $this->messages( 'warning' ) );
	}

	/**
	 * `markup --specs --portable` makes upload URLs root-relative.
	 *
	 * @return void
	 */
	public function test_markup_with_specs_applies_portable(): void {
		$base_url = wp_get_upload_dir()['baseurl'];
		$specs    = $this->temp_file(
			(string) wp_json_encode(
				[
					[
						'name'       => 'core/image',
						'attributes' => [
							'id'       => 12,
							'url'      => $base_url . '/2020/01/lorem.png',
							'sizeSlug' => 'full',
						],
					],
				]
			)
		);

		$this->command(
			'markup',
			[],
			[
				'specs'    => $specs,
				'portable' => true,
			]
		);

		$markup = json_decode( $this->messages( 'line' )[0], true );

		$this->assertStringNotContainsString( $base_url, $markup[0] );
		$this->assertStringNotContainsString( 'wp-image-12', $markup[0] );
	}

	/**
	 * List options ignore spaces and empty items.
	 *
	 * @return void
	 */
	public function test_list_options_are_trimmed(): void {
		$this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' ) );

		$this->command(
			'list',
			[],
			[
				'post_type' => ' post , ,page ',
				'status'    => 'publish, draft',
				'format'    => 'table',
			]
		);

		$this->assertCount( 1, WP_CLI::$rows );
	}

	/**
	 * An option that holds no value is an error.
	 *
	 * @return void
	 */
	public function test_empty_list_option_is_an_error(): void {
		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'The --post_type option needs at least one comma-separated value.' );

		$this->command(
			'list',
			[],
			[
				'post_type' => ' , ',
				'status'    => self::STATUSES,
				'format'    => 'table',
			]
		);
	}

	/**
	 * A spec for a block that is not registered gets a warning.
	 *
	 * @return void
	 */
	public function test_markup_with_specs_warns_about_a_missing_block(): void {
		$specs = $this->temp_file( '[ { "name": "example/not-registered" } ]' );

		$this->command( 'markup', [], [ 'specs' => $specs ] );

		$this->assertSame( [ 'No markup for example/not-registered: the block is not registered or has no save.' ], $this->messages( 'warning' ) );
	}

	/**
	 * A spec that is not an object is an error, and so is a file that is not
	 * a list.
	 *
	 * @return void
	 */
	public function test_markup_with_invalid_specs_is_an_error(): void {
		$list = $this->temp_file( '[ { "name": "core/paragraph" }, "lorem" ]' );

		try {
			$this->command( 'markup', [], [ 'specs' => $list ] );

			$this->fail( 'The command accepted a spec that is not an object.' );
		} catch ( \WP_CLI_Error $error ) {
			$this->assertSame( 'The spec at index 1 is not a JSON object.', $error->getMessage() );
		}

		$object = $this->temp_file( '{ "name": "core/paragraph" }' );

		try {
			$this->command( 'markup', [], [ 'specs' => $object ] );

			$this->fail( 'The command accepted a file that is not a list.' );
		} catch ( \WP_CLI_Error $error ) {
			$this->assertSame( 'The specs file must hold a JSON list of block specs.', $error->getMessage() );
		}

		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'The specs file must hold a JSON list of block specs.' );

		$this->command( 'markup', [], [ 'specs' => $this->temp_file( 'not json' ) ] );
	}

	/**
	 * `preview` without `--tree` prints the specs and the report, and saves
	 * nothing.
	 *
	 * @return void
	 */
	public function test_preview_prints_specs_and_report(): void {
		$source = $this->divi_page( '[et_pb_text]<p>Lorem ipsum.</p>[/et_pb_text]' );
		$id     = $this->insert_post( $source );

		$this->command( 'preview', [ (string) $id ], [] );

		$output = json_decode( $this->messages( 'line' )[0], true );

		$this->assertSame( [ 'specs', 'report' ], array_keys( $output ) );
		$this->assertCount( 1, $output['specs'] );
		$this->assertSame( 'core/group', $output['specs'][0]['name'] );
		$this->assertSame( 1, $output['report']['et_pb_text']['converted'] );
		$this->assertSame( $source, $this->stored_content( $id ) );
		$this->assertFalse( $this->has_backup( $id ) );
	}

	/**
	 * `preview --tree` prints the parsed Divi tree.
	 *
	 * @return void
	 */
	public function test_preview_with_tree_prints_the_tree(): void {
		$id = $this->insert_post( $this->divi_page( '[et_pb_text]<p>Lorem ipsum.</p>[/et_pb_text]' ) );

		$this->command( 'preview', [ (string) $id ], [ 'tree' => true ] );

		$tree = json_decode( $this->messages( 'line' )[0], true );

		$this->assertIsArray( $tree );
		$this->assertStringContainsString( 'et_pb_section', (string) wp_json_encode( $tree ) );
	}

	/**
	 * `preview` for a post that does not exist is an error.
	 *
	 * @return void
	 */
	public function test_preview_for_a_missing_post_is_an_error(): void {
		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Post not found.' );

		$this->command( 'preview', [ '999999' ], [] );
	}

	/**
	 * Writes a temporary file that the test removes at its end.
	 *
	 * @param string $content File content.
	 * @return string File path.
	 */
	private function temp_file( string $content ): string {
		$path = wp_tempnam( 'divi-migration-command' );

		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary test file.

		$this->files[] = $path;

		return $path;
	}
}
