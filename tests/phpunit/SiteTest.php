<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\SiteSettings;
use WP_CLI;
use WP_Error;

/**
 * Tests the `site` command and the site settings it moves: the logo and the
 * menus.
 */
class SiteTest extends TestCase {

	/**
	 * Stylesheet of the theme that holds the Divi menu locations.
	 */
	const DIVI_THEME = 'lorem-divi-child';

	/**
	 * `site` stops with an error without `--theme` when the active theme is
	 * not Divi.
	 *
	 * @return void
	 */
	public function test_site_command_without_theme_is_an_error_when_the_theme_is_not_divi(): void {
		$this->expectException( \WP_CLI_Error::class );
		$this->expectExceptionMessage( 'Pass the Divi theme with --theme=<stylesheet>.' );

		$this->command( 'site', [], [] );
	}

	/**
	 * Without `--force`, locations that already have a menu are kept, and
	 * nothing is written.
	 *
	 * @return void
	 */
	public function test_keep_menus_keeps_locations_that_are_set_and_writes_nothing(): void {
		$first  = wp_create_nav_menu( 'Lorem menu' );
		$second = wp_create_nav_menu( 'Ipsum menu' );

		$this->set_divi_locations(
			[
				'primary' => $first,
				'footer'  => $second,
			]
		);

		$current = [
			'primary' => $second,
			'footer'  => $first,
		];

		set_theme_mod( 'nav_menu_locations', $current );

		$results = SiteSettings::keep_menus( self::DIVI_THEME );

		$this->assertSame(
			[
				'primary' => [
					'menu' => 'Ipsum menu',
					'kept' => true,
				],
				'footer'  => [
					'menu' => 'Lorem menu',
					'kept' => true,
				],
			],
			$results
		);
		$this->assertSame( $current, get_theme_mod( 'nav_menu_locations' ) );
	}

	/**
	 * Without `--force`, a location without a menu gets the Divi menu, and a
	 * location with a menu keeps it.
	 *
	 * @return void
	 */
	public function test_keep_menus_assigns_only_locations_without_a_menu(): void {
		$first  = wp_create_nav_menu( 'Lorem menu' );
		$second = wp_create_nav_menu( 'Ipsum menu' );

		$this->set_divi_locations(
			[
				'primary' => $first,
				'footer'  => $second,
			]
		);

		set_theme_mod( 'nav_menu_locations', [ 'primary' => $second ] );

		$results = SiteSettings::keep_menus( self::DIVI_THEME );

		$this->assertTrue( $results['primary']['kept'] );
		$this->assertFalse( $results['footer']['kept'] );
		$this->assertSame(
			[
				'primary' => $second,
				'footer'  => $second,
			],
			get_theme_mod( 'nav_menu_locations' )
		);
	}

	/**
	 * A location that is set to a menu that no longer exists counts as not
	 * set.
	 *
	 * @return void
	 */
	public function test_keep_menus_replaces_a_location_whose_menu_is_gone(): void {
		$menu = wp_create_nav_menu( 'Lorem menu' );

		$this->set_divi_locations( [ 'primary' => $menu ] );
		set_theme_mod( 'nav_menu_locations', [ 'primary' => 999999 ] );

		$results = SiteSettings::keep_menus( self::DIVI_THEME );

		$this->assertFalse( $results['primary']['kept'] );
		$this->assertSame( [ 'primary' => $menu ], get_theme_mod( 'nav_menu_locations' ) );
	}

	/**
	 * With `--force`, the Divi menus replace the menus of set locations.
	 *
	 * @return void
	 */
	public function test_keep_menus_with_force_replaces_set_locations(): void {
		$first  = wp_create_nav_menu( 'Lorem menu' );
		$second = wp_create_nav_menu( 'Ipsum menu' );

		$this->set_divi_locations(
			[
				'primary' => $first,
				'footer'  => $second,
			]
		);

		set_theme_mod(
			'nav_menu_locations',
			[
				'primary' => $second,
				'footer'  => $first,
			]
		);

		$results = SiteSettings::keep_menus( self::DIVI_THEME, true );

		$this->assertFalse( $results['primary']['kept'] );
		$this->assertFalse( $results['footer']['kept'] );
		$this->assertSame( 'Lorem menu', $results['primary']['menu'] );
		$this->assertSame(
			[
				'primary' => $first,
				'footer'  => $second,
			],
			get_theme_mod( 'nav_menu_locations' )
		);
	}

