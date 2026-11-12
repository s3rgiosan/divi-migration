<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;

defined( 'ABSPATH' ) || exit;

/**
 * Shared handling of the design settings every Divi module has.
 */
abstract class Module implements Rule {

	/**
	 * Divi's `disabled_on` positions, mapped to block visibility viewports.
	 */
	const VISIBILITY_VIEWPORTS = [ 'mobile', 'tablet', 'desktop' ];

	/**
	 * Text color of Divi's dark background layout.
	 */
	const DARK_LAYOUT_TEXT = '#ffffff';

	/**
	 * Modules whose padding in Divi's older `top|bottom` format Divi rewrites
	 * to the four-part format before it renders them.
	 */
	const LEGACY_PADDING_TAGS = [ 'et_pb_section' ];

	/**
	 * Returns the attributes every converted block shares: anchor, class
	 * name, the module's label and its visibility per viewport.
	 *
	 * @param Node   $node       Module node.
	 * @param string $class_name Extra class name for the block.
	 * @return array
	 */
	protected function common_attributes( Node $node, string $class_name = '' ): array {
		$attributes = [];
		$classes    = trim( $class_name . ' ' . $node->attr( 'module_class' ) );

		if ( '' !== $classes ) {
			$attributes['className'] = $classes;
		}

		// An HTML ID holds any character but whitespace.
		$module_id = (string) preg_replace( '/[ \t\n\f\r]+/', '', $node->attr( 'module_id' ) );

		if ( '' !== $module_id ) {
			$attributes['anchor'] = $module_id;
		}

		$admin_label = $node->attr( 'admin_label' );

		if ( '' !== $admin_label ) {
			$attributes['metadata']['name'] = $admin_label;
		}

		$hidden = $this->hidden_viewports( $node );

		if ( [] !== $hidden ) {
			$attributes['metadata']['blockVisibility']['viewport'] = array_fill_keys( $hidden, false );
		}

		return $attributes;
	}

	/**
	 * Adds a module's background, spacing, border and minimum height settings
	 * to a style set.
	 *
	 * @param Node           $node    Module node.
	 * @param StyleSet       $style   Style set to fill.
	 * @param Presets        $presets Theme presets.
	 * @param array          $skip    Settings to leave out: `background`, `padding`, `margin`, `border`, `height`.
	 * @param Converter|null $converter Converter, for the report of settings that are not converted.
	 * @return void
	 */
	protected function design( Node $node, StyleSet $style, Presets $presets, array $skip = [], ?Converter $converter = null ): void {
		if ( ! in_array( 'background', $skip, true ) ) {
			$this->background( $node, $style, $presets, $converter );
		}

		foreach ( [
			'padding' => 'custom_padding',
			'margin'  => 'custom_margin',
		] as $property => $attribute ) {
			if ( in_array( $property, $skip, true ) ) {
				continue;
			}

			$this->spacing( $node, $attribute, [ 'spacing', $property ], $style );
		}

		if ( ! in_array( 'border', $skip, true ) ) {
			$this->border( $node, $style, $presets, $converter );
		}

		if ( ! in_array( 'height', $skip, true ) ) {
			$style->set( [ 'dimensions', 'minHeight' ], array_map( fn( $value ) => Values::is_length( $value ) ? ( is_numeric( $value ) ? $value . 'px' : $value ) : '', Values::responsive( $node, 'min_height' ) ) );
		}
	}

