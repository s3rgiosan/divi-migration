<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

/**
 * The saved markup of static blocks: one PHP method for each block's `save()` output.
 *
 * A save returns the markup before and after the block's inner blocks, and
 * takes the block's attributes (with defaults) and its block supports. A
 * registered static block with no save here makes the serializer list it as
 * unsupported, and the conversion skips the post. Dynamic blocks need no
 * save: they are serialized as their comment delimiters.
 */
final class Saves {

	/**
	 * Cover content positions and their classes.
	 */
	const COVER_POSITIONS = [
		'top left'      => 'is-position-top-left',
		'top center'    => 'is-position-top-center',
		'top right'     => 'is-position-top-right',
		'center left'   => 'is-position-center-left',
		'center center' => 'is-position-center-center',
		'center'        => 'is-position-center-center',
		'center right'  => 'is-position-center-right',
		'bottom left'   => 'is-position-bottom-left',
		'bottom center' => 'is-position-bottom-center',
		'bottom right'  => 'is-position-bottom-right',
	];

	/**
	 * Returns the save callbacks, keyed by block name.
	 *
	 * @return array<string, callable(array, Supports): array{0: string, 1: string}>
	 */
	public static function all(): array {
		$saves = [
			'core/accordion'             => [ self::class, 'accordion' ],
			'core/accordion-heading'     => [ self::class, 'accordion_heading' ],
			'core/accordion-item'        => [ self::class, 'accordion_item' ],
			'core/accordion-panel'       => [ self::class, 'accordion_panel' ],
			'core/audio'                 => [ self::class, 'audio' ],
			'core/button'                => [ self::class, 'button' ],
			'core/buttons'               => [ self::class, 'buttons' ],
			'core/column'                => [ self::class, 'column' ],
			'core/columns'               => [ self::class, 'columns' ],
			'core/cover'                 => [ self::class, 'cover' ],
			'core/embed'                 => [ self::class, 'embed' ],
			'core/gallery'               => [ self::class, 'gallery' ],
			'core/group'                 => [ self::class, 'group' ],
			'core/heading'               => [ self::class, 'heading' ],
			'core/image'                 => [ self::class, 'image' ],
			'core/list'                  => [ self::class, 'list' ],
			'core/list-item'             => [ self::class, 'list_item' ],
			'core/more'                  => [ self::class, 'more' ],
			'core/nextpage'              => [ self::class, 'nextpage' ],
			'core/paragraph'             => [ self::class, 'paragraph' ],
			'core/query'                 => [ self::class, 'query' ],
			'core/quote'                 => [ self::class, 'quote' ],
			'core/separator'             => [ self::class, 'separator' ],
			'core/shortcode'             => [ self::class, 'shortcode' ],
			'core/table'                 => [ self::class, 'table' ],
			'core/video'                 => [ self::class, 'video' ],

			// Flexible Table Block plugin, for the `flexible-table` target.
			'flexible-table-block/table' => [ self::class, 'flexible_table' ],
		];

		/**
		 * Filters the saves of static blocks.
		 *
		 * A block plugin whose blocks save markup adds a save per block, so the
		 * converter can write those blocks: a callable that takes the block's
		 * attributes (with defaults) and a `Supports` instance, and returns the
		 * markup before and after the inner blocks. A registered static block
		 * with no save makes the conversion skip the post. Dynamic blocks need
		 * none.
		 *
		 * @param array<string, callable> $saves Saves keyed by block name.
		 */
		return apply_filters( 's3s_divi_migration_block_saves', $saves );
	}

