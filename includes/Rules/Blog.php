<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Settings;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a blog module into a Query Loop.
 *
 * The grid layout (`fullwidth="off"`) shows three posts per row as cards;
 * the full-width layout lists them. The featured image, title, meta and
 * excerpt follow the module's show settings.
 */
class Blog extends Module {

	/**
	 * Divi's default excerpt length, in characters.
	 */
	const EXCERPT_LENGTH = '270';

	/**
	 * Average characters per word, with the space, to turn Divi's excerpt
	 * length into the word count the Excerpt block takes.
	 */
	const CHARACTERS_PER_WORD = 6;

	/**
	 * Divi's space between posts in a full-width list.
	 */
	const FULLWIDTH_GAP = '60px';

	/**
	 * Divi's space between full-width posts at 980px and below.
	 */
	const FULLWIDTH_GAP_MOBILE = '42px';

	/**
	 * Divi's space below a grid card title.
	 */
	const GRID_TITLE_GAP = '6px';

	/**
	 * Divi's space between grid columns.
	 */
	const GRID_COLUMN_GAP = '5.5%';

	/**
	 * Divi's space between grid rows on desktop, as a share of the row width.
	 */
	const GRID_ROW_GAP = 0.055;

	/**
	 * Divi's space between grid posts on phones, as a share of the row width.
	 */
	const GRID_ROW_GAP_PHONE = 0.115;

	/**
	 * Divi's bottom margin of the last posts of a grid.
	 */
	const GRID_BOTTOM_GAP = '30px';

	/**
	 * Divi's row width, in percent of the viewport.
	 */
	const ROW_WIDTH = 80;

	/**
	 * Divi's space below a post's image in a full-width list.
	 */
	const FULLWIDTH_IMAGE_GAP = '30px';

	/**
	 * Divi's font size of a post's meta line.
	 */
	const META_FONT_SIZE = '14px';

	/**
	 * Divi's space below a post's meta line.
	 */
	const META_GAP = '6px';

	/**
	 * Margins that take a grid card's image to the card's edges, over its
	 * padding and border, with 29px below.
	 */
	const GRID_IMAGE_MARGIN = [
		'top'    => '-20px',
		'right'  => '-20px',
		'bottom' => '29px',
		'left'   => '-20px',
	];

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets        = $converter->presets;
		$fullwidth      = $node->attr( 'fullwidth', 'on' );
		$show_thumbnail = $node->attr( 'show_thumbnail', 'on' );
		$show_author    = $node->attr( 'show_author', 'on' );
		$show_date      = $node->attr( 'show_date', 'on' );
		$show_category  = $node->attr( 'show_categories', 'on' );
		$show_excerpt   = $node->attr( 'show_excerpt', 'on' );
		$show_paging    = $node->attr( 'show_pagination', 'on' );
		$is_grid        = 'off' === $fullwidth;

		$title_style = new StyleSet();
		$this->typography( $node, 'header', $title_style, $presets );

		$card = [];

		if ( 'off' !== $show_thumbnail ) {
			// The theme registers these sizes with Divi's dimensions: 400 by 250 for a grid card and 1080 by 675 for a full-width post, both cropped.
			$card[] = Spec::block(
				'core/post-featured-image',
				array_merge(
					[
						'isLink'   => true,
						'sizeSlug' => $converter->settings->image_size( $is_grid ? 'card' : 'wide' ),
					],
					// A grid card's image reaches the card's edges; a full-width post leaves 30px between its image and its title.
					[ 'style' => [ 'spacing' => [ 'margin' => $is_grid ? self::GRID_IMAGE_MARGIN : [ 'bottom' => self::FULLWIDTH_IMAGE_GAP ] ] ] ]
				)
			);
		}

		// Divi drops the title's bottom padding at 479px and below; phones here are 767px and below.
		$title_style->set(
			[ 'spacing', 'padding', 'bottom' ],
			[
				'desktop' => $is_grid ? self::GRID_TITLE_GAP : '',
				'phone'   => '0px',
			]
		);

		$card[] = Spec::block(
			'core/post-title',
			[
				'level'  => 2,
				'isLink' => true,
				'style'  => $title_style->to_style(),
			]
		);

