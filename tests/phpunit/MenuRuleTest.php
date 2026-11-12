<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use WP_UnitTestCase;

/**
 * Tests the menu rule: the Navigation block, its menu binding for classic
 * and Navigation menus, and the note for the menu logo.
 */
class MenuRuleTest extends WP_UnitTestCase {

	/**
	 * Registers a menu location and assigns a new classic menu to it.
	 *
	 * @param string $location Location slug.
	 * @param string $name     Menu name.
	 * @return int Menu term ID.
	 */
	private function assign_menu( string $location, string $name ): int {
		register_nav_menu( $location, 'Lorem location' );

		$menu_id = wp_create_nav_menu( $name );

		set_theme_mod( 'nav_menu_locations', [ $location => $menu_id ] );

		return $menu_id;
	}

	/**
	 * Converts a menu module and returns the Navigation block spec.
	 *
	 * @param string         $module    Divi module.
	 * @param string         $menus     The `menus` setting.
	 * @param Converter|null $converter Receives the converter used.
	 * @return array
	 */
	private function convert( string $module, string $menus = 'classic', ?Converter &$converter = null ): array {
		$converter                  = Converter::create();
		$converter->settings->menus = $menus;

		$specs = $converter->convert_content( $module );

		$this->assertCount( 1, $specs );
		$this->assertSame( 'core/navigation', $specs[0]['name'] );

		return $specs[0];
	}

	/**
	 * A classic menu converts to a Navigation block bound to the location
	 * the menu is assigned to.
	 *
	 * @return void
	 */
	public function test_classic_menu_binds_to_its_location(): void {
		$menu_id = $this->assign_menu( 'lorem-primary', 'Lorem menu' );

		$spec = $this->convert( '[et_pb_menu menu_id="' . $menu_id . '"][/et_pb_menu]' );

		$this->assertSame( 'lorem-primary', $spec['attributes']['__unstableLocation'] );
		$this->assertArrayNotHasKey( 'ref', $spec['attributes'] );
		$this->assertSame( 'mobile', $spec['attributes']['overlayMenu'] );
	}

	/**
	 * A menu that no location holds adds no binding.
	 *
	 * @return void
	 */
	public function test_classic_menu_without_location_has_no_binding(): void {
		$this->assign_menu( 'lorem-primary', 'Lorem menu' );
		$other = wp_create_nav_menu( 'Ipsum menu' );

		$spec = $this->convert( '[et_pb_menu menu_id="' . $other . '"][/et_pb_menu]' );

		$this->assertArrayNotHasKey( '__unstableLocation', $spec['attributes'] );
	}

	/**
	 * A full-width menu converts the same way as a menu.
	 *
	 * @return void
	 */
	public function test_fullwidth_menu_binds_to_its_location(): void {
		$menu_id = $this->assign_menu( 'lorem-primary', 'Lorem menu' );

		$spec = $this->convert( '[et_pb_fullwidth_menu menu_id="' . $menu_id . '"][/et_pb_fullwidth_menu]' );

		$this->assertSame( 'lorem-primary', $spec['attributes']['__unstableLocation'] );
	}

	/**
	 * A menu without a menu ID binds to the primary menu location.
	 *
	 * @return void
	 */
	public function test_menu_without_id_binds_to_primary_location(): void {
		$spec = $this->convert( '[et_pb_menu][/et_pb_menu]' );

		$this->assertSame( 'primary-menu', $spec['attributes']['__unstableLocation'] );
	}

	/**
	 * The saved markup is a self-closing Navigation block with the location.
	 *
	 * @return void
	 */
	public function test_classic_menu_markup_holds_the_location(): void {
		$menu_id = $this->assign_menu( 'lorem-primary', 'Lorem menu' );

		$converter = Converter::create();
		$markup    = $converter->serializer()->serialize( $converter->convert_content( '[et_pb_menu menu_id="' . $menu_id . '"][/et_pb_menu]' ) );

		$this->assertStringStartsWith( '<!-- wp:navigation ', $markup );
		$this->assertStringContainsString( '"__unstableLocation":"lorem-primary"', $markup );
		$this->assertStringEndsWith( '/-->', trim( $markup ) );
	}