	/**
	 * Accordion.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function accordion( array $attributes, Supports $supports ): array {
		return [ Html::open( 'div', $supports->props( $attributes, [ 'role' => 'group' ] ) ), '</div>' ];
	}

	/**
	 * Accordion heading.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function accordion_heading( array $attributes, Supports $supports ): array {
		$tag       = 'h' . (int) ( $attributes['level'] ?? 3 );
		$show_icon = ! empty( $attributes['showIcon'] );
		$position  = $attributes['iconPosition'] ?? 'right';
		$icon      = Html::element(
			'span',
			[
				'class'       => 'wp-block-accordion-heading__toggle-icon',
				'aria-hidden' => 'true',
			],
			'+'
		);

		$typography = $supports->group_styles( $attributes, [ 'typography' ] );
		$title      = Html::element(
			'span',
			[
				'class' => 'wp-block-accordion-heading__toggle-title',
				'style' => array_intersect_key( $typography, array_flip( [ 'letter-spacing', 'text-decoration' ] ) ),
			],
			(string) ( $attributes['title'] ?? '' )
		);

		$button = Html::element(
			'button',
			[
				'type'  => 'button',
				'class' => 'wp-block-accordion-heading__toggle',
				'style' => $supports->group_styles( $attributes, [ 'spacing' ] ),
			],
			( $show_icon && 'left' === $position ? $icon : '' ) . $title . ( $show_icon && 'right' === $position ? $icon : '' )
		);

		$props = $supports->props(
			$attributes,
			[
				'class' => Html::classes(
					[
						'has-icon'       => $show_icon,
						'has-icon-left'  => $show_icon && 'left' === $position,
						'has-icon-right' => $show_icon && 'right' === $position,
					]
				),
			]
		);

		return [ Html::element( $tag, $props, $button ), '' ];
	}

	/**
	 * Accordion item.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function accordion_item( array $attributes, Supports $supports ): array {
		$props = $supports->props( $attributes, [ 'class' => ! empty( $attributes['openByDefault'] ) ? 'is-open' : '' ] );

		return [ Html::open( 'div', $props ), '</div>' ];
	}

	/**
	 * Accordion panel.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function accordion_panel( array $attributes, Supports $supports ): array {
		return [ Html::open( 'div', $supports->props( $attributes, [ 'role' => 'region' ] ) ), '</div>' ];
	}

	/**
	 * Audio.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function audio( array $attributes, Supports $supports ): array {
		$src = (string) ( $attributes['src'] ?? '' );

		if ( '' === $src ) {
			return [ '', '' ];
		}

		$audio = Html::element(
			'audio',
			[
				'controls' => true,
				'src'      => $src,
				'autoplay' => ! empty( $attributes['autoplay'] ) ? true : null,
				'loop'     => ! empty( $attributes['loop'] ) ? true : null,
				'preload'  => $attributes['preload'] ?? null,
			]
		);

		return [ Html::element( 'figure', $supports->props( $attributes ), $audio . self::caption( $attributes ) ), '' ];
	}

	/**
	 * Button.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function button( array $attributes, Supports $supports ): array {
		$tag       = $attributes['tagName'] ?? 'a';
		$is_button = 'button' === $tag;
		$style     = $attributes['style'] ?? [];

		$classes = Html::classes(
			[
				'wp-block-button__link',
				$supports->color_classes( $attributes, true ),
				$supports->border_classes( $attributes ),
				$supports->typography_classes( $attributes, true ),
				'no-border-radius'     => isset( $style['border']['radius'] ) && 0 === $style['border']['radius'],
				'has-custom-font-size' => ! empty( $attributes['fontSize'] ) || ! empty( $style['typography']['fontSize'] ),
				'wp-element-button',
			]
		);

		$link = Html::element(
			$tag,
			[
				'type'   => $is_button ? ( $attributes['type'] ?? 'button' ) : null,
				'class'  => $classes,
				'href'   => $is_button ? null : ( $attributes['url'] ?? null ),
				'title'  => $attributes['title'] ?? null,
				'style'  => $supports->group_styles( $attributes, [ 'border', 'color', 'spacing', 'shadow', 'typography' ] ),
				'target' => $is_button ? null : ( $attributes['linkTarget'] ?? null ),
				'rel'    => $is_button ? null : ( $attributes['rel'] ?? null ),
			],
			(string) ( $attributes['text'] ?? '' )
		);

		return [ Html::element( 'div', $supports->props( $attributes ), $link ), '' ];
	}

	/**
	 * Buttons.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function buttons( array $attributes, Supports $supports ): array {
		$custom_size = ! empty( $attributes['fontSize'] ) || ! empty( $attributes['style']['typography']['fontSize'] );

		return [ Html::open( 'div', $supports->props( $attributes, [ 'class' => $custom_size ? 'has-custom-font-size' : '' ] ) ), '</div>' ];
	}

	/**
	 * Column.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function column( array $attributes, Supports $supports ): array {
		$width     = $attributes['width'] ?? '';
		$style     = [];
		$number    = is_int( $width ) || is_float( $width ) ? (float) $width : 0.0;
		$has_digit = is_string( $width ) && 1 === preg_match( '/\d/', $width );

		// A width of zero, or a number that is not finite, sets no flex basis.
		if ( is_finite( $number ) && 0.0 !== $number ) {
			$style['flex-basis'] = self::number( $width ) . '%';
		} elseif ( $has_digit ) {
			$style['flex-basis'] = str_ends_with( $width, '%' ) ? self::number( round( (float) $width, 12 ) ) . '%' : $width;
		}

		$vertical = (string) ( $attributes['verticalAlignment'] ?? '' );
		$props    = $supports->props(
			$attributes,
			[
				'class' => '' !== $vertical ? 'is-vertically-aligned-' . $vertical : '',
				'style' => $style,
			]
		);

		return [ Html::open( 'div', $props ), '</div>' ];
	}

	/**
	 * Columns.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function columns( array $attributes, Supports $supports ): array {
		$vertical = (string) ( $attributes['verticalAlignment'] ?? '' );
		$classes  = Html::classes(
			[
				'' !== $vertical ? 'are-vertically-aligned-' . $vertical : '',
				'is-not-stacked-on-mobile' => false === ( $attributes['isStackedOnMobile'] ?? true ),
			]
		);

		return [ Html::open( 'div', $supports->props( $attributes, [ 'class' => $classes ] ) ), '</div>' ];
	}

	/**
	 * Cover.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function cover( array $attributes, Supports $supports ): array {
		$url         = (string) ( $attributes['url'] ?? '' );
		$id          = (int) ( $attributes['id'] ?? 0 );
		$parallax    = ! empty( $attributes['hasParallax'] );
		$repeated    = ! empty( $attributes['isRepeated'] );
		$is_img      = ! $parallax && ! $repeated;
		$focal_point = $attributes['focalPoint'] ?? null;
		$position    = (string) ( $attributes['contentPosition'] ?? '' );
		$dim_ratio   = $attributes['dimRatio'] ?? null;
		$overlay     = (string) ( $attributes['overlayColor'] ?? '' );
		$size_slug   = (string) ( $attributes['sizeSlug'] ?? '' );
		$min_height  = $attributes['minHeight'] ?? null;
		$is_centered = '' === $position || 'center center' === $position || 'center' === $position;

		if ( null !== $min_height && ! empty( $attributes['minHeightUnit'] ) ) {
			$min_height = self::number( $min_height ) . $attributes['minHeightUnit'];
		}

		$props = $supports->props(
			$attributes,
			[
				'class' => Html::classes(
					[
						'is-light'                    => empty( $attributes['isDark'] ),
						'has-parallax'                => $parallax,
						'is-repeated'                 => $repeated,
						'has-custom-content-position' => ! $is_centered,
						$is_centered ? '' : ( self::COVER_POSITIONS[ $position ] ?? '' ),
					]
				),
				'style' => [ 'min-height' => null !== $min_height ? (string) $min_height : null ],
			]
		);

		$media           = '';
		$background_type = (string) ( $attributes['backgroundType'] ?? 'image' );

		if ( empty( $attributes['useFeaturedImage'] ) && 'image' === $background_type && '' !== $url ) {
			$image_classes   = Html::classes(
				[
					'wp-block-cover__image-background',
					$id > 0 ? 'wp-image-' . $id : '',
					'' !== $size_slug ? 'size-' . $size_slug : '',
					'has-parallax' => $parallax,
					'is-repeated'  => $repeated,
				]
			);
			$object_position = is_array( $focal_point ) && $is_img ? self::media_position( $focal_point ) : null;

			$media = $is_img
				? Html::open(
					'img',
					[
						'class'                => $image_classes,
						'alt'                  => (string) ( $attributes['alt'] ?? '' ),
						'src'                  => $url,
						'style'                => [ 'object-position' => $object_position ],
						'data-object-fit'      => 'cover',
						'data-object-position' => $object_position,
					]
				)
				: Html::element(
					'div',
					[
						'role'       => ! empty( $attributes['alt'] ) ? 'img' : null,
						'aria-label' => ! empty( $attributes['alt'] ) ? $attributes['alt'] : null,
						'class'      => $image_classes,
						'style'      => [
							'background-position' => self::media_position( is_array( $focal_point ) ? $focal_point : [] ),
							'background-image'    => 'url(' . $url . ')',
						],
					]
				);
		}

		if ( 'video' === $background_type && '' !== $url ) {
			$object_position = is_array( $focal_point ) && $is_img ? self::media_position( $focal_point ) : null;

			$media .= Html::element(
				'video',
				[
					'class'                => 'wp-block-cover__video-background intrinsic-ignore',
					'autoplay'             => true,
					'muted'                => true,
					'loop'                 => true,
					'playsinline'          => true,
					'src'                  => $url,
					'poster'               => $attributes['poster'] ?? null,
					'style'                => [ 'object-position' => $object_position ],
					'data-object-fit'      => 'cover',
					'data-object-position' => $object_position,
				]
			);
		}

		if ( 'embed-video' === $background_type && '' !== $url ) {
			$media .= Html::element(
				'figure',
				[ 'class' => 'wp-block-cover__video-background wp-block-cover__embed-background wp-block-embed' ],
				Html::element( 'div', [ 'class' => 'wp-block-embed__wrapper' ], Html::escape_text( $url ) )
			);
		}

		$gradient        = (string) ( $attributes['gradient'] ?? '' );
		$custom_gradient = (string) ( $attributes['customGradient'] ?? '' );
		$has_gradient    = '' !== $gradient || '' !== $custom_gradient;
		$dim_value       = null !== $dim_ratio ? (float) $dim_ratio : null;

		$background = Html::element(
			'span',
			[
				'aria-hidden' => 'true',
				'class'       => Html::classes(
					[
						'wp-block-cover__background',
						'' !== $overlay ? 'has-' . _wp_to_kebab_case( $overlay ) . '-background-color' : '',
						null === $dim_value || 50.0 === $dim_value ? '' : 'has-background-dim-' . ( 10 * (int) round( $dim_value / 10 ) ),
						'has-background-dim'      => null !== $dim_value,
						'wp-block-cover__gradient-background' => '' !== $url && $has_gradient && 0.0 !== $dim_value,
						'has-background-gradient' => $has_gradient,
						'' !== $gradient ? 'has-' . _wp_to_kebab_case( $gradient ) . '-gradient-background' : '',
					]
				),
				'style'       => [
					'background-color' => '' === $overlay ? ( $attributes['customOverlayColor'] ?? null ) : null,
					'background'       => '' !== $custom_gradient ? $custom_gradient : null,
				],
			]
		);

		$tag = $attributes['tagName'] ?? 'div';

		return [
			Html::open( $tag, $props ) . $media . $background . '<div class="wp-block-cover__inner-container">',
			'</div></' . $tag . '>',
		];
	}

	/**
	 * Embed.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function embed( array $attributes, Supports $supports ): array {
		$url = (string) ( $attributes['url'] ?? '' );

		if ( '' === $url ) {
			return [ '', '' ];
		}

		$type     = (string) ( $attributes['type'] ?? '' );
		$provider = (string) ( $attributes['providerNameSlug'] ?? '' );
		$classes  = Html::classes(
			[
				'' !== $type ? 'is-type-' . $type : '',
				'' !== $provider ? 'is-provider-' . $provider : '',
				'' !== $provider ? 'wp-block-embed-' . $provider : '',
			]
		);

		$content = Html::element( 'div', [ 'class' => 'wp-block-embed__wrapper' ], "\n" . Html::escape_text( $url ) . "\n" )
			. self::caption( $attributes );

		return [ Html::element( 'figure', $supports->props( $attributes, [ 'class' => $classes ] ), $content ), '' ];
	}

	/**
	 * Flexible Table (Flexible Table Block plugin).
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function flexible_table( array $attributes, Supports $supports ): array {
		if ( [] === ( $attributes['head'] ?? [] ) && [] === ( $attributes['body'] ?? [] ) && [] === ( $attributes['foot'] ?? [] ) ) {
			return [ '', '' ];
		}

		$justify = (string) ( $attributes['contentJustification'] ?? '' );
		$sticky  = (string) ( $attributes['sticky'] ?? '' );

		$props = $supports->props(
			$attributes,
			[
				'class' => Html::classes(
					[
						'' !== $justify ? 'is-content-justification-' . $justify : '',
						'is-scroll-on-pc'     => ! empty( $attributes['isScrollOnPc'] ),
						'is-scroll-on-mobile' => ! empty( $attributes['isScrollOnMobile'] ),
					]
				),
			]
		);

		$sections = '';

		foreach ( [ 'head', 'body', 'foot' ] as $section ) {
			$sections .= self::table_section(
				$section,
				$attributes[ $section ] ?? [],
				fn( array $cell ) => Html::element(
					(string) ( $cell['tag'] ?? 'td' ),
					[
						'class'   => ( $cell['className'] ?? '' ) !== '' ? $cell['className'] : null,
						'id'      => 'th' === ( $cell['tag'] ?? 'td' ) && ! empty( $cell['id'] ) ? $cell['id'] : null,
						'headers' => ! empty( $cell['headers'] ) ? $cell['headers'] : null,
						'scope'   => 'th' === ( $cell['tag'] ?? 'td' ) && ! empty( $cell['scope'] ) ? $cell['scope'] : null,
						'rowspan' => (int) ( $cell['rowSpan'] ?? 0 ) > 1 ? (int) $cell['rowSpan'] : null,
						'colspan' => (int) ( $cell['colSpan'] ?? 0 ) > 1 ? (int) $cell['colSpan'] : null,
						'style'   => self::inline_styles( (string) ( $cell['styles'] ?? '' ) ),
					],
					(string) ( $cell['content'] ?? '' )
				)
			);
		}

		$table = Html::element(
			'table',
			[
				'class' => Html::classes(
					[
						$supports->color_classes( $attributes, true ),
						'has-fixed-layout'     => ! empty( $attributes['hasFixedLayout'] ),
						'is-stacked-on-mobile' => ! empty( $attributes['isStackedOnMobile'] ),
						'' !== $sticky ? 'is-sticky-' . $sticky : '',
					]
				),
				'style' => array_merge( self::inline_styles( (string) ( $attributes['tableStyles'] ?? '' ) ), $supports->group_styles( $attributes, [ 'color' ] ) ),
			],
			$sections,
			// The block writes the class attribute even when it is empty.
			[ 'class' ]
		);

		$caption      = (string) ( $attributes['caption'] ?? '' );
		$caption_html = '' !== $caption ? Html::element( 'figcaption', [ 'style' => self::inline_styles( (string) ( $attributes['captionStyles'] ?? '' ) ) ], $caption ) : '';
		$on_top       = 'top' === ( $attributes['captionSide'] ?? 'bottom' );

		return [ Html::element( 'figure', $props, ( $on_top ? $caption_html : '' ) . $table . ( $on_top ? '' : $caption_html ) ), '' ];
	}

	/**
	 * Gallery.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function gallery( array $attributes, Supports $supports ): array {
		$caption = (string) ( $attributes['caption'] ?? '' );
		$caption = '' !== $caption ? Html::element( 'figcaption', [ 'class' => 'blocks-gallery-caption wp-element-caption' ], $caption ) : '';

		if ( ! empty( $attributes['dynamicContent'] ) ) {
			return [ $caption, '' ];
		}

		$columns = $attributes['columns'] ?? null;
		$classes = Html::classes(
			[
				'has-nested-images',
				null !== $columns ? 'columns-' . Html::number( $columns ) : 'columns-default',
				'is-cropped' => ! empty( $attributes['imageCrop'] ),
			]
		);

		return [ Html::open( 'figure', $supports->props( $attributes, [ 'class' => $classes ] ) ), $caption . '</figure>' ];
	}

	/**
	 * Group.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function group( array $attributes, Supports $supports ): array {
		$tag = $attributes['tagName'] ?? 'div';

		return [ Html::open( $tag, $supports->props( $attributes ) ), '</' . $tag . '>' ];
	}

	/**
	 * Heading.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function heading( array $attributes, Supports $supports ): array {
		$tag = 'h' . (int) ( $attributes['level'] ?? 2 );

		return [ Html::element( $tag, $supports->props( $attributes ), (string) ( $attributes['content'] ?? '' ) ), '' ];
	}

	/**
	 * Image.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function image( array $attributes, Supports $supports ): array {
		$width     = $attributes['width'] ?? null;
		$height    = $attributes['height'] ?? null;
		$size_slug = (string) ( $attributes['sizeSlug'] ?? '' );
		$id        = (int) ( $attributes['id'] ?? 0 );

		$border_classes = $supports->border_classes( $attributes );
		$border_styles  = $supports->group_styles( $attributes, [ 'border' ] );

		$style = array_merge(
			$border_styles,
			$supports->group_styles( $attributes, [ 'shadow' ] ),
			[
				'aspect-ratio'    => $attributes['aspectRatio'] ?? null,
				'object-fit'      => $attributes['scale'] ?? null,
				'object-position' => ! empty( $attributes['focalPoint'] ) && ! empty( $attributes['scale'] ) ? self::media_position( $attributes['focalPoint'] ) : null,
			]
		);

		if ( null !== $width || null !== $height ) {
			if ( 'auto' === $width ) {
				$style['width'] = 'auto';
			} elseif ( null !== $width ) {
				$style['width'] = is_numeric( $width ) && ! is_string( $width ) ? self::number( $width ) . 'px' : $width;
			}

			$style['height'] = 'auto' === $height || null === $height ? 'auto' : ( is_numeric( $height ) && ! is_string( $height ) ? self::number( $height ) . 'px' : $height );
		}

		$image = Html::open(
			'img',
			[
				'src'   => $attributes['url'] ?? null,
				'alt'   => (string) ( $attributes['alt'] ?? '' ),
				'class' => Html::classes( [ $border_classes, $id > 0 ? 'wp-image-' . $id : '' ] ),
				'style' => $style,
				'title' => $attributes['title'] ?? null,
				'role'  => ! empty( $attributes['isDecorative'] ) ? 'none' : null,
			]
		);

		if ( ! empty( $attributes['href'] ) ) {
			$image = Html::element(
				'a',
				[
					'class'  => $attributes['linkClass'] ?? null,
					'href'   => $attributes['href'],
					'target' => $attributes['linkTarget'] ?? null,
					'rel'    => $attributes['rel'] ?? null,
				],
				$image
			);
		}

		$classes = Html::classes(
			[
				'alignnone'         => 'none' === ( $attributes['align'] ?? '' ),
				'' !== $size_slug ? 'size-' . $size_slug : '',
				'is-resized'        => ! empty( $width ) || ! empty( $height ),
				'has-custom-border' => '' !== $border_classes || [] !== $border_styles,
			]
		);

		return [ Html::element( 'figure', $supports->props( $attributes, [ 'class' => $classes ] ), $image . self::caption( $attributes ) ), '' ];
	}

	/**
	 * List.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function list( array $attributes, Supports $supports ): array {
		$ordered = ! empty( $attributes['ordered'] );
		$tag     = $ordered ? 'ol' : 'ul';
		$type    = $attributes['type'] ?? null;

		$props = $supports->props(
			$attributes,
			[
				'reversed' => ! empty( $attributes['reversed'] ) ? true : null,
				'start'    => $attributes['start'] ?? null,
				'style'    => [ 'list-style-type' => $ordered && null !== $type && 'decimal' !== $type ? $type : null ],
			]
		);

		return [ Html::open( $tag, $props ), '</' . $tag . '>' ];
	}

	/**
	 * List item.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function list_item( array $attributes, Supports $supports ): array {
		return [ Html::open( 'li', $supports->props( $attributes ) ) . (string) ( $attributes['content'] ?? '' ), '</li>' ];
	}

	/**
	 * Paragraph.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function paragraph( array $attributes, Supports $supports ): array {
		// A drop cap shows only on text aligned to the start of the line.
		$text_align = (string) ( $attributes['style']['typography']['textAlign'] ?? '' );
		$line_end   = is_rtl() ? 'left' : 'right';
		$drop_cap   = ! empty( $attributes['dropCap'] ) && 'center' !== $text_align && $line_end !== $text_align;

		$props = $supports->props(
			$attributes,
			[
				'class' => $drop_cap ? 'has-drop-cap' : '',
				'dir'   => $attributes['direction'] ?? null,
			]
		);

		return [ Html::element( 'p', $props, (string) ( $attributes['content'] ?? '' ) ), '' ];
	}

	/**
	 * Query loop.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function query( array $attributes, Supports $supports ): array {
		$tag = $attributes['tagName'] ?? 'div';

		return [ Html::open( $tag, $supports->props( $attributes ) ), '</' . $tag . '>' ];
	}

	/**
	 * Quote.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function quote( array $attributes, Supports $supports ): array {
		$align    = (string) ( $attributes['textAlign'] ?? '' );
		$citation = (string) ( $attributes['citation'] ?? '' );

		return [
			Html::open( 'blockquote', $supports->props( $attributes, [ 'class' => '' !== $align ? 'has-text-align-' . $align : '' ] ) ),
			( '' !== $citation ? Html::element( 'cite', [], $citation ) : '' ) . '</blockquote>',
		];
	}

	/**
	 * More: the `more` comment, with its custom text, and the `noteaser` comment.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function more( array $attributes, Supports $supports ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Every save takes the block supports.
		$custom_text = (string) ( $attributes['customText'] ?? '' );
		$tags        = [ '' !== $custom_text ? '<!--more ' . $custom_text . '-->' : '<!--more-->' ];

		if ( ! empty( $attributes['noTeaser'] ) ) {
			$tags[] = '<!--noteaser-->';
		}

		return [ implode( "\n", $tags ), '' ];
	}

	/**
	 * Page Break: the `nextpage` comment.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function nextpage( array $attributes, Supports $supports ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Every save takes the attributes and block supports.
		return [ '<!--nextpage-->', '' ];
	}

	/**
	 * Separator, with its default opacity. The background color also sets
	 * the text color, which colors the dots style.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function separator( array $attributes, Supports $supports ): array {
		$opacity      = $attributes['opacity'] ?? 'alpha-channel';
		$background   = (string) ( $attributes['backgroundColor'] ?? '' );
		$custom_color = is_string( $attributes['style']['color']['background'] ?? null ) ? $attributes['style']['color']['background'] : null;
		$color_styles = $supports->group_styles( $attributes, [ 'color' ] );

		$classes = Html::classes(
			[
				'has-text-color'            => '' !== $background || ! empty( $custom_color ),
				'' !== $background ? 'has-' . _wp_to_kebab_case( $background ) . '-color' : '',
				'has-css-opacity'           => 'css' === $opacity,
				'has-alpha-channel-opacity' => 'alpha-channel' === $opacity,
				$supports->color_classes( $attributes, true ),
			]
		);

		$style = [
			'background-color' => $color_styles['background-color'] ?? null,
			'color'            => '' === $background ? $custom_color : null,
		];

		return [
			Html::open(
				$attributes['tagName'] ?? 'hr',
				$supports->props(
					$attributes,
					[
						'class' => $classes,
						'style' => $style,
					]
				)
			),
			'',
		];
	}

	/**
	 * Shortcode, without HTML comments: a block delimiter in the text would
	 * end the block early.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function shortcode( array $attributes, Supports $supports ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Every save takes the block supports.
		return [ Html::strip_comments( (string) ( $attributes['text'] ?? '' ) ), '' ];
	}

	/**
	 * Table.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function table( array $attributes, Supports $supports ): array {
		if ( [] === ( $attributes['head'] ?? [] ) && [] === ( $attributes['body'] ?? [] ) && [] === ( $attributes['foot'] ?? [] ) ) {
			return [ '', '' ];
		}

		$sections = '';

		foreach ( [ 'head', 'body', 'foot' ] as $section ) {
			$sections .= self::table_section(
				$section,
				$attributes[ $section ] ?? [],
				function ( array $cell ) {
					$tag   = (string) ( $cell['tag'] ?? 'td' );
					$align = (string) ( $cell['align'] ?? '' );

					return Html::element(
						$tag,
						[
							'class'      => '' !== $align ? 'has-text-align-' . $align : null,
							'data-align' => '' !== $align ? $align : null,
							'scope'      => 'th' === $tag && ! empty( $cell['scope'] ) ? $cell['scope'] : null,
							'colspan'    => $cell['colspan'] ?? null,
							'rowspan'    => $cell['rowspan'] ?? null,
						],
						(string) ( $cell['content'] ?? '' )
					);
				}
			);
		}

		$table = Html::element(
			'table',
			[
				'class' => Html::classes(
					[
						$supports->color_classes( $attributes, true ),
						$supports->border_classes( $attributes ),
						'has-fixed-layout' => ! empty( $attributes['hasFixedLayout'] ),
					]
				),
				'style' => $supports->group_styles( $attributes, [ 'color', 'border' ] ),
			],
			$sections
		);

		return [ Html::element( 'figure', $supports->props( $attributes ), $table . self::caption( $attributes ) ), '' ];
	}

	/**
	 * Video.
	 *
	 * @param array    $attributes Attributes.
	 * @param Supports $supports   Block supports.
	 * @return array{0: string, 1: string}
	 */
	public static function video( array $attributes, Supports $supports ): array {
		$src     = (string) ( $attributes['src'] ?? '' );
		$width   = $attributes['width'] ?? null;
		$height  = $attributes['height'] ?? null;
		$preload = $attributes['preload'] ?? 'metadata';
		$video   = '';

		if ( '' !== $src ) {
			$video = Html::element(
				'video',
				[
					'autoplay'    => ! empty( $attributes['autoplay'] ),
					'controls'    => ! empty( $attributes['controls'] ),
					'loop'        => ! empty( $attributes['loop'] ),
					'muted'       => ! empty( $attributes['muted'] ),
					'poster'      => $attributes['poster'] ?? null,
					'preload'     => 'metadata' !== $preload ? $preload : null,
					'src'         => $src,
					'playsinline' => ! empty( $attributes['playsInline'] ),
					'width'       => $width,
					'height'      => $height,
					'style'       => [ 'aspect-ratio' => $width && $height ? $width . ' / ' . $height : null ],
				]
			);
		}

		return [ Html::element( 'figure', $supports->props( $attributes ), $video . self::caption( $attributes ) ), '' ];
	}

