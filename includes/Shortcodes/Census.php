<?php

namespace S3S\WP\DiviMigration\Shortcodes;

use S3S\WP\DiviMigration\Content;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the shortcode tags that content and widgets use, with the code that
 * registers each tag on the running site.
 *
 * Divi module shortcodes (`et_pb_*`) are left out: the conversion rules read
 * them. A tag that no code registers stops rendering; so does a tag whose
 * code goes away with Divi or one of its plugins.
 */
class Census {

	/**
	 * Matches an opening shortcode tag: a letter, then word characters or
	 * hyphens, then a space, `]` or `/`. Numbers, closing tags and `[CDATA[`
	 * do not match. CSS attribute selectors and script indexes such as `[i]`
	 * can match; the report shows them without a registering file.
	 */
	const TAG_PATTERN = '/\[([a-zA-Z][\w-]*)(?=[\s\]\/])/';

	/**
	 * Tag prefixes that the conversion rules read.
	 */
	const SKIPPED_PREFIXES = [ 'et_pb_' ];

	/**
	 * Widget options and the instance key that holds their text.
	 */
	const WIDGET_OPTIONS = [
		'widget_text'        => 'text',
		'widget_custom_html' => 'content',
		'widget_block'       => 'content',
	];

	/**
	 * Uses per tag: the use count and the places, keyed by tag.
	 *
	 * @var array<string, array{uses: int, places: string[]}>
	 */
	private array $tags = [];

	/**
	 * Counts the tags in the content of posts.
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $statuses   Post statuses.
	 * @return void
	 */
	public function add_posts( array $post_types, array $statuses ): void {
		$ids = get_posts(
			[
				'post_type'        => $post_types,
				'post_status'      => $statuses,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			]
		);

		$processed = 0;

		foreach ( $ids as $id ) {
			$this->add_text( (string) get_post_field( 'post_content', (int) $id, 'raw' ), (string) $id );

			Content::flush_runtime_cache( ++$processed );
		}
	}

	/**
	 * Counts the tags in text, HTML and block widgets.
	 *
	 * @return void
	 */
	public function add_widgets(): void {
		foreach ( self::WIDGET_OPTIONS as $option => $key ) {
			$instances = get_option( $option, [] );

			if ( ! is_array( $instances ) ) {
				continue;
			}

			$id_base = substr( $option, strlen( 'widget_' ) );

			foreach ( $instances as $number => $instance ) {
				if ( ! is_array( $instance ) || ! is_string( $instance[ $key ] ?? null ) ) {
					continue;
				}

				$this->add_text( $instance[ $key ], 'widget:' . str_replace( '_', '-', $id_base ) . '-' . $number );
			}
		}
	}

	/**
	 * Counts the tags in one text.
	 *
	 * @param string $text  Text.
	 * @param string $place Where the text comes from: a post ID or `widget:<id>`.
	 * @return void
	 */
	public function add_text( string $text, string $place ): void {
		$found = preg_match_all( self::TAG_PATTERN, $text, $matches );

		if ( ! $found ) {
			return;
		}

		foreach ( $matches[1] as $tag ) {
			if ( self::is_skipped( $tag ) ) {
				continue;
			}

			$this->tags[ $tag ]['uses'] = ( $this->tags[ $tag ]['uses'] ?? 0 ) + 1;

			if ( ! in_array( $place, $this->tags[ $tag ]['places'] ?? [], true ) ) {
				$this->tags[ $tag ]['places'][] = $place;
			}
		}
	}

	/**
	 * Returns one row per tag, the most used first.
	 *
	 * @return array<int, array{tag: string, uses: int, places: string, registered_by: string}>
	 */
	public function rows(): array {
		global $shortcode_tags;

		$tags = $this->tags;
		uasort( $tags, fn( array $a, array $b ) => $b['uses'] <=> $a['uses'] );

		$rows = [];

		foreach ( $tags as $tag => $entry ) {
			$rows[] = [
				'tag'           => $tag,
				'uses'          => $entry['uses'],
				'places'        => implode( ',', $entry['places'] ),
				'registered_by' => isset( $shortcode_tags[ $tag ] ) ? self::owner( $shortcode_tags[ $tag ] ) : '',
			];
		}

		return $rows;
	}

	/**
	 * Whether the conversion rules read a tag.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	private static function is_skipped( string $tag ): bool {
		foreach ( self::SKIPPED_PREFIXES as $prefix ) {
			if ( str_starts_with( $tag, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns where a shortcode callback is defined: `core`, `plugin:<slug>`,
	 * `mu-plugin:<file>`, `theme:<slug>`, or the file path.
	 *
	 * @param callable $callback Shortcode callback.
	 * @return string
	 */
	private static function owner( $callback ): string {
		try {
			if ( is_array( $callback ) ) {
				$reflection = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( is_string( $callback ) && str_contains( $callback, '::' ) ) {
				$reflection = new ReflectionMethod( $callback );
			} else {
				$reflection = new ReflectionFunction( $callback );
			}
		} catch ( Throwable $error ) {
			return 'unknown';
		}

		$file = wp_normalize_path( (string) $reflection->getFileName() );

		$roots = [
			'plugin:'    => wp_normalize_path( WP_PLUGIN_DIR ) . '/',
			'mu-plugin:' => wp_normalize_path( WPMU_PLUGIN_DIR ) . '/',
			'theme:'     => wp_normalize_path( get_theme_root() ) . '/',
		];

		foreach ( $roots as $label => $root ) {
			if ( str_starts_with( $file, $root ) ) {
				return $label . strtok( substr( $file, strlen( $root ) ), '/' );
			}
		}

		if ( str_starts_with( $file, wp_normalize_path( ABSPATH . WPINC ) ) ) {
			return 'core';
		}

		return '' !== $file ? $file : 'unknown';
	}
}
