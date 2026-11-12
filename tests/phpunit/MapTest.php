<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Map;
use S3S\WP\DiviMigration\Targets\Target;
use WP_Error;

/**
 * Tests the validation of the migration map.
 */
class MapTest extends TestCase {

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
	 * Returns maps that are not valid, with the error message of each.
	 *
	 * @return array[]
	 */
	public static function data_invalid_maps(): array {
		$known = 'outstand-carousel, core-cover';

		return [
			'unknown key'                       => [ [ 'bogus' => 1 ], 'Unknown map keys: bogus.' ],
			'several unknown keys'              => [
				[
					'one' => 1,
					'two' => 2,
				],
				'Unknown map keys: one, two.',
			],
			'classNames not an object'          => [ [ 'classNames' => 'row' ], 'The map key classNames must be an object.' ],
			'targets not an object'             => [ [ 'targets' => 'auto' ], 'The map key targets must be an object.' ],
			'colors not an object'              => [ [ 'colors' => 'red' ], 'The map key colors must be an object.' ],
			'fonts not an object'               => [ [ 'fonts' => 'Open Sans' ], 'The map key fonts must be an object.' ],
			'modules not an object'             => [ [ 'modules' => 'skip' ], 'The map key modules must be an object.' ],
			'shortcodes not an object'          => [ [ 'shortcodes' => 'skip' ], 'The map key shortcodes must be an object.' ],
			'classes not an object'             => [ [ 'classes' => 'is-style-big' ], 'The map key classes must be an object.' ],
			'gutter above range'                => [ [ 'gutter' => 5 ], 'The map key gutter must be a whole number from 1 to 4.' ],
			'gutter below range'                => [ [ 'gutter' => 0 ], 'The map key gutter must be a whole number from 1 to 4.' ],
			'gutter as string'                  => [ [ 'gutter' => '3' ], 'The map key gutter must be a whole number from 1 to 4.' ],
			'gutter as float'                   => [ [ 'gutter' => 2.5 ], 'The map key gutter must be a whole number from 1 to 4.' ],
			'classNames value not a string'     => [ [ 'classNames' => [ 'row' => 5 ] ], 'The map key classNames needs a string value for row.' ],
			'sidebarPart not a string'          => [ [ 'sidebarPart' => 5 ], 'The map key sidebarPart must be a string.' ],
			'emptyParagraphs unknown value'     => [ [ 'emptyParagraphs' => 'remove' ], 'The map key emptyParagraphs must be keep or drop.' ],
			'menus unknown value'               => [ [ 'menus' => 'both' ], 'The map key menus must be classic or navigation.' ],
			'targets value not a string'        => [ [ 'targets' => [ 'carousel' => 5 ] ], 'The map key targets needs a string value for carousel.' ],
			'targets unknown kind'              => [ [ 'targets' => [ 'bogus' => 'auto' ] ], 'The map key targets names an unknown kind: bogus.' ],
			'targets unknown name'              => [ [ 'targets' => [ 'carousel' => 'nope' ] ], sprintf( 'The map key targets needs auto or one of %s for carousel, not nope.', $known ) ],
			'colors value not a string'         => [ [ 'colors' => [ '#336699' => 5 ] ], 'The map key colors needs a string value for #336699.' ],
			'fonts value not a string'          => [ [ 'fonts' => [ 'Open Sans' => [ 'body' ] ] ], 'The map key fonts needs a string value for Open Sans.' ],
			'classes empty key'                 => [ [ 'classes' => [ '' => 'is-style-big' ] ], 'The map key classes cannot have an empty key.' ],
			'classes blank key'                 => [ [ 'classes' => [ '  ' => 'is-style-big' ] ], 'The map key classes cannot have an empty key.' ],
			'classes number value'              => [ [ 'classes' => [ 'big' => 5 ] ], 'The class big needs a string of classes or an object of block attributes.' ],
			'classes list value'                => [ [ 'classes' => [ 'big' => [ 'one', 'two' ] ] ], 'The class big needs a string of classes or an object of block attributes.' ],
			'classes className not a string'    => [ [ 'classes' => [ 'big' => [ 'className' => 5 ] ] ], 'The class big needs a string of classes or an object of block attributes.' ],
			'classes invalid pattern'           => [ [ 'classes' => [ '/[/' => 'is-style-big' ] ], 'The class pattern /[/ is not a valid regular expression.' ],
			'modules string other than skip'    => [ [ 'modules' => [ 'et_pb_text' => 'drop' ] ], 'The module et_pb_text needs "skip" or { "markup": "…" }.' ],
			'modules markup not a string'       => [ [ 'modules' => [ 'et_pb_text' => [ 'markup' => 5 ] ] ], 'The module et_pb_text needs "skip" or { "markup": "…" }.' ],
			'modules object without markup'     => [ [ 'modules' => [ 'et_pb_text' => [ 'attrs' => [] ] ] ], 'The module et_pb_text needs "skip" or { "markup": "…" }.' ],
			'shortcodes number entry'           => [ [ 'shortcodes' => [ 'lorem' => 5 ] ], 'The shortcode lorem needs "skip", { "markup": "…" } or a list of them, each with an optional "attrs" object.' ],
			'shortcodes string other than skip' => [ [ 'shortcodes' => [ 'lorem' => 'drop' ] ], 'The shortcode lorem needs "skip", { "markup": "…" } or a list of them, each with an optional "attrs" object.' ],
			'shortcodes markup not a string'    => [ [ 'shortcodes' => [ 'lorem' => [ [ 'markup' => 5 ] ] ] ], 'The shortcode lorem needs "skip", { "markup": "…" } or a list of them, each with an optional "attrs" object.' ],
			'shortcodes attrs not an object'    => [
				[
					'shortcodes' => [
						'lorem' => [
							[
								'markup' => '<p>Lorem</p>',
								'attrs'  => 'x',
							],
						],
					],
				],
				'The shortcode lorem needs "skip", { "markup": "…" } or a list of them, each with an optional "attrs" object.',
			],
			'shortcodes entry without action'   => [ [ 'shortcodes' => [ 'lorem' => [ [ 'attrs' => [ 'id' => '1' ] ] ] ] ], 'The shortcode lorem needs "skip", { "markup": "…" } or a list of them, each with an optional "attrs" object.' ],
			'JSON list instead of object'       => [ [ 'one', 'two' ], 'Unknown map keys: 0, 1.' ],
		];
	}