		$post_text = self::post_text_style( $converter->settings, $presets );

		$meta = [];

		if ( 'off' !== $show_author ) {
			$meta[] = Spec::block( 'core/post-author-name', [ 'isLink' => true ] );
		}

		if ( 'off' !== $show_date ) {
			$meta[] = Spec::block( 'core/post-date', [] );
		}

		if ( 'off' !== $show_category ) {
			$meta[] = Spec::block( 'core/post-terms', [ 'term' => 'category' ] );
		}

		if ( [] !== $meta ) {
			$card[] = Spec::block(
				'core/group',
				[
					'layout' => [ 'type' => 'flex' ],
					'style'  => array_replace_recursive(
						[
							'spacing'    => [
								'blockGap' => '0.5em',
								'margin'   => [ 'bottom' => self::META_GAP ],
							],
							'typography' => [ 'fontSize' => $presets->font_size( self::META_FONT_SIZE ) ],
							'color'      => [ 'text' => $presets->color( '#666666' ) ],
						],
						$post_text
					),
				],
				$meta
			);
		}

		if ( $node->is_on( 'show_content' ) ) {
			$card[] = Spec::block( 'core/post-content', [ 'style' => $post_text ] );
		} elseif ( 'off' !== $show_excerpt ) {
			$manual_excerpt = $node->attr( 'use_manual_excerpt' );

			if ( 'off' === $manual_excerpt ) {
				$converter->note( $node->tag, 'not converted: ignore manual excerpts' );
			}

			// Divi cuts excerpts by characters. The "read more" link has its own line in a full-width list and follows the text in a grid card.
			$card[] = Spec::block(
				'core/post-excerpt',
				[
					'moreText'          => $node->is_on( 'show_more' ) ? __( 'read more', 'divi-migration' ) : '',
					'showMoreOnNewLine' => ! $is_grid,
					'excerptLength'     => max( 10, (int) round( (int) $node->attr( 'excerpt_length', self::EXCERPT_LENGTH ) / self::CHARACTERS_PER_WORD ) ),
					'style'             => $post_text,
				]
			);
		}

		$template = Spec::block(
			'core/post-template',
			[
				'layout' => $is_grid
					? [
						'type'        => 'grid',
						'columnCount' => 3,
					]
					: [ 'type' => 'default' ],
				'style'  => $is_grid ? self::grid_gap( $converter->settings ) : self::fullwidth_gap(),
			],
			[
				Spec::block(
					'core/group',
					array_merge(
						[ 'layout' => [ 'type' => 'default' ] ],
						$is_grid ? [ 'style' => self::grid_card_style( $presets ) ] : []
					),
					$card
				),
			]
		);

		$inner = [ $template ];

		if ( 'off' !== $show_paging ) {
			$inner[] = Spec::block(
				'core/query-pagination',
				[
					'layout' => [
						'type'           => 'flex',
						'justifyContent' => 'space-between',
					],
				],
				[
					Spec::block( 'core/query-pagination-previous', [] ),
					Spec::block( 'core/query-pagination-next', [] ),
				]
			);
		}

		$query = [
			'perPage'  => (int) $node->attr( 'posts_number', '10' ),
			'postType' => 'post',
			'order'    => 'desc',
			'orderBy'  => 'date',
			'offset'   => (int) $node->attr( 'offset_number', '0' ),
			'inherit'  => false,
		];

		$categories = $this->categories( $node->attr( 'include_categories', 'all' ) );

		if ( [] !== $categories ) {
			$query['taxQuery'] = [ 'category' => $categories ];
		}

		$style = new StyleSet();
		$this->design( $node, $style, $presets, [], $converter );

		$attributes = array_merge(
			$this->common_attributes( $node ),
			[
				'query' => $query,
				'style' => $style->to_style(),
			]
		);

