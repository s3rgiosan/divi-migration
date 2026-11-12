<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Targets\CoreCover;
use S3S\WP\DiviMigration\Targets\CoreTable;
use S3S\WP\DiviMigration\Targets\FlexibleTable;
use S3S\WP\DiviMigration\Targets\OutstandCarousel;
use S3S\WP\DiviMigration\Targets\Target;
use S3S\WP\DiviMigration\Targets\Targets;
use S3S\WP\DiviMigration\Targets\WsForm;

/**
 * Tests how the registry picks the target of each kind.
 */
class TargetsTest extends TestCase {

	/**
	 * The default targets are listed per kind, in order of preference.
	 *
	 * @return void
	 */
	public function test_names_lists_the_default_targets(): void {
		$targets = new Targets();

		$this->assertSame( [ 'outstand-carousel', 'core-cover' ], $targets->names( 'carousel' ) );
		$this->assertSame( [ 'outstand-accordion', 'core-accordion' ], $targets->names( 'accordion' ) );
		$this->assertSame( [ 'core-table', 'flexible-table' ], $targets->names( 'table' ) );
		$this->assertSame( [ 'ws-form' ], $targets->names( 'form' ) );
		$this->assertNull( $targets->names( 'bogus' ) );
	}

	/**
	 * A named preference wins, also when the target's blocks are not
	 * registered.
	 *
	 * @return void
	 */
	public function test_resolve_returns_the_named_target(): void {
		$resolved = ( new Targets( [ 'carousel' => 'core-cover' ] ) )->resolve( 'carousel' );

		$this->assertSame( 'core-cover', $resolved[0] );
		$this->assertInstanceOf( CoreCover::class, $resolved[1] );

		$resolved = ( new Targets( [ 'table' => 'flexible-table' ] ) )->resolve( 'table' );

		$this->assertSame( 'flexible-table', $resolved[0] );
		$this->assertInstanceOf( FlexibleTable::class, $resolved[1] );
		$this->assertFalse( ( new Targets() )->is_available( $resolved[1] ) );

		$resolved = ( new Targets( [ 'carousel' => 'outstand-carousel' ] ) )->resolve( 'carousel' );

		$this->assertSame( 'outstand-carousel', $resolved[0] );
		$this->assertInstanceOf( OutstandCarousel::class, $resolved[1] );
	}

	/**
	 * With `auto`, or without a preference, the first target whose blocks
	 * are all registered wins.
	 *
	 * @return void
	 */
	public function test_resolve_auto_picks_the_first_registered_target(): void {
		foreach ( [ [], [ 'table' => 'auto' ], [ 'table' => 'nope' ] ] as $preferences ) {
			$resolved = ( new Targets( $preferences ) )->resolve( 'table' );

			$this->assertSame( 'core-table', $resolved[0] );
			$this->assertInstanceOf( CoreTable::class, $resolved[1] );
		}

		$this->set_targets(
			[
				'carousel' => [
					'first'  => $this->target( [ 'example/unregistered' ] ),
					'second' => $this->target( [ 'core/paragraph' ] ),
					'third'  => $this->target( [ 'core/paragraph' ] ),
				],
			]
		);

		$this->assertSame( 'second', ( new Targets() )->resolve( 'carousel' )[0] );
	}

	/**
	 * When the preferred target's blocks are registered, auto picks it.
	 *
	 * @return void
	 */
	public function test_resolve_auto_prefers_the_first_target_when_its_blocks_are_registered(): void {
		$this->set_targets(
			[
				'carousel' => [
					'first'  => $this->target( [ 'core/paragraph', 'core/group' ] ),
					'second' => $this->target( [ 'core/paragraph' ] ),
				],
			]
		);

		$this->assertSame( 'first', ( new Targets() )->resolve( 'carousel' )[0] );
	}

	/**
	 * When no target is available, the last target is the fallback.
	 *
	 * @return void
	 */
	public function test_resolve_falls_back_to_the_last_target(): void {
		$last = $this->target( [ 'example/also-unregistered' ] );

		$this->set_targets(
			[
				'carousel' => [
					'first' => $this->target( [ 'example/unregistered' ] ),
					'last'  => $last,
				],
			]
		);

		$resolved = ( new Targets() )->resolve( 'carousel' );

		$this->assertSame( 'last', $resolved[0] );
		$this->assertSame( $last, $resolved[1] );
	}

	/**
	 * A kind without targets, or an unknown kind, resolves to null.
	 *
	 * @return void
	 */
	public function test_resolve_returns_null_without_targets(): void {
		$this->set_targets( [ 'carousel' => [] ] );

		$targets = new Targets();

		$this->assertNull( $targets->resolve( 'carousel' ) );
		$this->assertNull( $targets->resolve( 'bogus' ) );
		$this->assertSame( [], $targets->names( 'carousel' ) );
	}

	/**
	 * A target that a filter adds is listed, resolved by name, and picked by
	 * `auto` when it comes first and its blocks are registered.
	 *
	 * @return void
	 */
	public function test_filter_adds_a_target(): void {
		$added = $this->target( [ 'core/paragraph' ] );

		add_filter(
			's3s_divi_migration_targets',
			function ( array $targets ) use ( $added ) {
				$targets['form'] = array_merge( [ 'lorem-form' => $added ], $targets['form'] );

				return $targets;
			}
		);

		$targets = new Targets();

		$this->assertSame( [ 'lorem-form', 'ws-form' ], $targets->names( 'form' ) );
		$this->assertSame( [ 'lorem-form', $added ], $targets->resolve( 'form' ) );
		$this->assertSame( 'ws-form', ( new Targets( [ 'form' => 'ws-form' ] ) )->resolve( 'form' )[0] );
		$this->assertInstanceOf( WsForm::class, ( new Targets( [ 'form' => 'ws-form' ] ) )->resolve( 'form' )[1] );
	}

	/**
	 * A target is available when all its blocks are registered, and a target
	 * without blocks is available.
	 *
	 * @return void
	 */
	public function test_is_available_needs_every_block(): void {
		$targets = new Targets();

		$this->assertTrue( $targets->is_available( new CoreTable() ) );
		$this->assertTrue( $targets->is_available( $this->target( [] ) ) );
		$this->assertTrue( $targets->is_available( $this->target( [ 'core/paragraph', 'core/group' ] ) ) );
		$this->assertFalse( $targets->is_available( $this->target( [ 'core/paragraph', 'example/unregistered' ] ) ) );
		$this->assertFalse( $targets->is_available( $this->target( [ 'example/unregistered' ] ) ) );
	}

	/**
	 * Replaces the targets with a list.
	 *
	 * @param array<string, array<string, Target>> $replacement Targets per kind.
	 * @return void
	 */
	private function set_targets( array $replacement ): void {
		add_filter( 's3s_divi_migration_targets', fn() => $replacement );
	}

	/**
	 * Returns a target that needs blocks.
	 *
	 * @param string[] $blocks Block names.
	 * @return Target
	 */
	private function target( array $blocks ): Target {
		return new class( $blocks ) implements Target {

			/**
			 * Constructor.
			 *
			 * @param string[] $blocks Block names.
			 */
			public function __construct( private array $blocks ) {}

			/**
			 * {@inheritDoc}
			 *
			 * @return string[]
			 */
			public function blocks(): array {
				return $this->blocks;
			}
		};
	}
}
