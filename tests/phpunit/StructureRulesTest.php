<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Targets\CoreCover;
use WP_UnitTestCase;

/**
 * Tests the rules of inner rows, inner columns, full-width images, full-width
 * sliders and modules that have no rule.
 */
class StructureRulesTest extends WP_UnitTestCase {

	/**
	 * Converts Divi content into block specs with a converter.
	 *
	 * @param Converter $converter Converter.
	 * @param string    $content   Divi content.
	 * @return array[]
	 */
	private function specs( Converter $converter, string $content ): array {
		return $converter->convert_content( $content );
	}

	/**
	 * Returns the blocks with a name, at any depth.
	 *
	 * @param array[] $specs Block specs.
	 * @param string  $name  Block name.
	 * @return array[]
	 */
	private function find( array $specs, string $name ): array {
		$found = [];

		foreach ( $specs as $spec ) {
			if ( ( $spec['name'] ?? '' ) === $name ) {
				$found[] = $spec;
			}

			array_push( $found, ...$this->find( $spec['innerBlocks'] ?? [], $name ) );
		}

		return $found;
	}

	/**
	 * Wraps module shortcodes in a section, a row and a column.
	 *
	 * @param string $modules Module shortcodes.
	 * @return string
	 */
	private function page( string $modules ): string {
		return '[et_pb_section][et_pb_row][et_pb_column type="4_4"]' . $modules . '[/et_pb_column][/et_pb_row][/et_pb_section]';
	}

	/**
	 * An inner row with two columns becomes a Columns block inside the Group
	 * of the single outer column, and its columns take the width of the
	 * custom gutter.
	 *
	 * @return void
	 */
	public function test_inner_row_becomes_nested_columns(): void {
		$converter = Converter::create();
		$content   = $this->page( '[et_pb_row_inner use_custom_gutter="on" gutter_width="4"][et_pb_column_inner type="1_2"][et_pb_text]<p>Lorem</p>[/et_pb_text][/et_pb_column_inner][et_pb_column_inner type="1_2"][et_pb_text]<p>Ipsum</p>[/et_pb_text][/et_pb_column_inner][/et_pb_row_inner]' );
		$specs     = $this->specs( $converter, $content );
		$columns   = $this->find( $specs, 'core/columns' );

		$this->assertCount( 1, $columns );
		$this->assertSame( 'core/group', $specs[0]['name'] );
		$this->assertSame( '8%', $columns[0]['attributes']['style']['spacing']['blockGap']['left'] );
		$this->assertSame( '30px', $columns[0]['attributes']['style']['spacing']['blockGap']['top'] );
		$this->assertSame( [ 'core/column', 'core/column' ], array_column( $columns[0]['innerBlocks'], 'name' ) );
		$this->assertSame( '46%', $columns[0]['innerBlocks'][0]['attributes']['width'] );
		$this->assertSame( '46%', $columns[0]['innerBlocks'][1]['attributes']['width'] );
		$this->assertSame(
			[
				'et_pb_column_inner' => [ 'converted' => 2 ],
				'et_pb_row_inner'    => [ 'converted' => 1 ],
			],
			array_intersect_key( $converter->report(), array_flip( [ 'et_pb_row_inner', 'et_pb_column_inner' ] ) )
		);
	}

	/**
	 * An inner row with one column and no column design becomes a Group that
	 * holds the column's modules and its class.
	 *
	 * @return void
	 */
	public function test_inner_row_with_one_column_becomes_group(): void {
		$content = $this->page( '[et_pb_row_inner][et_pb_column_inner type="4_4" module_class="lorem-column"][et_pb_text]<p>Lorem</p>[/et_pb_text][/et_pb_column_inner][/et_pb_row_inner]' );
		$specs   = $this->specs( Converter::create(), $content );

		$this->assertSame( [], $this->find( $specs, 'core/columns' ) );

		$groups = $this->find( $specs, 'core/group' );

		$this->assertCount( 4, $groups );
		$this->assertStringContainsString( 'is-style-row-inner', $groups[2]['attributes']['className'] );
		$this->assertStringContainsString( 'lorem-column', $groups[2]['attributes']['className'] );
		$this->assertCount( 1, $this->find( $groups[2]['innerBlocks'], 'core/group' ) );
	}

	/**
	 * An empty inner column is hidden on tablets and phones, and a column with
	 * modules is not.
	 *
	 * @return void
	 */
	public function test_empty_inner_column_is_hidden_on_small_screens(): void {
		$content = $this->page( '[et_pb_row_inner][et_pb_column_inner type="1_2"][et_pb_text]<p>Lorem</p>[/et_pb_text][/et_pb_column_inner][et_pb_column_inner type="1_2"][/et_pb_column_inner][/et_pb_row_inner]' );
		$columns = $this->find( $this->specs( Converter::create(), $content ), 'core/column' );

		$this->assertCount( 2, $columns );
		$this->assertArrayNotHasKey( 'metadata', $columns[0]['attributes'] );
		$this->assertFalse( $columns[1]['attributes']['metadata']['blockVisibility']['viewport']['mobile'] );
		$this->assertFalse( $columns[1]['attributes']['metadata']['blockVisibility']['viewport']['tablet'] );
	}

