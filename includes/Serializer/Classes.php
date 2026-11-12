<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

/**
 * Translates the class names that classic and Divi content carry into the
 * classes and attributes of the block editor.
 *
 * The map (`classes` in the migration map) keys a class, a set of classes
 * that must all be present (`"big-button bigpink"`), or a pattern between
 * slashes that one class must match (`"/^p\d+$/"`). Each value is:
 *
 * - a string of classes that replace the matched ones, such as a block style
 *   (`is-style-big`); an empty string drops them;
 * - an object of block attributes to merge into the block, with an optional
 *   `className` string of replacement classes.
 *
 * A block keeps one block style: when several `is-style-*` classes result, the
 * last one stays. Classes the map does not name stay, and the census counts
 * those that are not block editor classes.
 */
final class Classes {

	/**
	 * Classes the map does not name, with their number of uses.
	 *
	 * @var array<string, int>
	 */
	public array $census = [];

	/**
	 * Class set entries, the largest sets first.
	 *
	 * @var array<int, array{0: string[], 1: string|array}>
	 */
	private array $sets = [];

	/**
	 * Pattern entries.
	 *
	 * @var array<string, string|array>
	 */
	private array $patterns = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, string|array> $map Replacements keyed by class, class set or pattern.
	 */
	public function __construct( array $map = [] ) {
		foreach ( $map as $key => $value ) {
			$key = trim( (string) $key );

			if ( str_starts_with( $key, '/' ) ) {
				$this->patterns[ $key ] = $value;
				continue;
			}

			$set = self::split( $key );

			if ( [] !== $set ) {
				$this->sets[] = [ $set, $value ];
			}
		}

		usort( $this->sets, fn( array $a, array $b ) => count( $b[0] ) <=> count( $a[0] ) );
	}

	/**
	 * Returns block attributes with their `className` translated, and the
	 * attributes the map adds merged in.
	 *
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	public function block( array $attributes ): array {
		$class_name = trim( (string) ( $attributes['className'] ?? '' ) );

		if ( '' === $class_name ) {
			return $attributes;
		}

		[ $classes, $added ] = $this->translate( $class_name );

		unset( $attributes['className'] );

		$attributes = self::merge( $attributes, $added );

		if ( '' !== $classes ) {
			$attributes['className'] = $classes;
		}

		return $attributes;
	}

	/**
	 * Returns an element's class attribute translated. Attributes the map adds
	 * apply to blocks only, so their classes alone are used here.
	 *
	 * @param string $class_name Class attribute.
	 * @return string
	 */
	public function element( string $class_name ): string {
		return $this->translate( $class_name )[0];
	}

	/**
	 * Returns the translated classes and the attributes to merge.
	 *
	 * @param string $class_name Class names.
	 * @return array{0: string, 1: array}
	 */
	private function translate( string $class_name ): array {
		$classes = self::split( $class_name );
		$added   = [];
		$extra   = [];

		foreach ( $this->sets as [ $set, $value ] ) {
			$missing = array_diff( $set, $classes );

			if ( [] !== $missing ) {
				continue;
			}

			$classes = array_values( array_diff( $classes, $set ) );

			self::collect( $value, $extra, $added );
		}

		$kept = [];

		foreach ( $classes as $class ) {
			$value = $this->pattern( $class );

			if ( null === $value ) {
				$kept[] = $class;
				continue;
			}

			self::collect( $value, $extra, $added );
		}

		foreach ( $kept as $class ) {
			if ( ! self::is_editor_class( $class ) ) {
				$this->census[ $class ] = ( $this->census[ $class ] ?? 0 ) + 1;
			}
		}

		return [ implode( ' ', self::one_style( array_values( array_unique( [ ...$kept, ...$extra ] ) ) ) ), $added ];
	}

	/**
	 * Returns the value of the first pattern a class matches, or null.
	 *
	 * @param string $class_name Class.
	 * @return string|array|null
	 */
	private function pattern( string $class_name ): string|array|null {
		foreach ( $this->patterns as $pattern => $value ) {
			$matches = @preg_match( $pattern, $class_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- The map validates patterns; a bad one matches nothing.

			if ( 1 === $matches ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Adds a map value's classes and attributes.
	 *
	 * @param string|array $value   Map value.
	 * @param string[]     $classes Classes to add.
	 * @param array        $added   Attributes to merge.
	 * @return void
	 */
	private static function collect( string|array $value, array &$classes, array &$added ): void {
		if ( is_string( $value ) ) {
			array_push( $classes, ...self::split( $value ) );
			return;
		}

		array_push( $classes, ...self::split( (string) ( $value['className'] ?? '' ) ) );

		unset( $value['className'] );

		$added = self::merge( $added, $value );
	}

	/**
	 * Keeps the last block style class of a list.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	private static function one_style( array $classes ): array {
		$styles = array_filter( $classes, fn( string $class_name ) => str_starts_with( $class_name, 'is-style-' ) );

		$style_count = count( $styles );

		if ( $style_count < 2 ) {
			return $classes;
		}

		$last = end( $styles );

		return array_values( array_filter( $classes, fn( string $class_name ) => ! str_starts_with( $class_name, 'is-style-' ) || $class_name === $last ) );
	}

	/**
	 * Whether a class is one the block editor or core writes.
	 *
	 * @param string $class_name Class.
	 * @return bool
	 */
	private static function is_editor_class( string $class_name ): bool {
		return 1 === preg_match( '/^(wp-|has-|is-|align(left|right|center|wide|full|none)$|size-|columns-\d+$)/', $class_name );
	}

	/**
	 * Splits class names.
	 *
	 * @param string $class_name Class names.
	 * @return string[]
	 */
	private static function split( string $class_name ): array {
		return array_values( array_unique( preg_split( '/\s+/', trim( $class_name ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] ) );
	}

	/**
	 * Merges attributes; nested objects merge, other values replace.
	 *
	 * @param array $target Base attributes.
	 * @param array $source Attributes to merge in.
	 * @return array
	 */
	private static function merge( array $target, array $source ): array {
		foreach ( $source as $key => $value ) {
			$target[ $key ] = is_array( $value ) && ! array_is_list( $value ) && is_array( $target[ $key ] ?? null ) && ! array_is_list( $target[ $key ] )
				? self::merge( $target[ $key ], $value )
				: $value;
		}

		return $target;
	}
}
