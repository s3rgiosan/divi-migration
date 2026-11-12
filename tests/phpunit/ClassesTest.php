<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Serializer\Classes;

/**
 * Tests the translation of class names into block classes and attributes.
 */
class ClassesTest extends TestCase {

	/**
	 * A class key replaces the class and keeps the classes the map does not
	 * name.
	 *
	 * @return void
	 */
	public function test_class_key_replaces_the_class(): void {
		$classes = new Classes( [ 'big' => 'is-style-big' ] );

		$this->assertSame( [ 'className' => 'is-style-big' ], $classes->block( [ 'className' => 'big' ] ) );
		$this->assertSame( [ 'className' => 'other is-style-big' ], $classes->block( [ 'className' => 'big other' ] ) );
		$this->assertSame( [ 'className' => 'other' ], $classes->block( [ 'className' => 'other' ] ) );
	}

	/**
	 * A block without a class name stays as it is.
	 *
	 * @return void
	 */
	public function test_block_without_class_name_is_unchanged(): void {
		$classes = new Classes( [ 'big' => 'is-style-big' ] );

		$this->assertSame( [ 'level' => 2 ], $classes->block( [ 'level' => 2 ] ) );
		$this->assertSame( [ 'className' => '  ' ], $classes->block( [ 'className' => '  ' ] ) );
		$this->assertSame( [], $classes->census );
	}

	/**
	 * A class set matches only when the element has all its classes.
	 *
	 * @return void
	 */
	public function test_set_key_needs_every_class_of_the_set(): void {
		$classes = new Classes( [ 'big-button bigpink' => 'is-style-pink' ] );

		$this->assertSame( [ 'className' => 'is-style-pink' ], $classes->block( [ 'className' => 'bigpink big-button' ] ) );
		$this->assertSame( [ 'className' => 'lorem is-style-pink' ], $classes->block( [ 'className' => 'big-button lorem bigpink' ] ) );
		$this->assertSame( [ 'className' => 'big-button' ], $classes->block( [ 'className' => 'big-button' ] ) );
		$this->assertSame(
			[
				'lorem'      => 1,
				'big-button' => 1,
			],
			$classes->census
		);
	}

	/**
	 * The largest class set matches first, and the classes it takes do not
	 * match a smaller set.
	 *
	 * @return void
	 */
	public function test_largest_set_wins(): void {
		$classes = new Classes(
			[
				'one'         => 'is-style-one',
				'one two'     => 'is-style-pair',
				'one two six' => 'is-style-triple',
			]
		);

		$this->assertSame( [ 'className' => 'is-style-triple' ], $classes->block( [ 'className' => 'one two six' ] ) );
		$this->assertSame( [ 'className' => 'is-style-pair' ], $classes->block( [ 'className' => 'one two' ] ) );
		$this->assertSame( [ 'className' => 'is-style-one' ], $classes->block( [ 'className' => 'one' ] ) );
	}

	/**
	 * A pattern key matches each class that fits it.
	 *
	 * @return void
	 */
	public function test_regex_key_matches_classes(): void {
		$classes = new Classes( [ '/^p\d+$/' => 'is-style-padded' ] );

		$this->assertSame( [ 'className' => 'is-style-padded' ], $classes->block( [ 'className' => 'p10' ] ) );
		$this->assertSame( [ 'className' => 'px is-style-padded' ], $classes->block( [ 'className' => 'p5 px' ] ) );
		$this->assertSame( [ 'className' => 'px' ], $classes->block( [ 'className' => 'px' ] ) );
	}

	/**
	 * An empty string drops the class, and a block with no class left has no
	 * class name.
	 *
	 * @return void
	 */
	public function test_empty_string_drops_the_class(): void {
		$classes = new Classes(
			[
				'drop-me' => '',
				'/^x\d$/' => '',
			]
		);

		$this->assertSame(
			[ 'level' => 2 ],
			$classes->block(
				[
					'className' => 'drop-me x1',
					'level'     => 2,
				]
			)
		);
		$this->assertSame( [ 'className' => 'lorem' ], $classes->block( [ 'className' => 'drop-me lorem' ] ) );
		$this->assertSame( 'lorem', $classes->element( 'drop-me lorem x2' ) );
		$this->assertSame( [ 'lorem' => 2 ], $classes->census );
	}

