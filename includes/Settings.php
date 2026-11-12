<?php

namespace S3S\WP\DiviMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Site-wide values the rules need: Divi's layout defaults, the class
 * names the theme styles and the image sizes the theme registers.
 */
class Settings {

	/**
	 * Column gap per Divi gutter width, as a share of the row width.
	 */
	const GUTTERS = [
		1 => '0%',
		2 => '3%',
		3 => '5.5%',
		4 => '8%',
	];

	/**
	 * Divi's default top and bottom row padding at 980px and below, in pixels.
	 */
	const DEFAULT_ROW_HEIGHT = 30;

	/**
	 * Divi's default body font size, in pixels.
	 */
	const DEFAULT_BODY_FONT_SIZE = 14;

	/**
	 * Divi's default body line height, in `em`.
	 */
	const DEFAULT_BODY_LINE_HEIGHT = 1.7;

	/**
	 * Divi's default content width, in pixels.
	 */
	const DEFAULT_CONTENT_WIDTH = 1080;

	/**
	 * Divi's default heading size option (the h1 size), in pixels.
	 */
	const DEFAULT_HEADER_FONT_SIZE = 30;

	/**
	 * Creates the settings.
	 *
	 * @param int                   $gutter_width Default Divi gutter width, 1 to 4.
	 * @param array<string, string> $class_names  Class names for converted layout blocks, keyed by `section`, `section_fullwidth`, `row`, `row_inner`, `text` and `testimonial`.
	 * @param string                $sidebar_part Template part slug that replaces sidebar modules.
	 * @param array<string, string> $targets      Target name per kind (`carousel`, `accordion`, `table`, `form`), or `auto` for the
	 *                                            first target whose blocks are registered.
	 * @param string                $menus        How menus convert: `classic` keeps the classic menus, assigned to
	 *                                            the same locations, and binds Navigation blocks to the locations;
	 *                                            `navigation` copies them into Navigation posts.
	 * @param array<string, int>    $row_heights  Divi's top and bottom row padding in pixels at 980px and below, keyed by `tablet` and `phone`.
	 * @param array<string, float>  $body_font    Divi's body font options: the size in pixels per viewport, keyed by `desktop`,
	 *                                            `tablet` and `phone`, and the line height as a multiple of the size, keyed by `line_height`.
	 * @param int                   $content_width Divi's content width in pixels.
	 * @param array<string, int>    $header_font  Divi's heading size option in pixels per viewport, keyed by `desktop`, `tablet` and `phone`.
	 * @param array<string, string> $image_sizes  Image size slugs of blog post images, keyed by `card` (grid lists) and `wide` (full-width lists).
	 */
	public function __construct(
		public int $gutter_width = 3,
		public array $class_names = [],
		public string $sidebar_part = 'sidebar',
		public array $targets = [],
		public string $menus = 'classic',
		public array $row_heights = [
			'tablet' => self::DEFAULT_ROW_HEIGHT,
			'phone'  => self::DEFAULT_ROW_HEIGHT,
		],
		public array $body_font = [
			'desktop'     => self::DEFAULT_BODY_FONT_SIZE,
			'tablet'      => self::DEFAULT_BODY_FONT_SIZE,
			'phone'       => self::DEFAULT_BODY_FONT_SIZE,
			'line_height' => self::DEFAULT_BODY_LINE_HEIGHT,
		],
		public int $content_width = self::DEFAULT_CONTENT_WIDTH,
		public array $header_font = [
			'desktop' => self::DEFAULT_HEADER_FONT_SIZE,
			'tablet'  => self::DEFAULT_HEADER_FONT_SIZE,
			'phone'   => self::DEFAULT_HEADER_FONT_SIZE,
		],
		public array $image_sizes = [],
	) {
		$this->class_names = array_merge(
			[
				'section'           => 'is-style-section',
				'section_fullwidth' => 'is-style-section-fullwidth',
				'row'               => 'is-style-row',
				'row_inner'         => 'is-style-row-inner',
				'text'              => 'is-style-text',
				'testimonial'       => 'is-style-testimonial',
			],
			$class_names
		);

		$this->image_sizes = array_merge(
			[
				'card' => 'post-card',
				'wide' => 'post-wide',
			],
			$image_sizes
		);
	}

