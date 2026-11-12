<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Map;
use S3S\WP\DiviMigration\Settings;
use WP_UnitTestCase;

/**
 * Tests the image size slugs of blog post images.
 */
class ImageSizesTest extends WP_UnitTestCase {

	/**
	 * Returns the size slugs of the featured image blocks in a blog module.
	 *
	 * @param Converter $converter Converter to use.
	 * @param string    $content   Divi content.
	 * @return string[]
	 */
	private function size_slugs( Converter $converter, string $content ): array {
		return array_column( array_column( $this->find( $converter->convert_content( $content ), 'core/post-featured-image' ), 'attributes' ), 'sizeSlug' );
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
	 * A grid list uses the card size and a full-width list the wide size.
	 *
	 * @return void
	 */
	public function test_blog_uses_neutral_size_slugs(): void {
		$converter = Converter::create();

		$this->assertSame( [ 'post-card' ], $this->size_slugs( $converter, '[et_pb_blog fullwidth="off"][/et_pb_blog]' ) );
		$this->assertSame( [ 'post-wide' ], $this->size_slugs( $converter, '[et_pb_blog fullwidth="on"][/et_pb_blog]' ) );
	}

	/**
	 * The settings set the size slugs.
	 *
	 * @return void
	 */
	public function test_blog_size_slugs_come_from_settings(): void {
		$converter = Converter::create();

		$converter->settings->image_sizes['card'] = 'lorem-card';
		$converter->settings->image_sizes['wide'] = 'lorem-wide';

		$this->assertSame( [ 'lorem-card' ], $this->size_slugs( $converter, '[et_pb_blog fullwidth="off"][/et_pb_blog]' ) );
		$this->assertSame( [ 'lorem-wide' ], $this->size_slugs( $converter, '[et_pb_blog fullwidth="on"][/et_pb_blog]' ) );
	}

	/**
	 * The map sets the size slugs and rejects values that are not strings.
	 *
	 * @return void
	 */
	public function test_map_image_sizes(): void {
		$map = Map::from_array( [ 'imageSizes' => [ 'card' => 'lorem-card' ] ] );

		$this->assertInstanceOf( Map::class, $map );
		$this->assertSame( [ 'card' => 'lorem-card' ], $map->settings['imageSizes'] );

		$invalid = Map::from_array( [ 'imageSizes' => [ 'card' => 5 ] ] );

		$this->assertWPError( $invalid );
		$this->assertSame( 'The map key imageSizes needs a non-empty string value for card.', $invalid->get_error_message() );

		$not_object = Map::from_array( [ 'imageSizes' => 'lorem' ] );

		$this->assertWPError( $not_object );
		$this->assertSame( 'The map key imageSizes must be an object.', $not_object->get_error_message() );

		$empty = Map::from_array( [ 'imageSizes' => [ 'wide' => '' ] ] );

		$this->assertWPError( $empty );
		$this->assertSame( 'The map key imageSizes needs a non-empty string value for wide.', $empty->get_error_message() );

		$unknown = Map::from_array( [ 'imageSizes' => [ 'thumb' => 'lorem-thumb' ] ] );

		$this->assertWPError( $unknown );
		$this->assertSame( 'The map key imageSizes accepts card and wide, not thumb.', $unknown->get_error_message() );
	}

	/**
	 * Settings read the size slugs from a map, and keep the neutral slug of
	 * a role the map leaves out.
	 *
	 * @return void
	 */
	public function test_settings_read_image_sizes_from_map(): void {
		$map = Map::from_array( [ 'imageSizes' => [ 'card' => 'lorem-card' ] ] );

		$this->assertInstanceOf( Map::class, $map );

		$settings = Settings::from_options( $map );

		$this->assertSame( 'lorem-card', $settings->image_size( 'card' ) );
		$this->assertSame( 'post-wide', $settings->image_size( 'wide' ) );
	}
}
