<?php

namespace S3S\WP\DiviMigration;

use S3S\WP\DiviMigration\Parser\Node;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Divi's Global Presets to module settings, as Divi does when it
 * renders a module.
 *
 * Each module uses the preset its `_module_preset` names, or its module
 * type's default preset when that preset does not exist. The preset's
 * settings fill the settings the module does not set itself.
 */
class ModulePresets {

	/**
	 * Divi's default preset ID.
	 */
	const INITIAL_PRESET = '_initial';

	/**
	 * Presets per module type.
	 *
	 * @var array<string, array{default: string, presets: array<string, array<string, string>>}>
	 */
	private array $types = [];

	/**
	 * Creates the presets.
	 *
	 * @param array<string, array{default?: string, presets?: array<string, array<string, string>>}> $types Preset settings per preset ID, keyed by module type.
	 */
	public function __construct( array $types = [] ) {
		foreach ( $types as $type => $entry ) {
			$this->types[ $type ] = [
				'default' => (string) ( $entry['default'] ?? self::INITIAL_PRESET ),
				'presets' => $entry['presets'] ?? [],
			];
		}
	}

	/**
	 * Reads the presets from Divi's theme options.
	 *
	 * @return ModulePresets
	 */
	public static function from_options(): ModulePresets {
		$options = get_option( 'et_divi', [] );
		$stored  = is_array( $options ) ? ( $options['builder_global_presets'] ?? null ) : null;
		$types   = [];

		foreach ( (array) $stored as $type => $entry ) {
			$entry   = (array) $entry;
			$presets = [];

			foreach ( (array) ( $entry['presets'] ?? [] ) as $id => $preset ) {
				$settings = (array) ( ( (array) $preset )['settings'] ?? [] );

				// Divi keeps only plain values.
				$presets[ (string) $id ] = array_map( 'strval', array_filter( $settings, 'is_scalar' ) );
			}

			$types[ (string) $type ] = [
				'default' => (string) ( $entry['default'] ?? self::INITIAL_PRESET ),
				'presets' => $presets,
			];
		}

		return new self( $types );
	}

	/**
	 * Adds the preset settings to a node and its descendants.
	 *
	 * @param Node      $node      Node.
	 * @param Node|null $container Parent node, or null for the root.
	 * @return void
	 */
	public function apply( Node $node, ?Node $container = null ): void {
		if ( [] === $this->types ) {
			return;
		}

		if ( 'root' !== $node->tag ) {
			$node->attrs = array_merge( $this->settings( $node, $container ), $node->attrs );
		}

		foreach ( $node->children as $child ) {
			$this->apply( $child, $node );
		}
	}

	/**
	 * Returns the preset settings of a module.
	 *
	 * @param Node      $node      Module node.
	 * @param Node|null $container Parent node, or null.
	 * @return array<string, string>
	 */
	private function settings( Node $node, ?Node $container ): array {
		$entry = $this->types[ self::preset_type( $node, $container ) ] ?? null;

		if ( null === $entry ) {
			return [];
		}

		$id = $node->attr( '_module_preset' );

		if ( '' === $id || ! isset( $entry['presets'][ $id ] ) ) {
			$id = $entry['default'];
		}

		return $entry['presets'][ $id ] ?? [];
	}

	/**
	 * Returns the module type that Divi files a module's presets under.
	 *
	 * @param Node      $node      Module node.
	 * @param Node|null $container Parent node, or null.
	 * @return string
	 */
	private static function preset_type( Node $node, ?Node $container ): string {
		switch ( $node->tag ) {
			case 'et_pb_section':
				if ( $node->is_on( 'fullwidth' ) ) {
					return 'et_pb_section_fullwidth';
				}

				return $node->is_on( 'specialty' ) ? 'et_pb_section_specialty' : 'et_pb_section';

			case 'et_pb_column_inner':
				return 'et_pb_column';

			case 'et_pb_column':
				$is_specialty = '' !== $node->attr( 'specialty_columns' ) || ( null !== $container && $container->is_on( 'specialty' ) );

				return $is_specialty ? 'et_pb_column_specialty' : 'et_pb_column';

			case 'et_pb_slide':
				return null !== $container && 'et_pb_fullwidth_slider' === $container->tag ? 'et_pb_slide_fullwidth' : 'et_pb_slide';

			default:
				return $node->tag;
		}
	}
}
