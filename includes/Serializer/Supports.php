<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

use WP_Block_Type;

/**
 * Returns the class names and inline styles that block supports add to a
 * block's saved markup.
 *
 * Styles that a block support renders on the server (background, aspect
 * ratio, block gap, per-viewport and state styles, element styles) stay out
 * of the saved markup.
 */
final class Supports {

	/**
	 * Style groups, keyed by the support that may skip their serialization.
	 */
	const STYLE_GROUPS = [
		'color'      => 'color',
		'typography' => 'typography',
		'border'     => 'border',
		'spacing'    => 'spacing',
		'dimensions' => 'dimensions',
		'shadow'     => 'shadow',
	];

	/**
	 * Support names that mean the block has style support.
	 */
	const STYLE_SUPPORTS = [ 'color', 'typography', 'border', '__experimentalBorder', 'spacing', 'dimensions', 'shadow', 'background' ];

	/**
	 * Text alignments the text align support writes as a class.
	 */
	const TEXT_ALIGNMENTS = [ 'left', 'center', 'right' ];

	/**
	 * Constructor.
	 *
	 * @param WP_Block_Type $type Block type.
	 */
	public function __construct( private WP_Block_Type $type ) {}

	/**
	 * Returns the wrapper attributes: the save function's own attributes with
	 * the block supports' class names, inline styles, anchor and label.
	 *
	 * @param array $attributes Block attributes.
	 * @param array $props      Attributes the save function sets on the wrapper: `class` (string),
	 *                          `style` (CSS declarations) and any other HTML attribute.
	 * @return array HTML attributes; `style` holds CSS declarations.
	 */
	public function props( array $attributes, array $props = [] ): array {
		$classes = [];

		$class_name_support = $this->support( 'className', true );

		if ( false !== $class_name_support ) {
			$classes[] = 'wp-block-' . str_replace( '/', '-', preg_replace( '#^core/#', '', $this->type->name ) );
		}

		$classes[] = $props['class'] ?? '';

		$text_align = $attributes['style']['typography']['textAlign'] ?? '';

		if ( in_array( $text_align, $this->text_alignments(), true ) && ! $this->skips( 'typography', 'textAlign' ) ) {
			$classes[] = 'has-text-align-' . $text_align;
		}

		$align = (string) ( $attributes['align'] ?? '' );

		if ( '' !== $align && in_array( $align, $this->alignments(), true ) ) {
			$classes[] = 'align' . $align;
		}

		if ( ! $this->skips( 'color' ) && $this->has_color_support() ) {
			$classes[] = $this->color_classes( $attributes );
		}

		if ( ! $this->skips( 'typography' ) ) {
			$classes[] = $this->typography_classes( $attributes );
		}

		if ( ! $this->skips( 'border', 'color' ) && $this->support( 'border.color' ) ) {
			$classes[] = $this->border_classes( $attributes );
		}

		if ( ! empty( $attributes['fitText'] ) && $this->support( 'typography.fitText' ) ) {
			$classes[] = 'has-fit-text';
		}

		$css = trim( (string) ( $attributes['style']['css'] ?? '' ) );

		$custom_css_support   = $this->support( 'customCSS', true );
		$custom_class_support = $this->support( 'customClassName', true );

		if ( '' !== $css && false !== $custom_css_support ) {
			$classes[] = 'has-custom-css';
		}

		if ( ! empty( $attributes['className'] ) && false !== $custom_class_support ) {
			$classes[] = $attributes['className'];
		}

		$style = array_merge( $this->styles( $attributes ), $props['style'] ?? [] );

		unset( $props['class'], $props['style'] );

		$wrapper = array_merge(
			$props,
			[
				'class' => Html::classes( $classes ),
				'style' => $style,
			]
		);

		if ( $this->support( 'anchor' ) && ! empty( $attributes['anchor'] ) ) {
			$wrapper['id'] = $attributes['anchor'];
		}

		if ( $this->support( 'ariaLabel' ) && ! empty( $attributes['ariaLabel'] ) ) {
			$wrapper['aria-label'] = $attributes['ariaLabel'];
		}

		return $wrapper;
	}

	/**
	 * Returns the inline styles of the given style groups, whether or not the
	 * block skips their serialization on the wrapper. Blocks such as Button
	 * and Image write these on an inner element.
	 *
	 * @param array    $attributes Block attributes.
	 * @param string[] $groups     Style groups (`color`, `border`, `typography`, …).
	 * @return array<string, string> CSS declarations.
	 */
	public function group_styles( array $attributes, array $groups ): array {
		$style = array_intersect_key( (array) ( $attributes['style'] ?? [] ), array_flip( $groups ) );

		return $this->declarations( $style );
	}