	/**
	 * A map that is not valid returns an error that names the problem.
	 *
	 * @dataProvider data_invalid_maps
	 *
	 * @param array  $data    Map data.
	 * @param string $message Expected error message.
	 * @return void
	 */
	public function test_from_array_rejects_invalid_map( array $data, string $message ): void {
		$map = Map::from_array( $data );

		$this->assertInstanceOf( WP_Error::class, $map );
		$this->assertSame( 's3s_divi_migration_map', $map->get_error_code() );
		$this->assertSame( $message, $map->get_error_message() );
	}

	/**
	 * A map that holds every key, with valid values, is accepted and read.
	 *
	 * @return void
	 */
	public function test_from_array_reads_a_valid_map(): void {
		$map = Map::from_array(
			[
				'gutter'          => 4,
				'classNames'      => [ 'row' => 'is-style-lorem' ],
				'sidebarPart'     => 'lorem-sidebar',
				'targets'         => [
					'carousel'  => 'core-cover',
					'accordion' => 'auto',
					'table'     => 'flexible-table',
					'form'      => 'ws-form',
				],
				'menus'           => 'navigation',
				'emptyParagraphs' => 'drop',
				'colors'          => [ '#FF0000' => 'accent' ],
				'fonts'           => [ 'Open Sans' => 'body' ],
				'modules'         => [
					'et_pb_text'  => 'skip',
					'et_pb_image' => [ 'markup' => '<p>Lorem</p>' ],
				],
				'shortcodes'      => [ 'lorem' => 'skip' ],
				'classes'         => [
					'big'    => 'is-style-big',
					'/^p\d/' => '',
				],
			]
		);

		$this->assertInstanceOf( Map::class, $map );
		$this->assertSame( 4, $map->settings['gutter'] );
		$this->assertSame( 'navigation', $map->settings['menus'] );
		$this->assertSame( 'drop', $map->settings['emptyParagraphs'] );
		$this->assertSame( 'lorem-sidebar', $map->settings['sidebarPart'] );
		$this->assertSame( [ '#ff0000' => 'accent' ], $map->colors );
		$this->assertSame( [ 'open sans' => 'body' ], $map->fonts );
		$this->assertSame( 'skip', $map->module( 'et_pb_text' ) );
		$this->assertSame( '<p>Lorem</p>', $map->module( 'et_pb_image' ) );
		$this->assertNull( $map->module( 'et_pb_code' ) );
		$this->assertSame( [ 'lorem' => 'skip' ], $map->shortcodes );
		$this->assertSame(
			[
				'big'    => 'is-style-big',
				'/^p\d/' => '',
			],
			$map->classes
		);
	}

