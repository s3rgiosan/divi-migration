<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use WP_UnitTestCase;

/**
 * Tests the video rule: embeds for video services, the Video block for
 * files, the host check and the attachment lookup.
 */
class VideoRuleTest extends WP_UnitTestCase {

	/**
	 * Converts a video module with a source.
	 *
	 * @param string $src Video source.
	 * @return array[]
	 */
	private function specs( string $src ): array {
		return Converter::create()->convert_content( '[et_pb_video src="' . $src . '"][/et_pb_video]' );
	}

	/**
	 * Returns the block markup for a video module with a source.
	 *
	 * @param string $src Video source.
	 * @return string
	 */
	private function markup( string $src ): string {
		$converter = Converter::create();

		return $converter->serializer()->serialize( $converter->convert_content( '[et_pb_video src="' . $src . '"][/et_pb_video]' ) );
	}

	/**
	 * Video service URLs convert to an Embed block with the provider.
	 *
	 * @return void
	 */
	public function test_video_service_urls_become_embeds(): void {
		$urls = [
			'https://www.youtube.com/watch?v=abc123' => 'youtube',
			'https://youtu.be/abc123'                => 'youtube',
			'https://vimeo.com/123456'               => 'vimeo',
			'https://player.vimeo.com/video/123456'  => 'vimeo',
			'https://YOUTUBE.com/watch?v=abc123'     => 'youtube',
		];

		foreach ( $urls as $url => $provider ) {
			$specs = $this->specs( $url );

			$this->assertCount( 1, $specs, $url );
			$this->assertSame( 'core/embed', $specs[0]['name'], $url );
			$this->assertSame( $url, $specs[0]['attributes']['url'], $url );
			$this->assertSame( 'video', $specs[0]['attributes']['type'], $url );
			$this->assertSame( $provider, $specs[0]['attributes']['providerNameSlug'], $url );
			$this->assertTrue( $specs[0]['attributes']['responsive'], $url );
			$this->assertSame( 'wp-embed-aspect-16-9 wp-has-aspect-ratio', $specs[0]['attributes']['className'], $url );
		}
	}

	/**
	 * A YouTube `/embed/<id>` URL becomes the watch URL that oEmbed resolves,
	 * and keeps its start time.
	 *
	 * @return void
	 */
	public function test_youtube_embed_urls_become_watch_urls(): void {
		$urls = [
			'https://www.youtube-nocookie.com/embed/abc123' => 'https://www.youtube.com/watch?v=abc123',
			'https://www.youtube.com/embed/abc123'       => 'https://www.youtube.com/watch?v=abc123',
			'https://www.youtube.com/embed/abc123?start=42&rel=0' => 'https://www.youtube.com/watch?v=abc123&t=42',
			'https://www.youtube.com/embed/abc123?start=lorem' => 'https://www.youtube.com/watch?v=abc123',
			'https://www.youtube.com/watch?v=abc123&t=5' => 'https://www.youtube.com/watch?v=abc123&t=5',
			'https://youtu.be/abc123'                    => 'https://youtu.be/abc123',
		];

		foreach ( $urls as $url => $expected ) {
			$specs = $this->specs( $url );

			$this->assertSame( 'core/embed', $specs[0]['name'], $url );
			$this->assertSame( 'youtube', $specs[0]['attributes']['providerNameSlug'], $url );
			$this->assertSame( $expected, $specs[0]['attributes']['url'], $url );
		}
	}

	/**
	 * The embed markup holds the provider classes and the URL.
	 *
	 * @return void
	 */
	public function test_embed_markup_holds_provider_and_url(): void {
		$markup = $this->markup( 'https://vimeo.com/123456' );

		$this->assertStringStartsWith( '<!-- wp:embed ', $markup );
		$this->assertStringContainsString( '"providerNameSlug":"vimeo"', $markup );
		$this->assertStringContainsString( 'is-provider-vimeo wp-block-embed-vimeo', $markup );
		$this->assertStringContainsString( '<div class="wp-block-embed__wrapper">' . "\n" . 'https://vimeo.com/123456' . "\n" . '</div>', $markup );
	}

	/**
	 * The URL of any oEmbed provider becomes an Embed block, and the slug is
	 * the editor's slug for the provider.
	 *
	 * @return void
	 */
	public function test_other_oembed_providers_become_embeds(): void {
		$specs = $this->specs( 'https://www.dailymotion.com/video/lorem' );

		$this->assertSame( 'core/embed', $specs[0]['name'] );
		$this->assertSame( 'dailymotion', $specs[0]['attributes']['providerNameSlug'] );
		$this->assertTrue( $specs[0]['attributes']['responsive'] );

		$unknown = $this->specs( 'https://example.com/lorem' );

		$this->assertSame( 'core/video', $unknown[0]['name'] );
	}

