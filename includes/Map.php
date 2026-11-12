<?php

namespace S3S\WP\DiviMigration;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * The migration map: the project decisions the converter applies to a site.
 *
 * A JSON file, written while the block theme is built, with these keys (all
 * optional):
 *
 * - `gutter`: default Divi gutter width, 1 to 4 (defaults to Divi's option).
 * - `classNames`: class names of the layout roles (`section`, `section_fullwidth`,
 *   `row`, `row_inner`, `text`, `testimonial`), matching the theme's style variations.
 * - `imageSizes`: image size slugs of blog post images, `card` for grid lists and
 *   `wide` for full-width lists (default `post-card` and `post-wide`).
 * - `sidebarPart`: template part slug that replaces sidebar modules.
 * - `targets`: per kind (`carousel`, `accordion`, `table`, `form`), the name of one
 *   of its targets, or `auto`.
 * - `menus`: `classic` or `navigation`.
 * - `emptyParagraphs`: `keep` (default) or `drop` the empty paragraphs that classic
 *   content uses as spacers.
 * - `colors`: palette slug per Divi color (`"#336699": "accent"`), for colors the
 *   theme palette does not hold exactly.
 * - `fonts`: font family slug per Divi font name (`"Open Sans": "body"`).
 * - `modules`: per shortcode tag, `"skip"` to drop the module, or
 *   `{ "markup": "<!-- wp:… -->" }` to put block markup (or HTML) in its place.
 * - `shortcodes`: per shortcode tag, what replaces a shortcode that stands alone
 *   in a paragraph: `"skip"`, `{ "markup": "…" }`, or a list of entries with an
 *   `attrs` object the shortcode's attributes must match (`{ "attrs":
 *   { "optin_id": "optin_4" }, "markup": "…" }`). An entry with `"skip": true`
 *   drops the shortcodes it matches.
 * - `classes`: per class, set of classes (`"big-button bigpink"`) or pattern
 *   (`"/^p\\d+$/"`), the classes that replace it (`"is-style-big"`, `""` to drop
 *   it), or block attributes to merge with an optional `className` (see
 *   `Serializer\Classes`).
 */
final class Map {

	/**
	 * Keys the map accepts.
	 */
	const KEYS = [ 'gutter', 'classNames', 'imageSizes', 'sidebarPart', 'targets', 'menus', 'emptyParagraphs', 'colors', 'fonts', 'modules', 'shortcodes', 'classes' ];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>        $settings Settings: `gutter`, `classNames`, `imageSizes`, `sidebarPart`, `targets`, `menus`.
	 * @param array<string, string>       $colors   Palette slugs keyed by lowercase color.
	 * @param array<string, string>       $fonts    Font family slugs keyed by lowercase font name.
	 * @param array<string, string|array> $modules    Module actions keyed by shortcode tag.
	 * @param array<string, string|array> $shortcodes Shortcode replacements keyed by tag.
	 * @param array<string, string|array> $classes    Class replacements keyed by class, class set or pattern.
	 */
	public function __construct(
		public array $settings = [],
		public array $colors = [],
		public array $fonts = [],
		public array $modules = [],
		public array $shortcodes = [],
		public array $classes = [],
	) {}

	/**
	 * Reads a map from a JSON file.
	 *
	 * @param string $path File path.
	 * @return Map|WP_Error
	 */
	public static function from_file( string $path ): Map|WP_Error {
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 's3s_divi_migration_map', sprintf( 'Cannot read the map file %s.', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$data = json_decode( (string) file_get_contents( $path ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map file %s is not a JSON object.', $path ) );
		}

		return self::from_array( $data );
	}

	/**
	 * Creates a map from decoded JSON.
	 *
	 * @param array $data Map data.
	 * @return Map|WP_Error
	 */
	public static function from_array( array $data ): Map|WP_Error {
		$unknown = array_diff( array_keys( $data ), self::KEYS );

		if ( [] !== $unknown ) {
			return new WP_Error( 's3s_divi_migration_map', sprintf( 'Unknown map keys: %s.', implode( ', ', $unknown ) ) );
		}

		foreach ( [ 'classNames', 'imageSizes', 'targets', 'colors', 'fonts', 'modules', 'shortcodes', 'classes' ] as $key ) {
			if ( isset( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key %s must be an object.', $key ) );
			}
		}

		if ( isset( $data['gutter'] ) && ( ! is_int( $data['gutter'] ) || $data['gutter'] < 1 || $data['gutter'] > 4 ) ) {
			return new WP_Error( 's3s_divi_migration_map', 'The map key gutter must be a whole number from 1 to 4.' );
		}

		foreach ( $data['classNames'] ?? [] as $role => $class_name ) {
			if ( ! is_string( $class_name ) ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key classNames needs a string value for %s.', $role ) );
			}
		}

		foreach ( $data['imageSizes'] ?? [] as $role => $image_size ) {
			if ( ! in_array( $role, [ 'card', 'wide' ], true ) ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key imageSizes accepts card and wide, not %s.', $role ) );
			}

			if ( ! is_string( $image_size ) || '' === $image_size ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key imageSizes needs a non-empty string value for %s.', $role ) );
			}
		}

		if ( isset( $data['sidebarPart'] ) && ! is_string( $data['sidebarPart'] ) ) {
			return new WP_Error( 's3s_divi_migration_map', 'The map key sidebarPart must be a string.' );
		}

		$targets = self::validate_targets( $data['targets'] ?? [] );

		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		if ( isset( $data['emptyParagraphs'] ) && ! in_array( $data['emptyParagraphs'], [ 'keep', 'drop' ], true ) ) {
			return new WP_Error( 's3s_divi_migration_map', 'The map key emptyParagraphs must be keep or drop.' );
		}

		if ( isset( $data['menus'] ) && ! in_array( $data['menus'], [ 'classic', 'navigation' ], true ) ) {
			return new WP_Error( 's3s_divi_migration_map', 'The map key menus must be classic or navigation.' );
		}

		foreach ( $data['modules'] ?? [] as $tag => $action ) {
			if ( 'skip' !== $action && ! is_string( $action['markup'] ?? null ) ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The module %s needs "skip" or { "markup": "…" }.', $tag ) );
			}
		}

		foreach ( $data['shortcodes'] ?? [] as $tag => $entries ) {
			foreach ( is_array( $entries ) && array_is_list( $entries ) ? $entries : [ $entries ] as $entry ) {
				$valid = 'skip' === $entry || ( is_array( $entry ) && ( is_string( $entry['markup'] ?? null ) || ! empty( $entry['skip'] ) ) && is_array( $entry['attrs'] ?? [] ) );

				if ( ! $valid ) {
					return new WP_Error( 's3s_divi_migration_map', sprintf( 'The shortcode %s needs "skip", { "markup": "…" } or a list of them, each with an optional "attrs" object.', $tag ) );
				}
			}
		}

		foreach ( [ 'colors', 'fonts' ] as $key ) {
			foreach ( $data[ $key ] ?? [] as $name => $slug ) {
				if ( ! is_string( $slug ) ) {
					return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key %s needs a string value for %s.', $key, $name ) );
				}
			}
		}

		$classes = [];

		foreach ( $data['classes'] ?? [] as $key => $value ) {
			$key = trim( (string) $key );

			if ( '' === $key ) {
				return new WP_Error( 's3s_divi_migration_map', 'The map key classes cannot have an empty key.' );
			}

			// `{}` decodes to an empty array: an object without attributes.
			$valid = is_string( $value ) || ( is_array( $value ) && ( [] === $value || ! array_is_list( $value ) ) && is_string( $value['className'] ?? '' ) );

			if ( ! $valid ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The class %s needs a string of classes or an object of block attributes.', $key ) );
			}

			if ( str_starts_with( $key, '/' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A bad pattern returns false; the error names it.
				$compiled = @preg_match( $key, '' );

				if ( false === $compiled ) {
					return new WP_Error( 's3s_divi_migration_map', sprintf( 'The class pattern %s is not a valid regular expression.', $key ) );
				}
			}

			$classes[ $key ] = $value;
		}

		return new self(
			settings: array_intersect_key( $data, array_flip( [ 'gutter', 'classNames', 'imageSizes', 'sidebarPart', 'targets', 'menus', 'emptyParagraphs' ] ) ),
			colors: array_change_key_case( $data['colors'] ?? [] ),
			fonts: array_change_key_case( $data['fonts'] ?? [] ),
			modules: $data['modules'] ?? [],
			shortcodes: $data['shortcodes'] ?? [],
			classes: $classes,
		);
	}

	/**
	 * Returns what the map does with a module: `skip`, block markup to put in
	 * its place, or null to convert it.
	 *
	 * @param string $tag Shortcode tag.
	 * @return string|null `skip`, markup, or null.
	 */
	public function module( string $tag ): ?string {
		$action = $this->modules[ $tag ] ?? null;

		if ( 'skip' === $action ) {
			return 'skip';
		}

		return is_array( $action ) && is_string( $action['markup'] ?? null ) ? $action['markup'] : null;
	}

	/**
	 * Checks the map's `targets`: each kind must be a kind that has targets,
	 * and each value `auto` or the name of one of its targets, the filtered
	 * ones included.
	 *
	 * @param array $targets Target name per kind.
	 * @return true|WP_Error
	 */
	private static function validate_targets( array $targets ): true|WP_Error {
		$registry = new Targets\Targets();

		foreach ( $targets as $kind => $name ) {
			if ( ! is_string( $name ) ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key targets needs a string value for %s.', $kind ) );
			}

			$names = $registry->names( (string) $kind );

			if ( null === $names ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key targets names an unknown kind: %s.', $kind ) );
			}

			if ( 'auto' !== $name && ! in_array( $name, $names, true ) ) {
				return new WP_Error( 's3s_divi_migration_map', sprintf( 'The map key targets needs auto or one of %s for %s, not %s.', implode( ', ', $names ), $kind, $name ) );
			}
		}

		return true;
	}
}
