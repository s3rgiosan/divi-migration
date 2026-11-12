<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use WP_UnitTestCase;

/**
 * Tests the sidebar rule: a template part with the slug from the settings.
 */
class SidebarRuleTest extends WP_UnitTestCase {

	/**
	 * A sidebar module converts to a template part with the default slug.
	 *
	 * @return void
	 */
	public function test_sidebar_becomes_template_part(): void {
		$converter = Converter::create();
		$specs     = $converter->convert_content( '[et_pb_sidebar orientation="left"][/et_pb_sidebar]' );

		$this->assertCount( 1, $specs );
		$this->assertSame( 'core/template-part', $specs[0]['name'] );
		$this->assertSame( [ 'slug' => 'sidebar' ], $specs[0]['attributes'] );
		$this->assertSame( '<!-- wp:template-part {"slug":"sidebar"} /-->', trim( $converter->serializer()->serialize( $specs ) ) );
	}

	/**
	 * The slug comes from the settings.
	 *
	 * @return void
	 */
	public function test_slug_comes_from_settings(): void {
		$converter                         = Converter::create();
		$converter->settings->sidebar_part = 'lorem-aside';

		$specs = $converter->convert_content( '[et_pb_sidebar][/et_pb_sidebar]' );

		$this->assertSame( 'lorem-aside', $specs[0]['attributes']['slug'] );
		$this->assertStringContainsString( '"slug":"lorem-aside"', $converter->serializer()->serialize( $specs ) );
	}

	/**
	 * The module class and anchor reach the template part.
	 *
	 * @return void
	 */
	public function test_module_class_and_anchor_reach_the_part(): void {
		$specs = Converter::create()->convert_content( '[et_pb_sidebar module_class="lorem-aside" module_id="ipsum"][/et_pb_sidebar]' );

		$this->assertSame( 'lorem-aside', $specs[0]['attributes']['className'] );
		$this->assertSame( 'ipsum', $specs[0]['attributes']['anchor'] );
	}
}