	/**
	 * A video file on an oEmbed provider's host stays a Video block.
	 *
	 * @return void
	 */
	public function test_video_file_on_provider_host_stays_video(): void {
		$specs = $this->specs( 'https://wordpress.tv/wp-content/uploads/lorem.mp4' );

		$this->assertSame( 'core/video', $specs[0]['name'] );
	}

	/**
	 * The overlay image, and the WebM source next to an MP4 source, add notes.
	 *
	 * @return void
	 */
	public function test_dropped_overlay_and_webm_add_notes(): void {
		$converter = Converter::create();
		$converter->convert_content( '[et_pb_video src="https://example.com/lorem.mp4" src_webm="https://example.com/lorem.webm" image_src="https://example.com/lorem.png"][/et_pb_video]' );

		$this->assertArrayHasKey( 'not converted: video overlay image', $converter->report()['et_pb_video'] );
		$this->assertArrayHasKey( 'not converted: webm video source', $converter->report()['et_pb_video'] );

		$alone = Converter::create();
		$alone->convert_content( '[et_pb_video src_webm="https://example.com/lorem.webm"][/et_pb_video]' );

		$this->assertArrayNotHasKey( 'not converted: webm video source', $alone->report()['et_pb_video'] ?? [] );
	}

	/**
	 * A file URL converts to a Video block with the source.
	 *
	 * @return void
	 */
	public function test_file_url_becomes_video_block(): void {
		$specs = $this->specs( 'https://example.com/wp-content/uploads/lorem.mp4' );

		$this->assertSame( 'core/video', $specs[0]['name'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/lorem.mp4', $specs[0]['attributes']['src'] );
		$this->assertArrayNotHasKey( 'id', $specs[0]['attributes'] );

		$markup = $this->markup( 'https://example.com/wp-content/uploads/lorem.mp4' );

		$this->assertStringContainsString( '<figure class="wp-block-video"><video controls src="https://example.com/wp-content/uploads/lorem.mp4"></video></figure>', $markup );
	}

	/**
	 * The WebM source is the fallback when the module has no MP4 source.
	 *
	 * @return void
	 */
	public function test_webm_source_is_the_fallback(): void {
		$specs = Converter::create()->convert_content( '[et_pb_video src_webm="https://example.com/wp-content/uploads/lorem.webm"][/et_pb_video]' );

		$this->assertSame( 'core/video', $specs[0]['name'] );
		$this->assertSame( 'https://example.com/wp-content/uploads/lorem.webm', $specs[0]['attributes']['src'] );
	}

	/**
	 * A module without a source converts to nothing.
	 *
	 * @return void
	 */
	public function test_module_without_source_converts_to_nothing(): void {
		$this->assertSame( [], Converter::create()->convert_content( '[et_pb_video][/et_pb_video]' ) );
	}

	/**
	 * A host that only ends with a service name, or has it in the path or
	 * query, is not that service.
	 *
	 * @return void
	 */
	public function test_spoofed_hosts_are_not_embeds(): void {
		$urls = [
			'https://notyoutube.com/watch?v=abc123',
			'https://youtube.com.example.com/watch?v=abc123',
			'https://example.com/youtube.com/lorem.mp4',
			'https://example.com/lorem.mp4?ref=vimeo.com',
			'https://fakevimeo.com/123456',
		];

		foreach ( $urls as $url ) {
			$specs = $this->specs( $url );

			$this->assertSame( 'core/video', $specs[0]['name'], $url );
			$this->assertSame( $url, $specs[0]['attributes']['src'], $url );
		}
	}

	/**
	 * A file in the media library gets the attachment ID.
	 *
	 * @return void
	 */
	public function test_media_library_file_gets_attachment_id(): void {
		$attachment = self::factory()->attachment->create(
			[
				'post_mime_type' => 'video/mp4',
				'file'           => '2020/01/lorem.mp4',
			]
		);
		$url        = wp_get_upload_dir()['baseurl'] . '/2020/01/lorem.mp4';

		$specs = $this->specs( $url );

		$this->assertSame( 'core/video', $specs[0]['name'] );
		$this->assertSame( $attachment, $specs[0]['attributes']['id'] );
		$this->assertStringContainsString( '"id":' . $attachment, $this->markup( $url ) );
	}

	/**
	 * The module class and anchor reach the block.
	 *
	 * @return void
	 */
	public function test_module_class_and_anchor_reach_the_block(): void {
		$specs = Converter::create()->convert_content( '[et_pb_video src="https://youtu.be/abc123" module_class="lorem-video" module_id="ipsum"][/et_pb_video]' );

		$this->assertSame( 'wp-embed-aspect-16-9 wp-has-aspect-ratio lorem-video', $specs[0]['attributes']['className'] );
		$this->assertSame( 'ipsum', $specs[0]['attributes']['anchor'] );
	}
}