	/**
	 * A full-width image module is an Image block at the full width, and
	 * ignores the alignment of the regular image module.
	 *
	 * @return void
	 */
	public function test_fullwidth_image_has_full_width(): void {
		$converter = Converter::create();
		$specs     = $this->specs( $converter, '[et_pb_fullwidth_image src="https://example.com/lorem.jpg" alt="Lorem" title_text="Ipsum" align="right"][/et_pb_fullwidth_image]' );

		$this->assertCount( 1, $specs );
		$this->assertSame( 'core/image', $specs[0]['name'] );
		$this->assertSame( 'https://example.com/lorem.jpg', $specs[0]['attributes']['url'] );
		$this->assertSame( 'Lorem', $specs[0]['attributes']['alt'] );
		$this->assertSame( 'Ipsum', $specs[0]['attributes']['title'] );
		$this->assertSame( '100%', $specs[0]['attributes']['width'] );
		$this->assertArrayNotHasKey( 'align', $specs[0]['attributes'] );
		$this->assertSame( [ 'converted' => 1 ], $converter->report()['et_pb_fullwidth_image'] );
	}

	/**
	 * A full-width image module with no source converts to nothing.
	 *
	 * @return void
	 */
	public function test_fullwidth_image_without_source_is_dropped(): void {
		$specs = $this->specs( Converter::create(), '[et_pb_fullwidth_image alt="Lorem"][/et_pb_fullwidth_image]' );

		$this->assertSame( [], $specs );
	}

	/**
	 * A full-width slider is a full-width carousel with one slide block per
	 * slide, and a regular slider is not full width.
	 *
	 * @return void
	 */
	public function test_fullwidth_slider_is_a_full_width_carousel(): void {
		// The Cover target needs only core blocks, so the result does not depend on the block plugins of the site.
		add_filter(
			's3s_divi_migration_targets',
			fn( array $targets ) => [ 'carousel' => [ 'core-cover' => new CoreCover() ] ] + $targets
		);

		$slides = '[et_pb_slide heading="Lorem"]Lorem[/et_pb_slide][et_pb_slide heading="Ipsum"]Ipsum[/et_pb_slide]';

		$full    = $this->specs( Converter::create(), '[et_pb_fullwidth_slider]' . $slides . '[/et_pb_fullwidth_slider]' );
		$regular = $this->specs( Converter::create(), '[et_pb_slider]' . $slides . '[/et_pb_slider]' );

		$this->assertCount( 1, $full );
		$this->assertCount( 1, $regular );
		$this->assertSame( 'core/group', $full[0]['name'] );
		$this->assertSame( 'full', $full[0]['attributes']['align'] );
		$this->assertArrayNotHasKey( 'align', $regular[0]['attributes'] );
		$this->assertSame( [ 'core/cover', 'core/cover' ], array_column( $full[0]['innerBlocks'], 'name' ) );
	}

	/**
	 * A full-width slider with no slide converts to nothing.
	 *
	 * @return void
	 */
	public function test_fullwidth_slider_without_slides_is_dropped(): void {
		$specs = $this->specs( Converter::create(), '[et_pb_fullwidth_slider][/et_pb_fullwidth_slider]' );

		$this->assertSame( [], $specs );
	}

	/**
	 * A module with an unknown `et_pb_*` tag goes through the fallback rule:
	 * a hidden marker with the non-empty attributes, then its text, then its
	 * child modules. The report counts it as a fallback.
	 *
	 * @return void
	 */
	public function test_unknown_module_goes_through_fallback(): void {
		$converter = Converter::create();
		$specs     = $this->specs( $converter, '[et_pb_lorem_widget title="Lorem" empty=""]Ipsum[et_pb_text]<p>Dolor</p>[/et_pb_text][/et_pb_lorem_widget]' );

		$this->assertSame( 'core/html', $specs[0]['name'] );

		$marker = $specs[0]['attributes']['content'];

		$this->assertStringContainsString( 'data-divi-module="et_pb_lorem_widget"', $marker );
		$this->assertSame( 1, preg_match( '/data-divi-attrs="([^"]*)"/', $marker, $matches ) );
		$this->assertSame( [ 'title' => 'Lorem' ], json_decode( html_entity_decode( $matches[1] ), true ) );
		$this->assertCount( 3, $specs );
		$this->assertSame( 'Ipsum', $specs[1]['raw'] );
		$this->assertSame( 'core/group', $specs[2]['name'] );
		$this->assertSame( "<p>Dolor</p>\n", $specs[2]['innerBlocks'][0]['raw'] );
		$this->assertSame( [ 'fallback' => 1 ], $converter->report()['et_pb_lorem_widget'] );
		$this->assertSame( [ 'converted' => 1 ], $converter->report()['et_pb_text'] );
	}

	/**
	 * A module with an unknown tag and no attributes has a marker with no
	 * attributes element.
	 *
	 * @return void
	 */
	public function test_unknown_module_without_attributes_has_plain_marker(): void {
		$specs = $this->specs( Converter::create(), '[et_pb_lorem_widget][/et_pb_lorem_widget]' );

		$this->assertCount( 1, $specs );
		$this->assertSame( '<div hidden data-divi-module="et_pb_lorem_widget"></div>', $specs[0]['attributes']['content'] );
	}
}