	/**
	 * With the navigation setting, the block points to the Navigation post
	 * that has the slug of the classic menu.
	 *
	 * @return void
	 */
	public function test_navigation_setting_sets_ref_to_navigation_post(): void {
		$menu_id = $this->assign_menu( 'lorem-primary', 'Lorem menu' );
		$menu    = wp_get_nav_menu_object( $menu_id );

		$navigation = self::factory()->post->create(
			[
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_name'    => $menu->slug,
				'post_content' => '',
			]
		);

		$spec = $this->convert( '[et_pb_menu menu_id="' . $menu_id . '"][/et_pb_menu]', 'navigation' );

		$this->assertSame( $navigation, $spec['attributes']['ref'] );
		$this->assertArrayNotHasKey( '__unstableLocation', $spec['attributes'] );
	}

	/**
	 * With the navigation setting and no matching Navigation post, the block
	 * has neither a reference nor a location.
	 *
	 * @return void
	 */
	public function test_navigation_setting_without_post_has_no_ref(): void {
		$menu_id = $this->assign_menu( 'lorem-primary', 'Lorem menu' );

		$spec = $this->convert( '[et_pb_menu menu_id="' . $menu_id . '"][/et_pb_menu]', 'navigation' );

		$this->assertArrayNotHasKey( 'ref', $spec['attributes'] );
		$this->assertArrayNotHasKey( '__unstableLocation', $spec['attributes'] );
	}

	/**
	 * The menu style sets the flex justification of the block.
	 *
	 * @return void
	 */
	public function test_menu_style_sets_justification(): void {
		$default  = $this->convert( '[et_pb_menu][/et_pb_menu]' );
		$centered = $this->convert( '[et_pb_menu menu_style="centered"][/et_pb_menu]' );
		$logo     = $this->convert( '[et_pb_menu menu_style="inline_centered_logo"][/et_pb_menu]' );

		$this->assertSame(
			[
				'type'           => 'flex',
				'justifyContent' => 'left',
			],
			$default['attributes']['layout']
		);
		$this->assertSame( 'center', $centered['attributes']['layout']['justifyContent'] );
		$this->assertSame( 'center', $logo['attributes']['layout']['justifyContent'] );
	}

	/**
	 * A menu logo adds a note, and a menu without one adds none.
	 *
	 * @return void
	 */
	public function test_menu_logo_adds_note(): void {
		$converter = null;
		$this->convert( '[et_pb_menu logo="https://example.com/logo.png"][/et_pb_menu]', 'classic', $converter );

		$this->assertSame( 1, $converter->report()['et_pb_menu']['not converted: menu logo'] );

		$this->convert( '[et_pb_menu][/et_pb_menu]', 'classic', $converter );

		$this->assertArrayNotHasKey( 'not converted: menu logo', $converter->report()['et_pb_menu'] ?? [] );
	}

	/**
	 * The logo link and alt text add no note without a logo, as Divi renders
	 * no logo then. With a logo, the full-width menu adds the note.
	 *
	 * @return void
	 */
	public function test_menu_logo_link_and_alt_add_no_note_without_logo(): void {
		$converter = null;
		$this->convert( '[et_pb_fullwidth_menu logo_url="https://example.com/" logo_alt="Lorem"][/et_pb_fullwidth_menu]', 'classic', $converter );

		$this->assertArrayNotHasKey( 'not converted: menu logo', $converter->report()['et_pb_fullwidth_menu'] ?? [] );

		$this->convert( '[et_pb_fullwidth_menu logo="https://example.com/logo.png" logo_url="https://example.com/"][/et_pb_fullwidth_menu]', 'classic', $converter );

		$this->assertArrayHasKey( 'not converted: menu logo', $converter->report()['et_pb_fullwidth_menu'] );
	}
}