	/**
	 * With `--force`, menu ID 0 and a menu that does not exist are skipped.
	 *
	 * @return void
	 */
	public function test_keep_menus_with_force_skips_menu_zero_and_missing_menus(): void {
		$menu = wp_create_nav_menu( 'Lorem menu' );

		$this->set_divi_locations(
			[
				'zero'    => 0,
				'missing' => 999999,
				'main'    => $menu,
			]
		);

		$results = SiteSettings::keep_menus( self::DIVI_THEME, true );

		$this->assertSame( [ 'main' ], array_keys( $results ) );
		$this->assertSame( [ 'main' => $menu ], get_theme_mod( 'nav_menu_locations' ) );
	}

	/**
	 * With only locations that have no menu, no result and no write.
	 *
	 * @return void
	 */
	public function test_keep_menus_returns_nothing_without_menus_to_keep(): void {
		$this->assertSame( [], SiteSettings::keep_menus( self::DIVI_THEME ) );

		$this->set_divi_locations(
			[
				'zero'    => 0,
				'missing' => 999999,
			]
		);

		$this->assertSame( [], SiteSettings::keep_menus( self::DIVI_THEME, true ) );
		$this->assertFalse( get_theme_mod( 'nav_menu_locations' ) );
	}

	/**
	 * When every location is already set, the command says so and sets
	 * nothing.
	 *
	 * @return void
	 */
	public function test_site_command_reports_that_all_locations_are_set(): void {
		$menu    = wp_create_nav_menu( 'Lorem menu' );
		$current = [ 'primary' => $menu ];

		$this->set_divi_locations( [ 'primary' => $menu ] );
		set_theme_mod( 'nav_menu_locations', $current );

		$this->command( 'site', [], [ 'theme' => self::DIVI_THEME ] );

		$this->assertContains( 'All menu locations are already set; pass --force to replace them.', $this->messages( 'log' ) );
		$this->assertSame(
			[
				[
					'location' => 'primary',
					'menu'     => 'Lorem menu',
					'status'   => 'kept',
				],
			],
			WP_CLI::$rows
		);
		$this->assertSame( $current, get_theme_mod( 'nav_menu_locations' ) );
	}

	/**
	 * When a location gets a menu, the command lists the locations to
	 * register in the theme.
	 *
	 * @return void
	 */
	public function test_site_command_assigns_menus_and_lists_locations(): void {
		$menu = wp_create_nav_menu( 'Lorem menu' );

		$this->set_divi_locations( [ 'primary' => $menu ] );

		$this->command( 'site', [], [ 'theme' => self::DIVI_THEME ] );

		$this->assertContains( 'Classic menus kept. Register these locations in the theme:', $this->messages( 'log' ) );
		$this->assertSame( 'assigned', WP_CLI::$rows[0]['status'] );
		$this->assertSame( [ 'primary' => $menu ], get_theme_mod( 'nav_menu_locations' ) );
	}

	/**
	 * Without menu locations, the command warns.
	 *
	 * @return void
	 */
	public function test_site_command_warns_without_menu_locations(): void {
		$this->command( 'site', [], [ 'theme' => self::DIVI_THEME ] );

		$this->assertContains( 'No menu locations found. Pass the Divi theme with --theme.', $this->messages( 'warning' ) );
	}

	/**
	 * The command prints the integration code that moves by hand.
	 *
	 * @return void
	 */
	public function test_site_command_prints_integration_code(): void {
		update_option( 'et_divi', [ 'divi_integration_head' => '<script>console.log("Lorem");</script>' ] );

		$this->command( 'site', [], [ 'theme' => self::DIVI_THEME ] );

		$stdout = array_values(
			array_filter(
				WP_CLI::$messages,
				fn( array $message ) => in_array( $message[0], [ 'log', 'line' ], true )
			)
		);

		$label = array_search( [ 'log', 'Move this divi_integration_head code by hand:' ], $stdout, true );
		$code  = array_search( [ 'line', '<script>console.log("Lorem");</script>' ], $stdout, true );

		$this->assertIsInt( $label );
		$this->assertIsInt( $code );
		$this->assertLessThan( $code, $label );
	}