	/**
	 * Returns the color classes for a block's attributes.
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $all        Whether to ignore the support's skip serialization setting, for
	 *                          blocks that write these classes on an inner element.
	 * @return string
	 */
	public function color_classes( array $attributes, bool $all = false ): string {
		$style = $attributes['style']['color'] ?? [];
		$keeps = fn( string $feature ) => $all || ! $this->skips( 'color', $feature );

		$text_color = $keeps( 'text' ) ? (string) ( $attributes['textColor'] ?? '' ) : '';
		$background = $keeps( 'background' ) ? (string) ( $attributes['backgroundColor'] ?? '' ) : '';
		$gradient   = $keeps( 'gradients' ) ? (string) ( $attributes['gradient'] ?? '' ) : '';

		// Inner elements drop the background class for any gradient; a block wrapper only for a custom gradient on a block with gradient support.
		$has_gradient = $all
			? ! empty( $attributes['gradient'] ) || ! empty( $style['gradient'] )
			: ! empty( $this->support( 'color.gradients' ) ) && ! empty( $style['gradient'] );

		$has_background = ! empty( $attributes['backgroundColor'] ) || ! empty( $style['background'] )
			|| ( ( $all || $this->support( 'color.gradients' ) ) && ( ! empty( $attributes['gradient'] ) || ! empty( $style['gradient'] ) ) );

		return Html::classes(
			[
				'' !== $text_color ? 'has-' . _wp_to_kebab_case( $text_color ) . '-color' : '',
				'' !== $gradient ? 'has-' . _wp_to_kebab_case( $gradient ) . '-gradient-background' : '',
				'' !== $background && ! $has_gradient ? 'has-' . _wp_to_kebab_case( $background ) . '-background-color' : '',
				'has-text-color' => $keeps( 'text' ) && ( ! empty( $attributes['textColor'] ) || ! empty( $style['text'] ) ),
				'has-background' => ( $keeps( 'background' ) || $keeps( 'gradients' ) ) && $has_background,
				'has-link-color' => $keeps( 'link' ) && ! empty( $attributes['style']['elements']['link']['color'] ),
			]
		);
	}

	/**
	 * Returns the border classes for a block's attributes.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function border_classes( array $attributes ): string {
		$border_color = (string) ( $attributes['borderColor'] ?? '' );

		return Html::classes(
			[
				'has-border-color' => '' !== $border_color || ! empty( $attributes['style']['border']['color'] ),
				'' !== $border_color ? 'has-' . _wp_to_kebab_case( $border_color ) . '-border-color' : '',
			]
		);
	}

	/**
	 * Returns the font size and font family classes for a block's attributes,
	 * and with `$all` the text alignment class.
	 *
	 * @param array $attributes Block attributes.
	 * @param bool  $all        Whether to ignore the support's skip serialization setting, for
	 *                          blocks that write these classes on an inner element.
	 * @return string
	 */
	public function typography_classes( array $attributes, bool $all = false ): string {
		$classes    = [];
		$text_align = (string) ( $attributes['style']['typography']['textAlign'] ?? '' );

		// The wrapper gets its text alignment class from `props()`.
		if ( $all && '' !== $text_align ) {
			$classes[] = 'has-text-align-' . $text_align;
		}

		if ( ! empty( $attributes['fontSize'] ) && ( $all || ( $this->support( 'typography.fontSize' ) && ! $this->skips( 'typography', 'fontSize' ) ) ) ) {
			$classes[] = 'has-' . _wp_to_kebab_case( $attributes['fontSize'] ) . '-font-size';
		}

		if ( ! empty( $attributes['fontFamily'] ) && ( $all || ( $this->support( 'typography.__experimentalFontFamily' ) && ! $this->skips( 'typography', 'fontFamily' ) ) ) ) {
			$classes[] = 'has-' . _wp_to_kebab_case( $attributes['fontFamily'] ) . '-font-family';
		}

		return Html::classes( $classes );
	}