	/**
	 * Reads the settings from Divi's theme options and the migration map.
	 *
	 * @param Map|null $map Migration map; its settings win over Divi's options.
	 * @return Settings
	 */
	public static function from_options( ?Map $map = null ): Settings {
		$options = get_option( 'et_divi', [] );
		$options = is_array( $options ) ? $options : [];
		$values  = $map?->settings ?? [];
		$gutter  = (int) ( $values['gutter'] ?? $options['gutter_width'] ?? 3 );

		$settings = new self(
			gutter_width: isset( self::GUTTERS[ $gutter ] ) ? $gutter : 3,
			class_names: (array) ( $values['classNames'] ?? [] ),
			sidebar_part: (string) ( $values['sidebarPart'] ?? 'sidebar' ),
			targets: (array) ( $values['targets'] ?? [] ),
			menus: (string) ( $values['menus'] ?? 'classic' ),
			row_heights: self::row_heights( $options ),
			body_font: self::body_font( $options ),
			content_width: isset( $options['content_width'] ) ? absint( $options['content_width'] ) : self::DEFAULT_CONTENT_WIDTH,
			header_font: self::header_font( $options ),
			image_sizes: (array) ( $values['imageSizes'] ?? [] ),
		);

		/**
		 * Filters the settings the conversion rules use.
		 *
		 * @param Settings $settings Settings read from Divi's theme options.
		 * @param array    $options  Divi's theme options.
		 */
		return apply_filters( 's3s_divi_migration_settings', $settings, $options );
	}

	/**
	 * Returns the column gap for a gutter width.
	 *
	 * @param int $gutter_width Divi gutter width, 1 to 4.
	 * @return string
	 */
	public function gutter( int $gutter_width ): string {
		return self::GUTTERS[ $gutter_width ] ?? self::GUTTERS[ $this->gutter_width ];
	}

	/**
	 * Returns the class name for a layout role, or an empty string.
	 *
	 * @param string $role Layout role, such as `row`.
	 * @return string
	 */
	public function class_name( string $role ): string {
		return $this->class_names[ $role ] ?? '';
	}

	/**
	 * Returns the image size slug for a blog post image, or an empty string.
	 *
	 * @param string $role Image size role: `card` or `wide`.
	 * @return string
	 */
	public function image_size( string $role ): string {
		return $this->image_sizes[ $role ] ?? '';
	}

	/**
	 * Returns the body font size Divi applies per viewport, in pixels.
	 *
	 * Divi prints a tablet size only when it differs from the default and
	 * from the desktop size, and a phone size only when it differs from the
	 * default and from the tablet size. A viewport without its own size
	 * takes the size of the next larger viewport.
	 *
	 * @return array{desktop: int, tablet: int, phone: int}
	 */
	public function body_font_sizes(): array {
		return self::viewport_sizes( $this->body_font, self::DEFAULT_BODY_FONT_SIZE );
	}

	/**
	 * Returns the heading size option Divi applies per viewport, in pixels.
	 *
	 * Divi derives the h1 to h6 sizes from it. It prints tablet and phone
	 * sizes under the same conditions as the body font size.
	 *
	 * @return array{desktop: int, tablet: int, phone: int}
	 */
	public function header_font_sizes(): array {
		return self::viewport_sizes( $this->header_font, self::DEFAULT_HEADER_FONT_SIZE );
	}

	/**
	 * Returns the body line height per viewport, as a pixel length.
	 *
	 * Divi sets the body line height in `em`, so text inside the body
	 * inherits it in pixels, whatever its own font size.
	 *
	 * @return array{desktop: string, tablet: string, phone: string}
	 */
	public function body_line_heights(): array {
		$line_height = (float) $this->body_font['line_height'];

		return array_map( fn( int $size ) => round( $size * $line_height, 2 ) . 'px', $this->body_font_sizes() );
	}