	/**
	 * Returns a table section (`thead`, `tbody`, `tfoot`), or an empty string
	 * when it has no rows.
	 *
	 * @param string   $section Section: `head`, `body` or `foot`.
	 * @param array    $rows    Rows, each with its `cells`.
	 * @param callable $cell    Returns a cell's HTML.
	 * @return string
	 */
	private static function table_section( string $section, array $rows, callable $cell ): string {
		if ( [] === $rows ) {
			return '';
		}

		$html = '';

		foreach ( $rows as $row ) {
			$html .= Html::element( 'tr', [], implode( '', array_map( $cell, (array) ( $row['cells'] ?? [] ) ) ) );
		}

		return Html::element( 't' . $section, [], $html );
	}

	/**
	 * Returns CSS declarations from an inline style attribute: property
	 * names in lowercase, values trimmed.
	 *
	 * @param string $styles Inline styles.
	 * @return array<string, string>
	 */
	private static function inline_styles( string $styles ): array {
		$declarations = [];

		foreach ( explode( ';', $styles ) as $style ) {
			$separator = strpos( $style, ':' );

			if ( false === $separator ) {
				continue;
			}

			$property = trim( substr( $style, 0, $separator ) );
			$value    = trim( substr( $style, $separator + 1 ) );

			if ( '' !== $property && '' !== $value ) {
				$declarations[ str_starts_with( $property, '--' ) ? $property : strtolower( $property ) ] = $value;
			}
		}

		return $declarations;
	}

	/**
	 * Returns a figure caption, or an empty string.
	 *
	 * @param array $attributes Attributes.
	 * @return string
	 */
	private static function caption( array $attributes ): string {
		$caption = (string) ( $attributes['caption'] ?? '' );

		return '' !== $caption ? Html::element( 'figcaption', [ 'class' => 'wp-element-caption' ], $caption ) : '';
	}

	/**
	 * Returns a focal point as a CSS position.
	 *
	 * @param array $focal_point Focal point, `x` and `y` from 0 to 1.
	 * @return string
	 */
	private static function media_position( array $focal_point ): string {
		$x = (float) ( $focal_point['x'] ?? 0.5 );
		$y = (float) ( $focal_point['y'] ?? 0.5 );

		return round( $x * 100 ) . '% ' . round( $y * 100 ) . '%';
	}

	/**
	 * Formats a number as JavaScript prints it: no trailing zeros.
	 *
	 * @param int|float $value Number.
	 * @return string
	 */
	private static function number( int|float $value ): string {
		return Html::number( $value );
	}
}
