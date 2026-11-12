<?php

namespace S3S\WP\DiviMigration\Targets;

use WP_Block_Type_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Picks the target a module type converts into.
 *
 * Each kind (`carousel`, `accordion`, `table`, `form`) lists targets by name, in order of
 * preference. A named target in the settings wins; with `auto`, the first
 * target whose blocks are all registered is used, so a site without the
 * preferred block plugin falls back to core blocks.
 */
class Targets {

	/**
	 * Targets per kind, keyed by name, in order of preference.
	 *
	 * @var array<string, array<string, Target>>
	 */
	private array $targets;

	/**
	 * Creates the registry with the default targets and the filtered additions.
	 *
	 * @param array<string, string> $preferences Target name per kind, or `auto`.
	 */
	public function __construct( private array $preferences = [] ) {
		$targets = [
			'carousel'  => [
				'outstand-carousel' => new OutstandCarousel(),
				'core-cover'        => new CoreCover(),
			],
			'accordion' => [
				'outstand-accordion' => new OutstandAccordion(),
				'core-accordion'     => new CoreAccordion(),
			],
			// Core first: the Flexible Table block is used only when a setting picks it.
			'table'     => [
				'core-table'     => new CoreTable(),
				'flexible-table' => new FlexibleTable(),
			],
			'form'      => [
				'ws-form' => new WsForm(),
			],
		];

		/**
		 * Filters the targets per kind.
		 *
		 * Add a target for another block plugin, or reorder the defaults. Keys
		 * are kinds (`carousel`, `accordion`, `table`, `form`); values map target
		 * names to objects implementing `CarouselTarget`, `AccordionTarget`,
		 * `TableTarget` or `FormTarget`, in order of preference for `auto`.
		 *
		 * @param array<string, array<string, Target>> $targets Targets per kind.
		 */
		$this->targets = apply_filters( 's3s_divi_migration_targets', $targets );
	}

	/**
	 * Returns the names of a kind's targets, in order of preference, the
	 * filtered targets included.
	 *
	 * @param string $kind Kind, such as `carousel`.
	 * @return string[]|null Target names, or null when the kind is unknown.
	 */
	public function names( string $kind ): ?array {
		$targets = $this->targets[ $kind ] ?? null;

		if ( ! is_array( $targets ) ) {
			return null;
		}

		return array_map( 'strval', array_keys( $targets ) );
	}

	/**
	 * Returns the target for a kind and its name.
	 *
	 * @param string $kind Kind, such as `carousel`.
	 * @return array{0: string, 1: Target}|null Name and target, or null when the kind has none.
	 */
	public function resolve( string $kind ): ?array {
		$targets = $this->targets[ $kind ] ?? [];

		if ( [] === $targets ) {
			return null;
		}

		$preference = $this->preferences[ $kind ] ?? 'auto';

		if ( isset( $targets[ $preference ] ) ) {
			return [ $preference, $targets[ $preference ] ];
		}

		foreach ( $targets as $name => $target ) {
			if ( $this->is_available( $target ) ) {
				return [ $name, $target ];
			}
		}

		$name = array_key_last( $targets );

		return [ $name, $targets[ $name ] ];
	}

	/**
	 * Whether all of a target's blocks are registered.
	 *
	 * @param Target $target Target.
	 * @return bool
	 */
	public function is_available( Target $target ): bool {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( $target->blocks() as $block ) {
			if ( ! $registry->is_registered( $block ) ) {
				return false;
			}
		}

		return true;
	}
}