	/**
	 * An object value merges its attributes into the block, with nested
	 * objects merged and its `className` as the replacement classes.
	 *
	 * @return void
	 */
	public function test_object_value_merges_attributes(): void {
		$classes = new Classes(
			[
				'wide-box' => [
					'className' => 'is-style-box',
					'align'     => 'wide',
					'style'     => [ 'spacing' => [ 'padding' => '1rem' ] ],
				],
			]
		);

		$this->assertSame(
			[
				'level'     => 2,
				'style'     => [
					'spacing' => [
						'margin'  => '2rem',
						'padding' => '1rem',
					],
				],
				'align'     => 'wide',
				'className' => 'is-style-box',
			],
			$classes->block(
				[
					'className' => 'wide-box',
					'level'     => 2,
					'style'     => [ 'spacing' => [ 'margin' => '2rem' ] ],
				]
			)
		);
	}

	/**
	 * An object value without a `className`, or an empty object, adds no
	 * class, and the matched class goes away.
	 *
	 * @return void
	 */
	public function test_object_value_without_class_name_drops_the_class(): void {
		$classes = new Classes(
			[
				'centered' => [ 'textAlign' => 'center' ],
				'nothing'  => [],
			]
		);

		$this->assertSame( [ 'textAlign' => 'center' ], $classes->block( [ 'className' => 'centered' ] ) );
		$this->assertSame( [], $classes->block( [ 'className' => 'nothing' ] ) );
	}

	/**
	 * Attributes that a map adds apply to blocks only; an element gets the
	 * classes alone.
	 *
	 * @return void
	 */
	public function test_element_gets_classes_without_attributes(): void {
		$classes = new Classes(
			[
				'wide-box' => [
					'className' => 'is-style-box',
					'align'     => 'wide',
				],
			]
		);

		$this->assertSame( 'is-style-box', $classes->element( 'wide-box' ) );
	}

	/**
	 * A block keeps one block style: the last one stays.
	 *
	 * @return void
	 */
	public function test_block_keeps_one_block_style(): void {
		$classes = new Classes(
			[
				'one' => 'is-style-one',
				'two' => 'is-style-two',
			]
		);

		$this->assertSame( [ 'className' => 'is-style-two' ], $classes->block( [ 'className' => 'one two' ] ) );
		$this->assertSame( [ 'className' => 'lorem is-style-two' ], $classes->block( [ 'className' => 'lorem two one is-style-old' ] ) );
	}

	/**
	 * A mapped block style replaces a block style the element already has,
	 * because the mapped one comes last.
	 *
	 * @return void
	 */
	public function test_mapped_block_style_replaces_existing_block_style(): void {
		$classes = new Classes( [ 'big' => 'is-style-new' ] );

		$this->assertSame( [ 'className' => 'is-style-new' ], $classes->block( [ 'className' => 'is-style-old big' ] ) );
	}

	/**
	 * The census counts the classes the map does not name, except the classes
	 * of the block editor, and adds up the uses.
	 *
	 * @return void
	 */
	public function test_census_counts_only_classes_that_the_editor_does_not_write(): void {
		$classes = new Classes( [ 'mapped' => 'is-style-big' ] );

		$classes->block( [ 'className' => 'wp-block-lorem has-red-color is-style-lorem alignwide alignfull size-large columns-3 mapped lorem ipsum' ] );
		$classes->block( [ 'className' => 'lorem' ] );
		$classes->element( 'lorem alignleft has-text-align-center' );

		$this->assertSame(
			[
				'lorem' => 3,
				'ipsum' => 1,
			],
			$classes->census
		);
	}
}
