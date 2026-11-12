<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Settings;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Styles\Values;
use S3S\WP\DiviMigration\Targets\CarouselData;
use S3S\WP\DiviMigration\Targets\CarouselTarget;
use S3S\WP\DiviMigration\Targets\Slide;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a slider module, or a full-width slider module, and hands it to the
 * carousel target.
 *
 * Each slide's background, title, text, button and image become a slide's
 * blocks; the target decides which carousel block holds them.
 */
class Slider extends Module {

	/**
	 * Default time between slides, in milliseconds, when autoplay is on.
	 */
	const DEFAULT_INTERVAL = 7000;

	/**
	 * Divi's slide text size, as a multiple of the body font size.
	 */
	const TEXT_SCALE = 1.14;

	/**
	 * Divi's slide text color on a light background.
	 */
	const LIGHT_TEXT_COLOR = '#333333';

	/**
	 * Divi's side padding of a slide, as a percentage of the slide width.
	 */
	const SLIDE_SIDE_PADDING = 6;

	/**
	 * Divi's padding of a slide's text box, as percentages of the slide's
	 * content width.
	 */
	const DESCRIPTION_PADDING = [
		'top'    => '16%',
		'right'  => '8%',
		'bottom' => '16%',
		'left'   => '8%',
	];

	/**
	 * Constructor.
	 *
	 * @param Rule $fallback Rule for a slider without a carousel target.
	 */
	public function __construct( private Rule $fallback = new Fallback() ) {}

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$slides = [];

		foreach ( $node->children as $child ) {
			if ( 'et_pb_slide' === $child->tag ) {
				$slides[] = $this->slide( $child, $node, $converter );
			}
		}

		if ( [] === $slides ) {
			return [];
		}

		$target = $converter->target( 'carousel', $node->tag );

		if ( ! $target instanceof CarouselTarget ) {
			$converter->note( $node->tag, 'kept: no carousel plugin' );
			return $this->fallback->convert( $node, $converter );
		}

		// The slider's padding applies to each slide.
		$style = new StyleSet();
		$this->design( $node, $style, $converter->presets, [ 'background', 'height', 'padding' ], $converter );

		$attributes          = $this->common_attributes( $node );
		$attributes['style'] = $style->to_style();

