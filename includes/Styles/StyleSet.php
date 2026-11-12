<?php

namespace S3S\WP\DiviMigration\Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Collects a block's `style` attribute, with its viewport and hover states.
 *
 * Desktop values go in the root of `style`. Tablet and phone values go in
 * `style['@tablet']` and `style['@mobile']`, and hover values in
 * `style[':hover']`, which WordPress 7.1 renders as scoped rules.
 */
class StyleSet {

	/**
	 * Styles per state: `desktop`, `tablet`, `phone` and `hover`.
	 *
	 * @var array<string, array>
	 */
	private array $states = [
		'desktop' => [],
		'tablet'  => [],
		'phone'   => [],
		'hover'   => [],
	];

	/**
	 * Sets a value for every viewport that has one.
	 *
	 * A tablet or phone value equal to the desktop value is dropped, since
	 * the desktop value already applies there.
	 *
	 * @param string[]              $path   Path inside `style`, such as `[ 'spacing', 'padding', 'top' ]`.
	 * @param array<string, string> $values Values keyed by `desktop`, `tablet` and `phone`.
	 * @return void
	 */
	public function set( array $path, array $values ): void {
		$desktop = $values['desktop'] ?? '';

		foreach ( [ 'desktop', 'tablet', 'phone' ] as $viewport ) {
			$value = $values[ $viewport ] ?? '';

			if ( '' === $value || ( 'desktop' !== $viewport && $value === $desktop ) ) {
				continue;
			}

			self::assign( $this->states[ $viewport ], $path, $value );
		}
	}

	/**
	 * Sets a desktop value.
	 *
	 * @param string[] $path  Path inside `style`.
	 * @param mixed    $value Value.
	 * @return void
	 */
	public function set_value( array $path, $value ): void {
		if ( '' === $value || null === $value ) {
			return;
		}

		self::assign( $this->states['desktop'], $path, $value );
	}

	/**
	 * Sets a hover value.
	 *
	 * @param string[] $path  Path inside `style`.
	 * @param string   $value Value.
	 * @return void
	 */
	public function set_hover( array $path, string $value ): void {
		if ( '' === $value ) {
			return;
		}

		self::assign( $this->states['hover'], $path, $value );
	}

	/**
	 * Whether no value was set.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return [] === array_filter( $this->states );
	}

	/**
	 * Returns the `style` attribute.
	 *
	 * @return array
	 */
	public function to_style(): array {
		$style = $this->states['desktop'];

		if ( [] !== $this->states['tablet'] ) {
			$style['@tablet'] = $this->states['tablet'];
		}

		if ( [] !== $this->states['phone'] ) {
			$style['@mobile'] = $this->states['phone'];
		}

		if ( [] !== $this->states['hover'] ) {
			$style[':hover'] = $this->states['hover'];
		}

		return $style;
	}

	/**
	 * Sets a nested value in an array.
	 *
	 * @param array    $target Array to change.
	 * @param string[] $path   Keys, outermost first.
	 * @param mixed    $value  Value.
	 * @return void
	 */
	private static function assign( array &$target, array $path, $value ): void {
		$cursor = &$target;

		foreach ( $path as $key ) {
			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = [];
			}

			$cursor = &$cursor[ $key ];
		}

		$cursor = $value;
	}
}