		return [ Spec::block( 'core/query', $attributes, $inner ) ];
	}

	/**
	 * Returns the category IDs a blog module includes.
	 *
	 * Divi stores IDs and the keywords `all` and `current`. With `all` in the
	 * list, Divi shows posts of every category, so the query is unfiltered.
	 * `current` adds no ID: on a page it stands for the page's categories,
	 * which pages do not have. IDs that are not categories are dropped, as
	 * Divi drops them.
	 *
	 * @param string $value Comma-separated categories.
	 * @return int[]
	 */
	private function categories( string $value ): array {
		$values = array_map( 'trim', explode( ',', $value ) );

		if ( in_array( 'all', $values, true ) ) {
			return [];
		}

		$ids = [];

		foreach ( $values as $item ) {
			$id   = (int) $item;
			$term = $id > 0 ? get_term( $id, 'category' ) : null;

			if ( $term instanceof \WP_Term ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Returns the text style of a post's meta line and excerpt.
	 *
	 * When the body font size is not Divi's default of 14px, Divi sets the
	 * paragraphs of a listed post to the desktop body size at every viewport.
	 * The paragraphs inherit the body line height in pixels.
	 *
	 * @param Settings $settings Converter settings.
	 * @param Presets  $presets  Theme presets.
	 * @return array
	 */
	private static function post_text_style( Settings $settings, Presets $presets ): array {
		$sizes = $settings->body_font_sizes();

		if ( Settings::DEFAULT_BODY_FONT_SIZE === $sizes['desktop'] ) {
			return [];
		}

		$style = new StyleSet();
		$style->set_value( [ 'typography', 'fontSize' ], $presets->font_size( $sizes['desktop'] . 'px' ) );
		$style->set( [ 'typography', 'lineHeight' ], $settings->body_line_heights() );

		return $style->to_style();
	}

	/**
	 * Returns the gap between full-width posts: Divi's 60px, and 42px at
	 * 980px and below.
	 *
	 * @return array
	 */
	private static function fullwidth_gap(): array {
		return [
			'spacing' => [ 'blockGap' => self::FULLWIDTH_GAP ],
			'@tablet' => [ 'spacing' => [ 'blockGap' => self::FULLWIDTH_GAP_MOBILE ] ],
			'@mobile' => [ 'spacing' => [ 'blockGap' => self::FULLWIDTH_GAP_MOBILE ] ],
		];
	}

	/**
	 * Returns the gaps of a grid of posts.
	 *
	 * Divi spaces the columns by its 5.5% gutter. It spaces the rows by a
	 * bottom margin on each card, a percentage of the column width: 5.5% of
	 * the row on desktop, and 11.5% of the row on phones, where one column
	 * fills the row. The row is 80% of the viewport, up to the content width.
	 * WordPress drops a block gap with a CSS function, so the desktop gap is
	 * the one of a full-width row, and the phone gap is in viewport units.
	 * The last cards keep Divi's 30px bottom margin.
	 *
	 * @param Settings $settings Converter settings.
	 * @return array
	 */
	private static function grid_gap( Settings $settings ): array {
		return [
			'spacing' => [
				'blockGap' => [
					'top'  => round( $settings->content_width * self::GRID_ROW_GAP, 2 ) . 'px',
					'left' => self::GRID_COLUMN_GAP,
				],
				'margin'   => [ 'bottom' => self::GRID_BOTTOM_GAP ],
			],
			'@mobile' => [
				'spacing' => [
					'blockGap' => [
						'top'  => round( self::ROW_WIDTH * self::GRID_ROW_GAP_PHONE, 2 ) . 'vw',
						'left' => self::GRID_COLUMN_GAP,
					],
				],
			],
		];
	}

	/**
	 * Returns the style of a grid card: Divi's white box with a grey border.
	 *
	 * @param Presets $presets Theme presets.
	 * @return array
	 */
	private static function grid_card_style( Presets $presets ): array {
		return [
			'color'      => [ 'background' => $presets->color( '#ffffff' ) ],
			'border'     => [
				'color' => $presets->color( '#d8d8d8' ),
				'style' => 'solid',
				'width' => '1px',
			],
			'spacing'    => [
				'padding' => [
					'top'    => '19px',
					'right'  => '19px',
					'bottom' => '19px',
					'left'   => '19px',
				],
			],
			'dimensions' => [ 'minHeight' => '100%' ],
		];
	}
}