	/**
	 * Returns the row padding Divi applies at 980px and below.
	 *
	 * Divi prints a phone value only when it differs from the default and
	 * from the tablet value. Otherwise phones get the tablet value.
	 *
	 * @param array $options Divi's theme options.
	 * @return array<string, int>
	 */
	private static function row_heights( array $options ): array {
		$tablet = isset( $options['tablet_row_height'] ) ? absint( $options['tablet_row_height'] ) : self::DEFAULT_ROW_HEIGHT;
		$phone  = isset( $options['phone_row_height'] ) ? absint( $options['phone_row_height'] ) : $tablet;

		$has_phone_rule = self::DEFAULT_ROW_HEIGHT !== $phone && $tablet !== $phone;

		return [
			'tablet' => $tablet,
			'phone'  => $has_phone_rule ? $phone : $tablet,
		];
	}

	/**
	 * Returns the size Divi applies per viewport for a size option with
	 * tablet and phone values.
	 *
	 * Divi prints a tablet size only when it differs from the default and
	 * from the desktop size, and a phone size only when it differs from the
	 * default and from the tablet size. A viewport without its own size
	 * takes the size of the next larger viewport.
	 *
	 * @param array<string, float> $sizes   Option values, keyed by `desktop`, `tablet` and `phone`.
	 * @param int                  $base    Divi's default size.
	 * @return array{desktop: int, tablet: int, phone: int}
	 */
	private static function viewport_sizes( array $sizes, int $base ): array {
		$desktop = (int) $sizes['desktop'];
		$tablet  = (int) $sizes['tablet'];
		$phone   = (int) $sizes['phone'];

		$has_tablet_rule = $base !== $tablet && $desktop !== $tablet;
		$has_phone_rule  = $base !== $phone && $tablet !== $phone;

		$tablet_size = $has_tablet_rule ? $tablet : $desktop;

		return [
			'desktop' => $desktop,
			'tablet'  => $tablet_size,
			'phone'   => $has_phone_rule ? $phone : $tablet_size,
		];
	}

	/**
	 * Returns Divi's heading size options, with Divi's defaults.
	 *
	 * @param array $options Divi's theme options.
	 * @return array<string, int>
	 */
	private static function header_font( array $options ): array {
		$tablet = isset( $options['tablet_header_font_size'] ) ? absint( $options['tablet_header_font_size'] ) : self::DEFAULT_HEADER_FONT_SIZE;

		return [
			'desktop' => isset( $options['body_header_size'] ) ? absint( $options['body_header_size'] ) : self::DEFAULT_HEADER_FONT_SIZE,
			'tablet'  => $tablet,
			'phone'   => isset( $options['phone_header_font_size'] ) ? absint( $options['phone_header_font_size'] ) : $tablet,
		];
	}

	/**
	 * Returns Divi's body font options, with Divi's defaults.
	 *
	 * @param array $options Divi's theme options.
	 * @return array<string, float>
	 */
	private static function body_font( array $options ): array {
		$desktop = isset( $options['body_font_size'] ) ? absint( $options['body_font_size'] ) : self::DEFAULT_BODY_FONT_SIZE;
		$tablet  = isset( $options['tablet_body_font_size'] ) ? absint( $options['tablet_body_font_size'] ) : self::DEFAULT_BODY_FONT_SIZE;
		$phone   = isset( $options['phone_body_font_size'] ) ? absint( $options['phone_body_font_size'] ) : $tablet;

		return [
			'desktop'     => $desktop,
			'tablet'      => $tablet,
			'phone'       => $phone,
			'line_height' => isset( $options['body_font_height'] ) ? (float) $options['body_font_height'] : self::DEFAULT_BODY_LINE_HEIGHT,
		];
	}
}