	/**
	 * An empty map is valid.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_an_empty_map(): void {
		$map = Map::from_array( [] );

		$this->assertInstanceOf( Map::class, $map );
		$this->assertSame( [], $map->settings );
		$this->assertSame( [], $map->classes );
	}

	/**
	 * A class mapped to `{}` is valid: an object without attributes.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_an_empty_object_for_a_class(): void {
		$map = Map::from_array( [ 'classes' => [ 'big' => [] ] ] );

		$this->assertInstanceOf( Map::class, $map );
		$this->assertSame( [ 'big' => [] ], $map->classes );
	}

	/**
	 * A class mapped to attributes, with or without a `className`, is valid.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_attributes_for_a_class(): void {
		$map = Map::from_array(
			[
				'classes' => [
					'big'   => [
						'className' => 'is-style-big',
						'align'     => 'wide',
					],
					'small' => [ 'align' => 'full' ],
				],
			]
		);

		$this->assertInstanceOf( Map::class, $map );
		$this->assertCount( 2, $map->classes );
	}

	/**
	 * A shortcode entry with `"skip": true` is valid, alone or in a list.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_skip_true_for_a_shortcode(): void {
		$alone = Map::from_array( [ 'shortcodes' => [ 'lorem' => [ 'skip' => true ] ] ] );
		$list  = Map::from_array(
			[
				'shortcodes' => [
					'lorem' => [
						[
							'attrs' => [ 'id' => '1' ],
							'skip'  => true,
						],
						[
							'attrs'  => [ 'id' => '2' ],
							'markup' => '<p>Lorem</p>',
						],
					],
				],
			]
		);

		$this->assertInstanceOf( Map::class, $alone );
		$this->assertInstanceOf( Map::class, $list );
		$this->assertSame( [ 'skip' => true ], $alone->shortcodes['lorem'] );
	}

	/**
	 * A gutter from 1 to 4 is valid.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_gutters_one_to_four(): void {
		foreach ( [ 1, 2, 3, 4 ] as $gutter ) {
			$map = Map::from_array( [ 'gutter' => $gutter ] );

			$this->assertInstanceOf( Map::class, $map );
			$this->assertSame( $gutter, $map->settings['gutter'] );
		}
	}

	/**
	 * A target that a filter adds is a valid name for its kind.
	 *
	 * @return void
	 */
	public function test_from_array_accepts_a_target_that_a_filter_adds(): void {
		$this->assertInstanceOf( WP_Error::class, Map::from_array( [ 'targets' => [ 'carousel' => 'lorem-carousel' ] ] ) );

		add_filter(
			's3s_divi_migration_targets',
			function ( array $targets ) {
				$targets['carousel']['lorem-carousel'] = new class() implements Target {

					/**
					 * {@inheritDoc}
					 *
					 * @return string[]
					 */
					public function blocks(): array {
						return [];
					}
				};

				return $targets;
			}
		);

		$map = Map::from_array( [ 'targets' => [ 'carousel' => 'lorem-carousel' ] ] );

		$this->assertInstanceOf( Map::class, $map );

		$error = Map::from_array( [ 'targets' => [ 'carousel' => 'nope' ] ] );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'The map key targets needs auto or one of outstand-carousel, core-cover, lorem-carousel for carousel, not nope.', $error->get_error_message() );
	}

	/**
	 * A file with a valid map loads.
	 *
	 * @return void
	 */
	public function test_from_file_reads_a_json_map(): void {
		$path = $this->temp_file( '{ "gutter": 2, "classes": { "big": "is-style-big", "empty": {} } }' );

		$map = Map::from_file( $path );

		$this->assertInstanceOf( Map::class, $map );
		$this->assertSame( 2, $map->settings['gutter'] );
		$this->assertSame(
			[
				'big'   => 'is-style-big',
				'empty' => [],
			],
			$map->classes
		);
	}

	/**
	 * A file that is not JSON, or not a JSON object, returns an error.
	 *
	 * @return void
	 */
	public function test_from_file_rejects_content_that_is_not_a_json_object(): void {
		foreach ( [ 'not json at all', '', '"lorem"', '5', 'null' ] as $content ) {
			$path = $this->temp_file( $content );
			$map  = Map::from_file( $path );

			$this->assertInstanceOf( WP_Error::class, $map, $content );
			$this->assertSame( sprintf( 'The map file %s is not a JSON object.', $path ), $map->get_error_message(), $content );
		}
	}

	/**
	 * A file with invalid JSON content for a map key returns the key's error.
	 *
	 * @return void
	 */
	public function test_from_file_returns_the_validation_error(): void {
		$map = Map::from_file( $this->temp_file( '{ "gutter": 5 }' ) );

		$this->assertInstanceOf( WP_Error::class, $map );
		$this->assertSame( 'The map key gutter must be a whole number from 1 to 4.', $map->get_error_message() );
	}

	/**
	 * A file that does not exist returns an error.
	 *
	 * @return void
	 */
	public function test_from_file_rejects_a_missing_file(): void {
		$path = trailingslashit( get_temp_dir() ) . 'divi-migration-missing-map.json';
		$map  = Map::from_file( $path );

		$this->assertInstanceOf( WP_Error::class, $map );
		$this->assertSame( sprintf( 'Cannot read the map file %s.', $path ), $map->get_error_message() );
	}

	/**
	 * Writes a temporary file that the test removes at its end.
	 *
	 * @param string $content File content.
	 * @return string File path.
	 */
	private function temp_file( string $content ): string {
		$path = wp_tempnam( 'divi-migration-map' );

		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A temporary test file.

		$this->files[] = $path;

		return $path;
	}
}