	/**
	 * With `--menus=navigation`, each menu is copied into a Navigation post,
	 * and running the command again updates the same post.
	 *
	 * @return void
	 */
	public function test_site_command_copies_menus_into_navigation_posts(): void {
		$menu = wp_create_nav_menu( 'Lorem menu' );

		wp_update_nav_menu_item(
			$menu,
			0,
			[
				'menu-item-title'  => 'Ipsum',
				'menu-item-url'    => 'https://example.com/ipsum',
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			]
		);

		$this->set_divi_locations( [ 'primary' => $menu ] );

		$this->command(
			'site',
			[],
			[
				'theme' => self::DIVI_THEME,
				'menus' => 'navigation',
			]
		);

		$rows = WP_CLI::$rows;

		$this->assertCount( 1, $rows );
		$this->assertSame( 'primary', $rows[0]['location'] );
		$this->assertSame( 'Lorem menu', $rows[0]['menu'] );
		$this->assertSame( 'wp_navigation', get_post_type( $rows[0]['navigation'] ) );
		$this->assertStringContainsString( 'https://example.com/ipsum', (string) get_post_field( 'post_content', $rows[0]['navigation'], 'raw' ) );

		$this->command(
			'site',
			[],
			[
				'theme' => self::DIVI_THEME,
				'menus' => 'navigation',
			]
		);

		$this->assertSame( $rows[0]['navigation'], WP_CLI::$rows[0]['navigation'] );
	}

	/**
	 * A logo that is already set is kept without `--force`.
	 *
	 * @return void
	 */
	public function test_import_logo_keeps_an_existing_logo(): void {
		$kept = $this->create_logo( 'ipsum-logo.png' );
		$logo = $this->create_logo( 'lorem-logo.png' );

		update_option( 'site_logo', $kept );

		$result = SiteSettings::import_logo();

		$this->assertSame(
			[
				'attachment' => $kept,
				'kept'       => true,
			],
			$result
		);
		$this->assertSame( $kept, (int) get_option( 'site_logo' ) );

		$result = SiteSettings::import_logo( true );

		$this->assertSame(
			[
				'attachment' => $logo,
				'kept'       => false,
			],
			$result
		);
		$this->assertSame( $logo, (int) get_option( 'site_logo' ) );
	}

	/**
	 * Without a logo, the import sets the logo from Divi's option.
	 *
	 * @return void
	 */
	public function test_import_logo_sets_the_logo(): void {
		$logo = $this->create_logo( 'lorem-logo.png' );

		$this->assertSame(
			[
				'attachment' => $logo,
				'kept'       => false,
			],
			SiteSettings::import_logo()
		);
		$this->assertSame( $logo, (int) get_option( 'site_logo' ) );
	}

	/**
	 * A missing Divi logo option, or a logo that is not in the media library,
	 * returns an error and sets nothing.
	 *
	 * @return void
	 */
	public function test_import_logo_returns_an_error_when_it_cannot_find_the_logo(): void {
		$result = SiteSettings::import_logo();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 's3s_divi_migration_no_logo', $result->get_error_code() );
		$this->assertSame( 'Divi has no logo set.', $result->get_error_message() );

		update_option( 'et_divi', [ 'divi_logo' => 'https://example.com/missing-logo.png' ] );

		$result = SiteSettings::import_logo();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'The logo is not in the media library: https://example.com/missing-logo.png', $result->get_error_message() );
		$this->assertFalse( get_option( 'site_logo' ) );
	}

	/**
	 * The command logs the kept logo.
	 *
	 * @return void
	 */
	public function test_site_command_logs_the_kept_logo(): void {
		$this->create_logo( 'lorem-logo.png' );

		$kept = $this->create_logo( 'ipsum-logo.png' );

		update_option( 'site_logo', $kept );

		$this->command( 'site', [], [ 'theme' => self::DIVI_THEME ] );

		$this->assertContains( sprintf( 'Site logo: kept attachment %d.', $kept ), $this->messages( 'log' ) );
	}

	/**
	 * Sets the menu locations of the Divi theme.
	 *
	 * @param array<string, int> $locations Menu IDs keyed by location.
	 * @return void
	 */
	private function set_divi_locations( array $locations ): void {
		update_option( 'theme_mods_' . self::DIVI_THEME, [ 'nav_menu_locations' => $locations ] );
	}

	/**
	 * Creates an attachment and sets Divi's logo option to its URL.
	 *
	 * @param string $name File name.
	 * @return int Attachment ID.
	 */
	private function create_logo( string $name ): int {
		$id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/png',
				'file'           => '2020/01/' . $name,
			]
		);

		update_option( 'et_divi', [ 'divi_logo' => wp_get_upload_dir()['baseurl'] . '/2020/01/' . $name ] );

		return $id;
	}
}