	/**
	 * Adds a Divi spacing setting to a style set, per viewport.
	 *
	 * @param Node     $node      Module node.
	 * @param string   $attribute Spacing attribute, such as `custom_padding`.
	 * @param string[] $path      Style path, such as `[ 'spacing', 'padding' ]`.
	 * @param StyleSet $style     Style set to fill.
	 * @return void
	 */
	protected function spacing( Node $node, string $attribute, array $path, StyleSet $style ): void {
		$values = Values::responsive( $node, $attribute );

		if ( 'custom_padding' === $attribute && in_array( $node->tag, self::LEGACY_PADDING_TAGS, true ) ) {
			$values            = Values::legacy_mobile_padding( $node, $values );
			$values['desktop'] = Values::legacy_padding( $values['desktop'] );
		}

		foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
			$per_viewport = [];

			foreach ( $values as $viewport => $value ) {
				$per_viewport[ $viewport ] = Values::spacing( $value )[ $side ] ?? '';
			}

			$style->set( [ ...$path, $side ], $per_viewport );
		}
	}

	/**
	 * Adds typography settings for a Divi text group, such as `header_2`.
	 *
	 * @param Node     $node    Module node.
	 * @param string   $prefix  Attribute prefix, such as `text` or `header_2`.
	 * @param StyleSet $style   Style set to fill.
	 * @param Presets  $presets Theme presets.
	 * @return void
	 */
	protected function typography( Node $node, string $prefix, StyleSet $style, Presets $presets ): void {
		$font = Values::font( $node->attr( $prefix . '_font' ) );

		if ( isset( $font['family'] ) ) {
			$style->set_value( [ 'typography', 'fontFamily' ], $presets->font_family( $font['family'] ) );
		}

		if ( isset( $font['weight'] ) ) {
			$style->set_value( [ 'typography', 'fontWeight' ], $font['weight'] );
		}

		if ( ! empty( $font['italic'] ) ) {
			$style->set_value( [ 'typography', 'fontStyle' ], 'italic' );
		}

		if ( ! empty( $font['uppercase'] ) ) {
			$style->set_value( [ 'typography', 'textTransform' ], 'uppercase' );
		}

		if ( ! empty( $font['underline'] ) ) {
			$style->set_value( [ 'typography', 'textDecoration' ], 'underline' );
		} elseif ( ! empty( $font['strikethrough'] ) ) {
			$style->set_value( [ 'typography', 'textDecoration' ], 'line-through' );
		}

		$sizes = array_map( fn( string $size ) => $presets->font_size( Values::with_unit( $size ) ), Values::responsive( $node, $prefix . '_font_size' ) );
		$style->set( [ 'typography', 'fontSize' ], $sizes );

		$line_heights = array_map( fn( string $height ) => self::line_height( Values::with_unit( $height, true ) ), Values::responsive( $node, $prefix . '_line_height' ) );
		$style->set( [ 'typography', 'lineHeight' ], $line_heights );

		$style->set( [ 'typography', 'letterSpacing' ], array_map( [ Values::class, 'with_unit' ], Values::responsive( $node, $prefix . '_letter_spacing' ) ) );

		$style->set( [ 'color', 'text' ], $presets->responsive( Values::responsive( $node, $prefix . '_text_color' ) ) );
	}

	/**
	 * Adds a module's custom button styles, set with a prefix such as `button`
	 * or `button_one` (`button_text_size`, `button_bg_color`, …).
	 *
	 * @param Node     $node    Module node.
	 * @param string   $prefix  Attribute prefix.
	 * @param StyleSet $style   Button styles.
	 * @param Presets  $presets Theme presets.
	 * @return void
	 */
	protected function button_styles( Node $node, string $prefix, StyleSet $style, Presets $presets ): void {
		$style->set( [ 'typography', 'fontSize' ], array_map( [ Values::class, 'with_unit' ], Values::responsive( $node, $prefix . '_text_size' ) ) );
		$style->set( [ 'typography', 'letterSpacing' ], array_map( [ Values::class, 'with_unit' ], Values::responsive( $node, $prefix . '_letter_spacing' ) ) );
		$style->set( [ 'color', 'text' ], $presets->responsive( Values::responsive( $node, $prefix . '_text_color' ) ) );
		$style->set( [ 'color', 'background' ], $presets->responsive( Values::responsive( $node, $prefix . '_bg_color' ) ) );
		$style->set( [ 'border', 'width' ], array_map( [ Values::class, 'with_unit' ], Values::responsive( $node, $prefix . '_border_width' ) ) );
		$style->set( [ 'border', 'color' ], $presets->responsive( Values::responsive( $node, $prefix . '_border_color' ) ) );
		$style->set( [ 'border', 'radius' ], array_map( [ Values::class, 'with_unit' ], Values::responsive( $node, $prefix . '_border_radius' ) ) );

		$style->set_hover( [ 'color', 'text' ], $presets->color( Values::hover( $node, $prefix . '_text_color' ) ) );
		$style->set_hover( [ 'color', 'background' ], $presets->color( Values::hover( $node, $prefix . '_bg_color' ) ) );
		$style->set_hover( [ 'border', 'color' ], $presets->color( Values::hover( $node, $prefix . '_border_color' ) ) );

		$font = Values::font( $node->attr( $prefix . '_font' ) );

		if ( isset( $font['family'] ) ) {
			$style->set_value( [ 'typography', 'fontFamily' ], $presets->font_family( $font['family'] ) );
		}

		if ( isset( $font['weight'] ) ) {
			$style->set_value( [ 'typography', 'fontWeight' ], $font['weight'] );
		}

		if ( ! empty( $font['uppercase'] ) ) {
			$style->set_value( [ 'typography', 'textTransform' ], 'uppercase' );
		}

		$this->spacing( $node, $prefix . '_custom_padding', [ 'spacing', 'padding' ], $style );
	}

	/**
	 * Returns a Buttons block with one Button, or an empty list without text.
	 *
	 * @param Node      $node      Module node.
	 * @param string    $prefix    Attribute prefix, such as `button`.
	 * @param string    $text      Button text.
	 * @param string    $url       Button link.
	 * @param Converter $converter Converter.
	 * @param string    $justify   Default alignment: `left`, `center` or `right`.
	 * @return array[]
	 */
	protected function button_block( Node $node, string $prefix, string $text, string $url, Converter $converter, string $justify = 'left' ): array {
		if ( '' === $text ) {
			return [];
		}

		$style = new StyleSet();

		if ( $node->is_on( 'custom_' . $prefix ) ) {
			$this->button_styles( $node, $prefix, $style, $converter->presets );
		}

		$button = [
			'text'  => esc_html( $text ),
			'url'   => self::url( $url ),
			'style' => $style->to_style(),
		];

		$button = $this->with_new_tab( $node, $button );

		$alignment = Values::responsive( $node, $prefix . '_alignment' );
		$layout    = [
			'type'           => 'flex',
			'justifyContent' => in_array( $alignment['desktop'], [ 'left', 'center', 'right' ], true ) ? $alignment['desktop'] : $justify,
		];

		$wrapper_style = [];

		foreach ( [
			'tablet' => '@tablet',
			'phone'  => '@mobile',
		] as $viewport => $state ) {
			$value = $alignment[ $viewport ];

			if ( in_array( $value, [ 'left', 'center', 'right' ], true ) && $value !== $layout['justifyContent'] ) {
				$wrapper_style[ $state ]['layout']['justifyContent'] = $value;
			}
		}

		return [
			Spec::block(
				'core/buttons',
				[
					'layout' => $layout,
					'style'  => $wrapper_style,
				],
				[ Spec::block( 'core/button', $button ) ]
			),
		];
	}

	/**
	 * Wraps specs in a constrained Group when the module has a width, a maximum
	 * width or an alignment, so they keep the module's size and position.
	 *
	 * @param Node     $node  Module node.
	 * @param array[]  $specs Specs to wrap.
	 * @param StyleSet $style Module styles for the wrapper.
	 * @param array    $attributes Extra wrapper attributes.
	 * @return array[]
	 */
	protected function wrap( Node $node, array $specs, StyleSet $style, array $attributes = [] ): array {
		$layout = $this->sized_layout( $node );

		if ( [] === $layout && $style->is_empty() && [] === $attributes ) {
			return $specs;
		}

		$style_attribute = $style->to_style();

		foreach ( $layout['viewports'] ?? [] as $state => $viewport_layout ) {
			$style_attribute[ $state ]['layout'] = $viewport_layout;
		}

		return [
			Spec::block(
				'core/group',
				array_merge(
					$attributes,
					[
						'layout' => $layout['base'] ?? [ 'type' => 'default' ],
						'style'  => $style_attribute,
					]
				),
				$specs
			),
		];
	}

	/**
	 * Returns a constrained layout for a module's width and alignment.
	 *
	 * @param Node $node            Module node.
	 * @param bool $inherit_desktop Whether a missing desktop width keeps the theme's content size. When false, a missing desktop width is the full width.
	 * @return array{base?: array, viewports?: array<string, array>}
	 */
	protected function sized_layout( Node $node, bool $inherit_desktop = false ): array {
		$width     = Values::responsive( $node, 'width' );
		$max_width = Values::responsive( $node, 'max_width' );
		$alignment = Values::responsive( $node, 'module_alignment' );

		$size = [];

		foreach ( [ 'desktop', 'tablet', 'phone' ] as $viewport ) {
			$lengths = array_filter(
				[ $width[ $viewport ], $max_width[ $viewport ] ],
				fn( string $length ) => Values::is_length( $length ) && 'auto' !== $length
			);

			$size[ $viewport ] = self::smallest_length( array_values( $lengths ) );
		}

		$sizes = implode( '', $size );

		if ( '' === $sizes ) {
			return [];
		}

		$justify = [
			'left'   => 'left',
			'center' => 'center',
			'right'  => 'right',
		];

		$base = [
			'type'           => 'constrained',
			'contentSize'    => '' !== $size['desktop'] ? $size['desktop'] : ( $inherit_desktop ? '' : '100%' ),
			'justifyContent' => $justify[ $alignment['desktop'] ] ?? ( $inherit_desktop ? 'center' : 'left' ),
		];

		if ( '' === $base['contentSize'] ) {
			unset( $base['contentSize'] );
		}

		$viewports = [];

		foreach ( [
			'tablet' => '@tablet',
			'phone'  => '@mobile',
		] as $viewport => $state ) {
			$viewport_layout = [];

			if ( '' !== $size[ $viewport ] && $size[ $viewport ] !== ( $base['contentSize'] ?? '' ) ) {
				$viewport_layout['contentSize'] = $size[ $viewport ];
			}

			if ( isset( $justify[ $alignment[ $viewport ] ] ) && $justify[ $alignment[ $viewport ] ] !== $base['justifyContent'] ) {
				$viewport_layout['justifyContent'] = $justify[ $alignment[ $viewport ] ];
			}

			if ( [] !== $viewport_layout ) {
				$viewports[ $state ] = $viewport_layout;
			}
		}

		return [
			'base'      => $base,
			'viewports' => $viewports,
		];
	}

	/**
	 * Returns the smaller of up to two CSS lengths. Lengths with the same
	 * unit are compared; others become a CSS `min()` call.
	 *
	 * @param string[] $lengths Lengths, none to two.
	 * @return string
	 */
	protected static function smallest_length( array $lengths ): string {
		if ( [] === $lengths ) {
			return '';
		}

		$count = count( $lengths );

		if ( 1 === $count ) {
			return $lengths[0];
		}

		[ $first, $second ] = $lengths;

		$pattern      = '/^(-?\d*\.?\d+)(px|em|rem|%|vw|vh|vmin|vmax)?$/';
		$first_found  = preg_match( $pattern, $first, $first_parts );
		$second_found = preg_match( $pattern, $second, $second_parts );

		if ( 1 === $first_found && 1 === $second_found && ( $first_parts[2] ?? '' ) === ( $second_parts[2] ?? '' ) ) {
			return (float) $first_parts[1] <= (float) $second_parts[1] ? $first : $second;
		}

		return 'min(' . $first . ', ' . $second . ')';
	}

	/**
	 * Adds a note to the report when a Divi setting of a module has a value
	 * and no conversion. An empty value and `off` are Divi's defaults.
	 *
	 * @param Node      $node       Module node.
	 * @param Converter $converter  Converter, for the report.
	 * @param string    $message    What is not converted, such as `icon font`.
	 * @param string[]  $attributes Divi settings that hold the data.
	 * @return void
	 */
	protected function note_unconverted( Node $node, Converter $converter, string $message, array $attributes ): void {
		foreach ( $attributes as $attribute ) {
			$value = $node->attr( $attribute );

			if ( '' !== $value && 'off' !== $value ) {
				$converter->note( $node->tag, 'not converted: ' . $message );
				return;
			}
		}
	}

	/**
	 * Returns an alignment attribute per viewport. Divi's `justified`
	 * becomes `left`, which the blocks' text alignment supports.
	 *
	 * @param Node      $node      Module node.
	 * @param string    $attribute Attribute name, such as `text_orientation`.
	 * @param Converter $converter Converter, for the report.
	 * @return array{desktop: string, tablet: string, phone: string}
	 */
	protected function alignment( Node $node, string $attribute, Converter $converter ): array {
		$values = Values::responsive( $node, $attribute );

		foreach ( $values as $viewport => $value ) {
			if ( 'justified' === $value ) {
				$values[ $viewport ] = 'left';
				$converter->note( $node->tag, 'justified text set to left' );
			}
		}

		return $values;
	}

	/**
	 * Sets light text on a style set for Divi's dark background layout, unless
	 * the style set has a text color.
	 *
	 * @param Node     $node     Module node.
	 * @param StyleSet $style    Style set to fill.
	 * @param Presets  $presets  Theme presets.
	 * @param string   $fallback Layout when the module sets none: `light` or `dark`.
	 * @return void
	 */
	protected function background_layout( Node $node, StyleSet $style, Presets $presets, string $fallback = 'light' ): void {
		if ( isset( $style->to_style()['color']['text'] ) ) {
			return;
		}

		$layouts = Values::responsive( $node, 'background_layout' );
		$layouts = [
			'desktop' => '' !== $layouts['desktop'] ? $layouts['desktop'] : $fallback,
			'tablet'  => $layouts['tablet'],
			'phone'   => $layouts['phone'],
		];

		$colors = array_map( fn( string $layout ) => 'dark' === $layout ? $presets->color( self::DARK_LAYOUT_TEXT ) : '', $layouts );
		$style->set( [ 'color', 'text' ], $colors );
	}

	/**
	 * Returns a module's content per viewport, one entry per distinct text.
	 *
	 * Divi keeps tablet and phone content in `content_tablet` and
	 * `content_phone` when `content_last_edited` starts with `on`. A missing
	 * tablet value takes the desktop content, and a missing phone value takes
	 * the tablet value. Divi hides the content on a viewport whose value is
	 * empty.
	 *
	 * @param Node $node Module node.
	 * @return array<int, array{content: string, hidden: string[]}> Each non-empty content, with the block visibility viewports where it is hidden.
	 */
	protected function viewport_contents( Node $node ): array {
		$desktop = trim( $node->content );

		if ( ! str_starts_with( $node->attr( 'content_last_edited' ), 'on' ) ) {
			return '' === $desktop ? [] : [
				[
					'content' => $desktop,
					'hidden'  => [],
				],
			];
		}

		$tablet = array_key_exists( 'content_tablet', $node->attrs ) ? trim( (string) $node->attrs['content_tablet'] ) : $desktop;
		$phone  = array_key_exists( 'content_phone', $node->attrs ) ? trim( (string) $node->attrs['content_phone'] ) : $tablet;

		$by_viewport = [
			'desktop' => $desktop,
			'tablet'  => $tablet,
			'mobile'  => $phone,
		];

		$variants = [];

		foreach ( array_unique( array_filter( $by_viewport, fn( string $content ) => '' !== $content ) ) as $content ) {
			$variants[] = [
				'content' => $content,
				'hidden'  => array_keys( array_filter( $by_viewport, fn( string $other ) => $other !== $content ) ),
			];
		}

		return $variants;
	}

	/**
	 * Builds the blocks of each content variant. When the content differs per
	 * viewport, each variant sits in a Group that is hidden where the variant
	 * does not apply.
	 *
	 * @param array<int, array{content: string, hidden: string[]}> $variants   Content variants from `viewport_contents()`.
	 * @param callable                                             $build      Returns the block specs for one content.
	 * @param string                                               $class_name Class name for the variant Groups.
	 * @return array[]
	 */
	protected function variant_blocks( array $variants, callable $build, string $class_name = '' ): array {
		$variant_count = count( $variants );

		if ( 1 === $variant_count && [] === $variants[0]['hidden'] ) {
			return $build( $variants[0]['content'] );
		}

		$specs = [];

		foreach ( $variants as $variant ) {
			$attributes = [
				'layout'   => [ 'type' => 'default' ],
				'metadata' => [ 'blockVisibility' => [ 'viewport' => array_fill_keys( $variant['hidden'], false ) ] ],
			];

			if ( '' !== $class_name ) {
				$attributes['className'] = $class_name;
			}

			$specs[] = Spec::block( 'core/group', $attributes, $build( $variant['content'] ) );
		}

		return $specs;
	}

	/**
	 * Converts a Divi line height in `em` to a unitless value.
	 *
	 * @param string $value Line height, such as `1.6em`.
	 * @return string
	 */
	protected static function line_height( string $value ): string {
		return str_ends_with( $value, 'em' ) && ! str_ends_with( $value, 'rem' ) ? substr( $value, 0, -2 ) : $value;
	}

	/**
	 * Sanitizes a Divi link for the database, and keeps a relative path
	 * relative.
	 *
	 * Divi writes a path such as `contact/` in the link as it is.
	 * `esc_url_raw()` adds `http://` to a path that does not start with `/`,
	 * `#` or `?`. The path is sanitized with a leading slash, which is then
	 * removed.
	 *
	 * @param string $url Divi link.
	 * @return string
	 */
	protected static function url( string $url ): string {
		$url        = trim( $url );
		$has_scheme = 1 === preg_match( '/^[a-z][a-z0-9+.-]*:/i', $url );

		if ( '' === $url || $has_scheme || in_array( $url[0], [ '/', '#', '?' ], true ) ) {
			return esc_url_raw( $url );
		}

		return substr( esc_url_raw( '/' . $url ), 1 );
	}

	/**
	 * Returns the heading level (1 to 6) from a module's level setting such
	 * as `h3`, or the fallback level when the setting is not a valid level.
	 *
	 * @param Node   $node     Module node.
	 * @param string $key      Level setting name.
	 * @param int    $fallback Level to use when the setting is invalid.
	 * @return int
	 */
	protected function heading_level( Node $node, string $key, int $fallback ): int {
		$level = (int) ltrim( $node->attr( $key, 'h' . $fallback ), 'h' );

		return $level >= 1 && $level <= 6 ? $level : $fallback;
	}

	/**
	 * Returns the block attributes with the ID of the media library
	 * attachment for a URL, when the URL belongs to one.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $url        Media URL.
	 * @return array
	 */
	protected function with_attachment_id( array $attributes, string $url ): array {
		$attachment_id = attachment_url_to_postid( $url );

		if ( $attachment_id > 0 ) {
			$attributes['id'] = $attachment_id;
		}

		return $attributes;
	}

	/**
	 * Returns the block attributes with the link set to open in a new tab,
	 * when the module's link opens in a new window.
	 *
	 * @param Node  $node       Module node.
	 * @param array $attributes Block attributes.
	 * @return array
	 */
	protected function with_new_tab( Node $node, array $attributes ): array {
		if ( $node->is_on( 'url_new_window' ) ) {
			$attributes['linkTarget'] = '_blank';
			$attributes['rel']        = 'noopener';
		}

		return $attributes;
	}

	/**
	 * Returns the attributes of a group block that wraps a module's content:
	 * the common attributes, the default layout and the style.
	 *
	 * @param Node     $node       Module node.
	 * @param StyleSet $style      Style of the group.
	 * @param string   $class_name Extra class name for the block.
	 * @return array
	 */
	protected function group_attributes( Node $node, StyleSet $style, string $class_name = '' ): array {
		return array_merge(
			$this->common_attributes( $node, $class_name ),
			[
				'layout' => [ 'type' => 'default' ],
				'style'  => $style->to_style(),
			]
		);
	}

	/**
	 * Returns a heading block.
	 *
	 * @param string   $content Heading HTML content.
	 * @param int      $level   Heading level.
	 * @param StyleSet $style   Style of the heading.
	 * @return array
	 */
	protected function heading_block( string $content, int $level, StyleSet $style ): array {
		return Spec::block(
			'core/heading',
			[
				'level'   => $level,
				'content' => $content,
				'style'   => $style->to_style(),
			]
		);
	}

	/**
	 * Adds background color and image settings to a style set.
	 *
	 * @param Node           $node    Module node.
	 * @param StyleSet       $style   Style set to fill.
	 * @param Presets        $presets Theme presets.
	 * @param Converter|null $converter Converter, for the report.
	 * @return void
	 */
	private function background( Node $node, StyleSet $style, Presets $presets, ?Converter $converter = null ): void {
		if ( $converter instanceof Converter ) {
			$this->note_unconverted( $node, $converter, 'background gradient', [ 'use_background_color_gradient' ] );
		}

		$enable_color = $node->attr( 'background_enable_color', 'on' );

		if ( 'off' !== $enable_color ) {
			$style->set( [ 'color', 'background' ], $presets->responsive( Values::responsive( $node, 'background_color', 'background' ) ) );
			$style->set_hover( [ 'color', 'background' ], $presets->color( Values::hover( $node, 'background_color' ) ) );
		}

		$images  = Values::responsive( $node, 'background_image', 'background' );
		$enabled = Values::responsive( $node, 'background_enable_image', 'background' );
		$sizes   = Values::responsive( $node, 'background_size', 'background' );
		$places  = Values::responsive( $node, 'background_position', 'background' );
		$repeats = Values::responsive( $node, 'background_repeat', 'background' );
		$values  = [];

		foreach ( [ 'desktop', 'tablet', 'phone' ] as $viewport ) {
			$image = '' !== $images[ $viewport ] ? $images[ $viewport ] : ( 'phone' === $viewport && '' !== $images['tablet'] ? $images['tablet'] : $images['desktop'] );
			$on    = '' !== $enabled[ $viewport ] ? $enabled[ $viewport ] : $enabled['desktop'];

			if ( '' === $image || 'off' === $on ) {
				continue;
			}

			$background_image = [
				'url'    => $image,
				'source' => 'file',
				'title'  => '',
			];

			$background_image = $this->with_attachment_id( $background_image, $image );

			$size = '' !== $sizes[ $viewport ] ? $sizes[ $viewport ] : $node->attr( 'background_size', 'cover' );

			$values['backgroundImage'][ $viewport ]    = $background_image;
			$values['backgroundSize'][ $viewport ]     = 'initial' === $size ? 'auto' : $size;
			$values['backgroundPosition'][ $viewport ] = Values::position( '' !== $places[ $viewport ] ? $places[ $viewport ] : $node->attr( 'background_position', 'center' ) );
			$values['backgroundRepeat'][ $viewport ]   = '' !== $repeats[ $viewport ] ? $repeats[ $viewport ] : $node->attr( 'background_repeat', 'no-repeat' );
		}

		foreach ( $values as $property => $per_viewport ) {
			$style->set( [ 'background', $property ], $per_viewport );
		}
	}

	/**
	 * Adds border width, color, style and radius settings to a style set.
	 *
	 * @param Node           $node    Module node.
	 * @param StyleSet       $style   Style set to fill.
	 * @param Presets        $presets Theme presets.
	 * @param Converter|null $converter Converter, for the report.
	 * @return void
	 */
	private function border( Node $node, StyleSet $style, Presets $presets, ?Converter $converter = null ): void {
		if ( $converter instanceof Converter ) {
			$sides = [];

			foreach ( [ 'top', 'right', 'bottom', 'left' ] as $side ) {
				foreach ( [ 'width', 'color', 'style' ] as $property ) {
					$sides[] = sprintf( 'border_%s_%s', $property, $side );
				}
			}

			$this->note_unconverted( $node, $converter, 'border per side', $sides );
		}

		$width = $node->attr( 'border_width_all' );

		if ( '' !== $width ) {
			$style->set_value( [ 'border', 'width' ], $width );
			$style->set_value( [ 'border', 'style' ], $node->attr( 'border_style_all', 'solid' ) );
			$style->set_value( [ 'border', 'color' ], $presets->color( $node->attr( 'border_color_all', '#333333' ) ) );
		}

		// Divi stores `linked|top-left|top-right|bottom-right|bottom-left`.
		$radii  = explode( '|', $node->attr( 'border_radii' ) );
		$values = implode( '', array_slice( $radii, 1, 4 ) );

		$radii_count = count( $radii );

		if ( $radii_count < 5 || '' === $values ) {
			return;
		}

		$corners = [ 'topLeft', 'topRight', 'bottomRight', 'bottomLeft' ];

		foreach ( $corners as $index => $corner ) {
			$radius = trim( $radii[ $index + 1 ] );

			if ( '' !== $radius ) {
				$style->set_value( [ 'border', 'radius', $corner ], $radius );
			}
		}
	}

	/**
	 * Returns the viewports a module is hidden on, from `disabled_on`
	 * (`phone|tablet|desktop`).
	 *
	 * @param Node $node Module node.
	 * @return string[]
	 */
	private function hidden_viewports( Node $node ): array {
		$flags  = explode( '|', $node->attr( 'disabled_on' ) );
		$hidden = [];

		foreach ( self::VISIBILITY_VIEWPORTS as $index => $viewport ) {
			if ( 'on' === ( $flags[ $index ] ?? '' ) ) {
				$hidden[] = $viewport;
			}
		}

		return $hidden;
	}
}
