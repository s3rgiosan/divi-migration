<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\DiviVersion;

/**
 * Tests the tested Divi version policy and the builder version census.
 */
class DiviVersionTest extends TestCase {

	/**
	 * A version is compared with the tested one.
	 *
	 * @return void
	 */
	public function test_status_compares_with_tested_version(): void {
		$this->assertSame( 'equal', DiviVersion::status( DiviVersion::TESTED ) );
		$this->assertSame( 'older', DiviVersion::status( '4.9.2' ) );
		$this->assertSame( 'older', DiviVersion::status( '4.0' ) );
		$this->assertSame( 'newer', DiviVersion::status( '4.9.12' ) );
		$this->assertSame( 'newer', DiviVersion::status( '4.27.4' ) );
		$this->assertSame( 'unsupported', DiviVersion::status( '5.0.0' ) );
		$this->assertSame( 'unsupported', DiviVersion::status( '5.0.0-public-alpha.20' ) );
		$this->assertSame( 'missing', DiviVersion::status( '' ) );
	}

	/**
	 * The census reads versions in double quotes, single quotes, curly quotes
	 * and encoded curly quotes.
	 *
	 * @return void
	 */
	public function test_census_reads_curly_and_encoded_quotes(): void {
		$content = '[et_pb_text _builder_version="4.9.11"][/et_pb_text]'
			. "[et_pb_text _builder_version='4.9.11'][/et_pb_text]"
			. "[et_pb_text _builder_version=\u{201C}4.9.11\u{201D}][/et_pb_text]"
			. '[et_pb_text _builder_version=&#8221;4.3.0&#8243;][/et_pb_text]'
			. "[et_pb_text _builder_version=\u{2033}4.3.0\u{2033}][/et_pb_text]";

		$this->assertSame(
			[
				'4.9.11' => 3,
				'4.3.0'  => 2,
			],
			DiviVersion::census( $content )
		);
	}

	/**
	 * Versions that match the tested one give no warning.
	 *
	 * @return void
	 */
	public function test_no_warning_when_versions_equal(): void {
		$this->assertSame( '', DiviVersion::warning( '4.9.11', [] ) );
		$this->assertSame( '', DiviVersion::warning( '4.9.11', [ '4.9.11' => 3 ] ) );
	}

	/**
	 * A different 4.x version, installed or in the content, gives the
	 * version warning.
	 *
	 * @return void
	 */
	public function test_warning_when_same_major_differs(): void {
		$expected = 'Divi version differs from the tested version 4.9.11 (installed: %s; newest content version: %s). Check layout defaults and module output against the old site.';

		$this->assertSame( sprintf( $expected, '4.9.2', 'none' ), DiviVersion::warning( '4.9.2', [] ) );
		$this->assertSame( sprintf( $expected, '4.27.4', '4.27.4' ), DiviVersion::warning( '4.27.4', [ '4.27.4' => 2 ] ) );
		$this->assertSame(
			sprintf( $expected, '4.9.11', '4.10.0' ),
			DiviVersion::warning(
				'4.9.11',
				[
					'4.9.11' => 5,
					'4.10.0' => 1,
				]
			)
		);
		$this->assertSame( sprintf( $expected, '4.9.11', '4.3.0' ), DiviVersion::warning( '4.9.11', [ '4.3.0' => 1 ] ) );
	}

	/**
	 * Divi 5, installed or in the content, gives the unsupported warning.
	 *
	 * @return void
	 */
	public function test_warning_when_divi_5(): void {
		$expected = 'Divi 5 is not supported. The converter reads Divi 4 shortcode content only, and is tested with Divi 4.9.11 (installed: %s; newest content version: %s).';

		$this->assertSame( sprintf( $expected, '5.0.0', 'none' ), DiviVersion::warning( '5.0.0', [] ) );
		$this->assertSame(
			sprintf( $expected, '4.9.11', '5.1.0' ),
			DiviVersion::warning(
				'4.9.11',
				[
					'4.9.11' => 1,
					'5.1.0'  => 1,
				]
			)
		);
		$this->assertSame( sprintf( $expected, 'none', '5.1.0' ), DiviVersion::warning( '', [ '5.1.0' => 1 ] ) );
	}

	/**
	 * A missing Divi install gives a note with the content versions.
	 *
	 * @return void
	 */
	public function test_warning_when_divi_missing(): void {
		$this->assertSame(
			'Divi not found; content versions: 4.9.11, 4.3.0. Tested with Divi 4.9.11.',
			DiviVersion::warning(
				'',
				[
					'4.9.11' => 2,
					'4.3.0'  => 1,
				]
			)
		);
		$this->assertSame( 'Divi not found; content versions: none. Tested with Divi 4.9.11.', DiviVersion::warning( '', [] ) );
	}