		return $target->build(
			new CarouselData(
				slides: $slides,
				autoplay: $node->is_on( 'auto' ),
				interval: (int) $node->attr( 'auto_speed', (string) self::DEFAULT_INTERVAL ),
				pause_on_hover: ! $node->is_on( 'auto_ignore_hover' ),
				height: Values::responsive( $node, 'min_height' )['desktop'],
				arrows: 'off' !== $node->attr( 'show_arrows', 'on' ),
				dots: 'off' !== $node->attr( 'show_pagination', 'on' ),
				arrow_color: $converter->presets->color( $node->attr( 'arrows_custom_color' ) ),
				dot_color: $converter->presets->color( $node->attr( 'dot_nav_custom_color' ) ),
				fullwidth: 'et_pb_fullwidth_slider' === $node->tag,
				attributes: $attributes,
			)
		);
	}

	/**
	 * Reads one slide.
	 *
	 * Text settings on the slider apply to every slide; a slide's own
	 * settings win.
	 *
	 * @param Node      $slide     Slide node.
	 * @param Node      $slider    Slider node.
	 * @param Converter $converter Converter.
	 * @return Slide
	 */
	private function slide( Node $slide, Node $slider, Converter $converter ): Slide {
		$inherited = array_filter(
			$slider->attrs,
			function ( $value, $name ) {
				$matches = preg_match( '/^(header_|body_|button_|custom_button|text_orientation|background_layout)/', (string) $name );

				return '' !== $value && 1 === $matches;
			},
			ARRAY_FILTER_USE_BOTH
		);

		$merged          = new Node( $slide->tag, array_merge( $inherited, array_filter( $slide->attrs, fn( $value ) => '' !== $value ) ) );
		$merged->content = $slide->content;

		$style = new StyleSet();
		$this->design( $merged, $style, $converter->presets, [ 'margin', 'background', 'padding' ], $converter );
		$this->padding( $slide, $slider, $style, $converter->settings );

		$this->note_unconverted( $slide, $converter, 'slide video', [ 'video_url' ] );

		$blocks = $this->content( $merged, $converter );

		$style_attribute = $style->to_style();

		// Divi's dark background layout sets light text on the slide.
		$layout = $merged->attr( 'background_layout', 'dark' );

		if ( [] !== $blocks && 'dark' === $layout ) {
			$style_attribute['color']['text'] ??= $converter->presets->color( '#ffffff' );
		}

		return new Slide(
			blocks: $blocks,
			background: $this->background( $merged, $converter ),
			style: $style_attribute,
			attributes: $this->common_attributes( $slide ),
		);
	}

	/**
	 * Returns the blocks inside a slide: heading, text, button and image.
	 *
	 * @param Node      $slide     Slide node, with the slider's text settings merged in.
	 * @param Converter $converter Converter.
	 * @return array[]
	 */
	private function content( Node $slide, Converter $converter ): array {
		$blocks = [];
		$align  = $this->alignment( $slide, 'text_orientation', $converter )['desktop'];
		$align  = '' !== $align ? $align : 'center';

		$heading = $slide->attr( 'heading' );

		if ( '' !== $heading ) {
			$heading_style = new StyleSet();
			$this->typography( $slide, 'header', $heading_style, $converter->presets );
			$heading_style->set_value( [ 'typography', 'textAlign' ], $align );

			$blocks[] = $this->heading_block( esc_html( $heading ), $this->heading_level( $slide, 'header_level', 2 ), $heading_style );
		}

		$text = trim( $slide->content );

		if ( '' !== $text ) {
			$apply    = [ 'core/paragraph' => [ 'style' => [ 'typography' => [ 'textAlign' => $align ] ] ] ];
			$blocks[] = Spec::block(
				'core/group',
				[
					'className' => $converter->settings->class_name( 'text' ),
					'layout'    => [ 'type' => 'default' ],
					'style'     => $this->text_style( $slide, $converter )->to_style(),
				],
				[ Spec::raw( wpautop( $text ), $apply ) ]
			);
		}

		$show_button = $slide->attr( 'show_button', 'on' );

		if ( 'off' !== $show_button ) {
			array_push( $blocks, ...$this->button_block( $slide, 'button', $slide->attr( 'button_text' ), $slide->attr( 'button_link' ), $converter, $align ) );
		}

		$image = $slide->attr( 'image' );

		if ( '' !== $image ) {
			$image_attributes = [
				'url'      => $image,
				'alt'      => $slide->attr( 'image_alt' ),
				'sizeSlug' => 'full',
			];

			$image_attributes = $this->with_attachment_id( $image_attributes, $image );

			$blocks[] = Spec::block( 'core/image', $image_attributes );
		}

		return $blocks;
	}

	/**
	 * Returns a slide's background: image, position, size, repeat and color.
	 *
	 * @param Node      $slide     Slide node.
	 * @param Converter $converter Converter.
	 * @return array
	 */
	private function background( Node $slide, Converter $converter ): array {
		$background = [];

		$enable_color = $slide->attr( 'background_enable_color', 'on' );
		$enable_image = $slide->attr( 'background_enable_image', 'on' );

		if ( 'off' !== $enable_color ) {
			$background['color'] = $converter->presets->color( $slide->attr( 'background_color' ) );
		}

		$image = $slide->attr( 'background_image' );

		if ( '' !== $image && 'off' !== $enable_image ) {
			$size = $slide->attr( 'background_size', 'cover' );

			$background['image']    = $image;
			$background['id']       = max( 0, attachment_url_to_postid( $image ) );
			$background['position'] = Values::position( $slide->attr( 'background_position', 'center' ) );
			$background['size']     = 'initial' === $size ? 'auto' : $size;
			$background['repeat']   = $slide->attr( 'background_repeat', 'no-repeat' );
		}

		return array_filter( $background, fn( $value ) => '' !== $value && 0 !== $value );
	}

	/**
	 * Adds a slide's padding to its styles.
	 *
	 * Divi pads a slide by 0 6% and the slide's text box by 16% 8% of the
	 * slide's content width. The slide block holds both paddings. The custom
	 * padding of the slider, and then of the slide, replaces the text box
	 * padding per side. A slide with an image has no side padding on its
	 * text box. An empty tablet or phone side takes the side of the next
	 * larger viewport.
	 *
	 * @param Node     $slide    Slide node.
	 * @param Node     $slider   Slider node.
	 * @param StyleSet $style    Slide styles.
	 * @param Settings $settings Converter settings.
	 * @return void
	 */
	private function padding( Node $slide, Node $slider, StyleSet $style, Settings $settings ): void {
		$defaults = self::DESCRIPTION_PADDING;
		$cap      = Settings::DEFAULT_CONTENT_WIDTH !== $settings->content_width ? $settings->content_width : 0;

		$image = $slide->attr( 'image' );

		if ( '' !== $image ) {
			$defaults['right'] = '0px';
			$defaults['left']  = '0px';
		}

		$sources = [
			array_map( [ Values::class, 'spacing' ], Values::responsive( $slide, 'custom_padding' ) ),
			array_map( [ Values::class, 'spacing' ], Values::responsive( $slider, 'custom_padding' ) ),
		];

		foreach ( $defaults as $side => $default ) {
			$per_viewport = [];

			foreach ( [ 'desktop', 'tablet', 'phone' ] as $viewport ) {
				$value = self::cascade( $sources, $viewport, $side, $default );

				$per_viewport[ $viewport ] = self::slide_padding( $side, $value, $cap );
			}

			$style->set( [ 'spacing', 'padding', $side ], $per_viewport );
		}
	}

	/**
	 * Returns the style of a slide's text group.
	 *
	 * Divi sets slide text to weight 400 and, on a light background, to
	 * #333333. Its size is 1.14 times the body size of each viewport that has
	 * its own body size, and 14px when the body size is Divi's default. The
	 * text inherits the body line height in pixels. A body font size or line
	 * height set on the slide or slider applies at every viewport, unless it
	 * has its own tablet or phone value.
	 *
	 * @param Node      $slide     Slide node, with the slider's text settings merged in.
	 * @param Converter $converter Converter.
	 * @return StyleSet
	 */
	private function text_style( Node $slide, Converter $converter ): StyleSet {
		$settings = $converter->settings;
		$style    = new StyleSet();

		$font_size   = $slide->attr( 'body_font_size' );
		$line_height = $slide->attr( 'body_line_height' );
		$layout      = $slide->attr( 'background_layout', 'dark' );

		if ( '' === $font_size ) {
			$style->set( [ 'typography', 'fontSize' ], array_map( [ $converter->presets, 'font_size' ], self::text_sizes( $settings ) ) );
		}

		if ( '' === $line_height ) {
			$style->set( [ 'typography', 'lineHeight' ], $settings->body_line_heights() );
		}

		$style->set_value( [ 'typography', 'fontWeight' ], '400' );

		if ( 'light' === $layout ) {
			$style->set_value( [ 'color', 'text' ], $converter->presets->color( self::LIGHT_TEXT_COLOR ) );
		}

		$this->typography( $slide, 'body', $style, $converter->presets );

		return $style;
	}

	/**
	 * Returns Divi's slide text size per viewport.
	 *
	 * @param Settings $settings Converter settings.
	 * @return array{desktop: string, tablet: string, phone: string}
	 */
	private static function text_sizes( Settings $settings ): array {
		$default = Settings::DEFAULT_BODY_FONT_SIZE;
		$desktop = (int) $settings->body_font['desktop'];
		$tablet  = (int) $settings->body_font['tablet'];
		$phone   = (int) $settings->body_font['phone'];

		$has_tablet_rule = $default !== $tablet && $desktop !== $tablet;
		$has_phone_rule  = $default !== $phone && $tablet !== $phone;

		$sizes            = [];
		$sizes['desktop'] = $default !== $desktop ? (int) ( $desktop * self::TEXT_SCALE ) : $default;
		$sizes['tablet']  = $has_tablet_rule ? (int) ( $tablet * self::TEXT_SCALE ) : $sizes['desktop'];
		$sizes['phone']   = $has_phone_rule ? (int) ( $phone * self::TEXT_SCALE ) : $sizes['tablet'];

		return array_map( fn( int $size ) => $size . 'px', $sizes );
	}

	/**
	 * Returns the padding side that Divi's CSS applies at a viewport.
	 *
	 * The first source wins over the second at every viewport. In a source,
	 * an empty side takes the side of the next larger viewport.
	 *
	 * @param array<int, array<string, array<string, string>>> $sources  Parsed padding per viewport, the strongest source first.
	 * @param string                                           $viewport Viewport: `desktop`, `tablet` or `phone`.
	 * @param string                                           $side     Side: `top`, `right`, `bottom` or `left`.
	 * @param string                                           $fallback Value when no source sets the side.
	 * @return string
	 */
	private static function cascade( array $sources, string $viewport, string $side, string $fallback ): string {
		$viewports = array_slice( [ 'phone', 'tablet', 'desktop' ], array_search( $viewport, [ 'phone', 'tablet', 'desktop' ], true ) );

		foreach ( $sources as $source ) {
			foreach ( $viewports as $candidate ) {
				$value = $source[ $candidate ][ $side ] ?? '';

				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return $fallback;
	}

	/**
	 * Converts a text box padding side into a slide padding side.
	 *
	 * The text box is 88% of the slide wide: the slide minus its 6% side
	 * padding. A percentage of the text box shrinks by that factor, and a
	 * left or right side adds the slide's 6%. When Divi's content width is
	 * not its default, Divi also caps the text box at that width and centers
	 * it, and the sides become CSS calculations.
	 *
	 * @param string $side  Side: `top`, `right`, `bottom` or `left`.
	 * @param string $value Text box padding.
	 * @param int    $cap   Maximum text box width in pixels, or 0 for none.
	 * @return string
	 */
	private static function slide_padding( string $side, string $value, int $cap ): string {
		$is_horizontal = in_array( $side, [ 'left', 'right' ], true );
		$is_percent    = str_ends_with( $value, '%' );
		$is_zero       = 1 === preg_match( '/^0+(\.0+)?[a-z]*$/', $value );
		$box_percent   = 100 - 2 * self::SLIDE_SIDE_PADDING;

		if ( 0 === $cap ) {
			$extra = $is_horizontal ? self::SLIDE_SIDE_PADDING : 0;

			if ( $is_percent ) {
				return round( (float) $value * $box_percent / 100 + $extra, 4 ) . '%';
			}

			if ( ! $is_horizontal ) {
				return $value;
			}

			return $is_zero ? $extra . '%' : 'calc(' . $extra . '% + ' . $value . ')';
		}

		$box = 'min(' . $box_percent . '%, ' . $cap . 'px)';

		if ( ! $is_horizontal ) {
			return $is_percent ? 'calc(' . $box . ' * ' . round( (float) $value / 100, 4 ) . ')' : $value;
		}

		// The text box starts at the middle of the slide minus half the text box.
		if ( $is_percent ) {
			return 'calc(50% - ' . $box . ' * ' . round( 0.5 - (float) $value / 100, 4 ) . ')';
		}

		return $is_zero ? 'calc(50% - ' . $box . ' / 2)' : 'calc(50% - ' . $box . ' / 2 + ' . $value . ')';
	}
}