	/**
	 * Returns a block support setting.
	 *
	 * @param string $path     Dot-separated path into the block's supports (`color.text`).
	 * @param mixed  $fallback Value when the support is not set.
	 * @return mixed
	 */
	public function support( string $path, mixed $fallback = null ): mixed {
		$value = $this->type->supports ?? [];
		$keys  = explode( '.', $path );

		// Border support is registered under either key.
		if ( 'border' === $keys[0] && ! isset( $value['border'] ) ) {
			$keys[0] = '__experimentalBorder';
		}

		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $fallback;
			}

			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Whether the block writes a support's styles somewhere other than its
	 * wrapper: the support's skip serialization setting, for all its features
	 * or for one.
	 *
	 * @param string $group   Support name (`color`, `typography`, `border`, …).
	 * @param string $feature Feature name, or an empty string for the whole support.
	 * @return bool
	 */
	public function skips( string $group, string $feature = '' ): bool {
		$skip = $this->support( $group . '.__experimentalSkipSerialization' ) ?? $this->support( $group . '.skipSerialization' );

		if ( is_array( $skip ) ) {
			return '' !== $feature && in_array( $feature, $skip, true );
		}

		return true === $skip;
	}

	/**
	 * Returns the inline styles the wrapper takes from the style attribute.
	 *
	 * @param array $attributes Block attributes.
	 * @return array<string, string> CSS declarations.
	 */
	private function styles( array $attributes ): array {
		$style = $attributes['style'] ?? [];

		if ( ! is_array( $style ) || ! $this->has_style_support() ) {
			return [];
		}

		foreach ( self::STYLE_GROUPS as $support => $group ) {
			$skip = $this->support( $support . '.__experimentalSkipSerialization' ) ?? $this->support( $support . '.skipSerialization' );

			if ( true === $skip ) {
				unset( $style[ $group ] );
			} elseif ( is_array( $skip ) ) {
				foreach ( $skip as $feature ) {
					unset( $style[ $group ][ 'gradients' === $feature ? 'gradient' : $feature ] );
				}
			}
		}

		// Rendered on the server.
		unset( $style['background'], $style['dimensions']['aspectRatio'] );

		return $this->declarations( $style );
	}

	/**
	 * Returns the CSS declarations for a style attribute.
	 *
	 * @param array $style Style attribute.
	 * @return array<string, string>
	 */
	private function declarations( array $style ): array {
		// Per-viewport and state styles render on the server.
		$style = array_filter( $style, fn( $key ) => ! str_starts_with( (string) $key, '@' ) && ! str_starts_with( (string) $key, ':' ), ARRAY_FILTER_USE_KEY );

		if ( [] === $style ) {
			return [];
		}

		// The style engine resolves preset references for some properties only, so each becomes its CSS custom property here.
		array_walk_recursive(
			$style,
			function ( &$value ) {
				if ( is_string( $value ) && str_starts_with( $value, 'var:' ) ) {
					$value = 'var(--wp--' . implode( '--', array_map( '_wp_to_kebab_case', explode( '|', substr( $value, 4 ) ) ) ) . ')';
				}
			}
		);

		$styles = wp_style_engine_get_styles( $style );

		return is_array( $styles['declarations'] ?? null ) ? $styles['declarations'] : [];
	}

	/**
	 * Whether the block has any support that writes inline styles.
	 *
	 * @return bool
	 */
	private function has_style_support(): bool {
		foreach ( self::STYLE_SUPPORTS as $support ) {
			if ( ! empty( $this->type->supports[ $support ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the block has color support, as the editor decides it: the
	 * support is set, and it allows links, gradients, a background or text.
	 *
	 * @return bool
	 */
	private function has_color_support(): bool {
		$support = $this->support( 'color' );

		if ( empty( $support ) ) {
			return false;
		}

		if ( ! is_array( $support ) ) {
			return true;
		}

		return true === ( $support['link'] ?? null )
			|| true === ( $support['gradient'] ?? null )
			|| false !== ( $support['background'] ?? null )
			|| false !== ( $support['text'] ?? null );
	}

	/**
	 * Returns the alignments the block supports.
	 *
	 * @return string[]
	 */
	private function alignments(): array {
		$align = $this->support( 'align' );

		if ( is_array( $align ) ) {
			return $align;
		}

		if ( true !== $align ) {
			return [];
		}

		$wide_support = $this->support( 'alignWide', true );

		return false !== $wide_support
			? [ 'left', 'center', 'right', 'wide', 'full' ]
			: [ 'left', 'center', 'right' ];
	}

	/**
	 * Returns the text alignments the block supports.
	 *
	 * @return string[]
	 */
	private function text_alignments(): array {
		$support = $this->support( 'typography.textAlign' );

		if ( is_array( $support ) ) {
			return array_values( array_intersect( self::TEXT_ALIGNMENTS, $support ) );
		}

		return true === $support ? self::TEXT_ALIGNMENTS : [];
	}
}