	/**
	 * The census counts the modules per builder version, and ignores
	 * modules without one.
	 *
	 * @return void
	 */
	public function test_census_counts_modules_per_builder_version(): void {
		$content = '[et_pb_section _builder_version="4.9.11"][et_pb_row _builder_version="4.9.11"][et_pb_column type="4_4" _builder_version="4.9.11"][et_pb_text _builder_version="4.3.0"]Lorem ipsum.[/et_pb_text][et_pb_text]Dolor sit.[/et_pb_text][et_pb_text _builder_version=""]Amet.[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';

		$this->assertSame(
			[
				'4.9.11' => 3,
				'4.3.0'  => 1,
			],
			DiviVersion::census( $content )
		);
		$this->assertSame( [], DiviVersion::census( '[et_pb_text]Lorem ipsum.[/et_pb_text]' ) );
		$this->assertSame( [], DiviVersion::census( '' ) );
	}

	/**
	 * Censuses merge into totals, and the newest version is found by number.
	 *
	 * @return void
	 */
	public function test_merge_and_newest(): void {
		$totals = DiviVersion::merge(
			[ '4.9.11' => 1 ],
			[
				'4.9.11' => 2,
				'4.10.0' => 1,
			]
		);

		$this->assertSame(
			[
				'4.9.11' => 3,
				'4.10.0' => 1,
			],
			$totals
		);
		$this->assertSame( '4.10.0', DiviVersion::newest( array_keys( $totals ) ) );
		$this->assertSame( '', DiviVersion::newest( [] ) );
	}

	/**
	 * Without a Divi install, a command warns once and lists the content
	 * versions.
	 *
	 * @return void
	 */
	public function test_preview_warns_once_when_divi_is_not_installed(): void {
		$id = $this->insert_post( '[et_pb_section _builder_version="4.3.0"][et_pb_row _builder_version="4.3.0"][/et_pb_row][/et_pb_section]' );

		$this->assertNull( DiviVersion::installed() );

		$this->command( 'preview', [ (string) $id ], [ 'tree' => true ] );

		$this->assertSame(
			[ 'Divi not found; content versions: 4.3.0. Tested with Divi 4.9.11.' ],
			$this->messages( 'warning' )
		);
	}

	/**
	 * With a Divi install of another version, a command warns once for
	 * content that holds a different builder version.
	 *
	 * @return void
	 */
	public function test_preview_warns_once_for_different_content_version(): void {
		$this->install_divi( '4.3.0' );

		$id = $this->insert_post( '[et_pb_section _builder_version="4.3.0"][et_pb_row _builder_version="4.3.0"][/et_pb_row][/et_pb_section]' );

		$this->assertSame(
			[
				'source'  => 'Divi theme builder',
				'version' => '4.3.0',
			],
			DiviVersion::installed()
		);

		$this->command( 'preview', [ (string) $id ], [ 'tree' => true ] );

		$this->assertSame(
			[ 'Divi version differs from the tested version 4.9.11 (installed: 4.3.0; newest content version: 4.3.0). Check layout defaults and module output against the old site.' ],
			$this->messages( 'warning' )
		);
	}

	/**
	 * With the tested Divi version installed and in the content, a command
	 * gives no warning.
	 *
	 * @return void
	 */
	public function test_preview_gives_no_warning_when_versions_equal(): void {
		$this->install_divi( DiviVersion::TESTED );

		$id = $this->insert_post( '[et_pb_section _builder_version="4.9.11"][/et_pb_section]' );

		$this->command( 'preview', [ (string) $id ], [ 'tree' => true ] );

		$this->assertSame( [], $this->messages( 'warning' ) );
	}

	/**
	 * The builder version is read from the bundled builder's functions file,
	 * and a missing file or definition gives an empty string.
	 *
	 * @return void
	 */
	public function test_builder_version_reads_the_bundled_builder(): void {
		$path = wp_tempnam( 'builder-functions' );

		file_put_contents( $path, "<?php\nif ( ! defined( 'ET_BUILDER_PRODUCT_VERSION' ) ) {\n\tdefine( 'ET_BUILDER_PRODUCT_VERSION', '4.1.2' );\n}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary test file.
		$this->assertSame( '4.1.2', DiviVersion::builder_version( $path ) );

		file_put_contents( $path, "<?php\n// No version here.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary test file.
		$this->assertSame( '', DiviVersion::builder_version( $path ) );

		wp_delete_file( $path );
		$this->assertSame( '', DiviVersion::builder_version( $path ) );
	}
}
