<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a post title module into the post's featured image, title and
 * meta (author, date, categories and comments link), as the module shows them.
 */
class PostTitle extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$presets   = $converter->presets;
		$align     = $this->alignment( $node, 'text_orientation', $converter )['desktop'];
		$align     = '' !== $align ? $align : 'left';
		$placement = $node->attr( 'featured_placement', 'below' );
		$show      = [];
		$inner     = [];

		foreach ( [ 'featured_image', 'title', 'meta', 'author', 'date', 'categories', 'comments' ] as $setting ) {
			$show[ $setting ] = 'off' !== $node->attr( $setting, 'on' );
		}

		$image = $show['featured_image'] ? [ Spec::block( 'core/post-featured-image', [] ) ] : [];

		if ( 'above' === $placement ) {
			array_push( $inner, ...$image );
		}

		if ( $show['title'] ) {
			$title_style = new StyleSet();
			$this->typography( $node, 'title', $title_style, $presets );

			if ( $node->is_on( 'title_all_caps' ) ) {
				$title_style->set_value( [ 'typography', 'textTransform' ], 'uppercase' );
			}

			$title_style->set_value( [ 'typography', 'textAlign' ], $align );

			$inner[] = Spec::block(
				'core/post-title',
				[
					'level' => 1,
					'style' => $title_style->to_style(),
				]
			);
		}

		if ( $show['meta'] ) {
			$meta = [];

			if ( $show['author'] ) {
				$meta[] = Spec::block( 'core/post-author-name', [] );
			}

			if ( $show['date'] ) {
				$meta[] = Spec::block( 'core/post-date', [] );
			}

			if ( $show['categories'] ) {
				$meta[] = Spec::block( 'core/post-terms', [ 'term' => 'category' ] );
			}

			if ( $show['comments'] ) {
				$meta[] = Spec::block( 'core/post-comments-link', [] );
			}

			if ( [] !== $meta ) {
				$inner[] = Spec::block(
					'core/group',
					[
						'layout' => [
							'type'           => 'flex',
							'justifyContent' => $align,
						],
						'style'  => [ 'spacing' => [ 'blockGap' => '0.5em' ] ],
					],
					$meta
				);
			}
		}

		if ( 'above' !== $placement ) {
			array_push( $inner, ...$image );
		}

		$style = new StyleSet();
		$this->design( $node, $style, $presets, [], $converter );

		$attributes = $this->group_attributes( $node, $style );

		return [ Spec::block( 'core/group', $attributes, $inner ) ];
	}
}
