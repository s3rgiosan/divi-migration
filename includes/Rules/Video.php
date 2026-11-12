<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Serializer\RawHandler;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a video module into an Embed block for the URL of an oEmbed
 * provider, or a Video block for a file. The overlay image and the WebM
 * source have no block attribute, so the report notes them.
 */
class Video extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$src = $node->attr( 'src', $node->attr( 'src_webm' ) );

		if ( '' === $src ) {
			return [];
		}

		$style = new StyleSet();
		$this->design( $node, $style, $converter->presets, [ 'background', 'padding' ], $converter );

		$this->note_unconverted( $node, $converter, 'video overlay image', [ 'image_src' ] );

		$source = $node->attr( 'src' );

		if ( '' !== $source ) {
			$this->note_unconverted( $node, $converter, 'webm video source', [ 'src_webm' ] );
		}

		$provider = $this->provider( $src );

		if ( null === $provider ) {
			$attributes = array_merge(
				$this->common_attributes( $node ),
				[
					'src'   => $src,
					'style' => $style->to_style(),
				]
			);

			$attributes = $this->with_attachment_id( $attributes, $src );

			return [ Spec::block( 'core/video', $attributes ) ];
		}

		$attributes = array_merge(
			$this->common_attributes( $node, 'wp-embed-aspect-16-9 wp-has-aspect-ratio' ),
			[
				'url'              => self::watch_url( $src ),
				'type'             => 'video',
				'providerNameSlug' => $provider,
				'responsive'       => true,
				'style'            => $style->to_style(),
			]
		);

		return [ Spec::block( 'core/embed', $attributes ) ];
	}

	/**
	 * Returns the embed provider slug for a video URL, or null for a video
	 * file. The slug is empty for an oEmbed provider that has no embed
	 * variation.
	 *
	 * @param string $url Video URL.
	 * @return string|null
	 */
	private function provider( string $url ): ?string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		switch ( true ) {
			case self::is_host( $host, [ 'youtube.com', 'youtu.be', 'youtube-nocookie.com' ] ):
				return 'youtube';
			case self::is_host( $host, [ 'vimeo.com' ] ):
				return 'vimeo';
			default:
				break;
		}

		$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, wp_get_video_extensions(), true ) ) {
			return null;
		}

		$endpoint = _wp_oembed_get_object()->get_provider( $url, [ 'discover' => false ] );

		if ( ! is_string( $endpoint ) || '' === $endpoint ) {
			return null;
		}

		return RawHandler::embed_provider( $url )['slug'] ?? '';
	}

	/**
	 * Returns the watch URL of a YouTube `/embed/<id>` URL, with its start
	 * time, which the oEmbed providers of core accept. Other URLs stay as
	 * they are.
	 *
	 * @param string $url Video URL.
	 * @return string
	 */
	private static function watch_url( string $url ): string {
		$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$is_embed = 1 === preg_match( '#^/embed/([\w-]+)/?$#', $path, $matches );

		if ( ! self::is_host( $host, [ 'youtube.com', 'youtube-nocookie.com' ] ) || ! $is_embed ) {
			return $url;
		}

		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$watch = 'https://www.youtube.com/watch?v=' . $matches[1];
		$start = isset( $query['start'] ) && is_string( $query['start'] ) ? $query['start'] : '';

		$is_numeric_start = preg_match( '/^\d+$/', $start );

		if ( 1 === $is_numeric_start ) {
			$watch .= '&t=' . $start;
		}

		return $watch;
	}

	/**
	 * Whether a host is one of the domains or a subdomain of one.
	 *
	 * @param string   $host    Lowercase host.
	 * @param string[] $domains Domains, such as `youtube.com`.
	 * @return bool
	 */
	private static function is_host( string $host, array $domains ): bool {
		foreach ( $domains as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}
}
