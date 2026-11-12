<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Map;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Targets\FormData;
use S3S\WP\DiviMigration\Targets\FormTarget;
use WP_UnitTestCase;

/**
 * Tests the report of Divi settings that are not converted, the class names
 * of converted blocks and the video host check.
 */
class ModuleSettingsTest extends WP_UnitTestCase {

	/**
	 * Returns the blocks with a name, at any depth.
	 *
	 * @param array[] $specs Block specs.
	 * @param string  $name  Block name.
	 * @return array[]
	 */
	private function find( array $specs, string $name ): array {
		$found = [];

		foreach ( $specs as $spec ) {
			if ( ( $spec['name'] ?? '' ) === $name ) {
				$found[] = $spec;
			}

			array_push( $found, ...$this->find( $spec['innerBlocks'] ?? [], $name ) );
		}

		return $found;
	}

	/**
	 * A Divi setting with a value and no conversion adds a note.
	 *
	 * @return void
	 */
	public function test_unconverted_settings_add_notes(): void {
		$cases = [
			'icon font'           => [ '[et_pb_blurb title="Lorem" use_icon="on" font_icon="%%1%%"]Ipsum[/et_pb_blurb]', 'et_pb_blurb', 'not converted: icon font' ],
			'background gradient' => [ '[et_pb_blurb title="Lorem" use_background_color_gradient="on"]Ipsum[/et_pb_blurb]', 'et_pb_blurb', 'not converted: background gradient' ],
			'border per side'     => [ '[et_pb_blurb title="Lorem" border_width_top="3px"]Ipsum[/et_pb_blurb]', 'et_pb_blurb', 'not converted: border per side' ],
			'slide video'         => [ '[et_pb_slider][et_pb_slide heading="Lorem" video_url="https://example.com/lorem.mp4"]Ipsum[/et_pb_slide][/et_pb_slider]', 'et_pb_slide', 'not converted: slide video' ],
		];

		foreach ( $cases as $label => [ $content, $tag, $note ] ) {
			$converter = Converter::create();

			$converter->convert_content( $content );

			$this->assertArrayHasKey( $tag, $converter->report(), $label );
			$this->assertArrayHasKey( $note, $converter->report()[ $tag ], $label );
			$this->assertCount( 1, array_filter( array_keys( $converter->report()[ $tag ] ), fn( string $key ) => str_starts_with( $key, 'not converted' ) ), $label );
		}
	}

	/**
	 * A module with Divi's default settings adds no note.
	 *
	 * @return void
	 */
	public function test_default_settings_add_no_notes(): void {
		$converter = Converter::create();

		$converter->convert_content( '[et_pb_blurb title="Lorem" use_icon="off" use_background_color_gradient="off"]Ipsum[/et_pb_blurb]' );

		$this->assertArrayHasKey( 'et_pb_blurb', $converter->report() );
		$this->assertSame( [], array_filter( array_keys( $converter->report()['et_pb_blurb'] ), fn( string $key ) => str_starts_with( $key, 'not converted' ) ) );
	}

	/**
	 * A contact form with a redirect adds a note when a form target builds it.
	 *
	 * @return void
	 */
	public function test_contact_form_redirect_adds_note(): void {
		$target = new class() implements FormTarget {
			/**
			 * {@inheritDoc}
			 */
			public function blocks(): array {
				return [];
			}

			/**
			 * {@inheritDoc}
			 */
			public function build( FormData $form, bool $create = true ): ?array {
				return Spec::block( 'core/paragraph', [ 'content' => 'form' ] );
			}

			/**
			 * {@inheritDoc}
			 */
			public function error(): string {
				return '';
			}
		};

		add_filter(
			's3s_divi_migration_targets',
			function ( array $targets ) use ( $target ): array {
				$targets['form'] = [ 'test' => $target ];
				return $targets;
			}
		);

		$with    = Converter::create();
		$without = Converter::create();

		$with->convert_content( '[et_pb_contact_form use_redirect="on" redirect_url="https://example.com/thanks"][/et_pb_contact_form]' );
		$without->convert_content( '[et_pb_contact_form][/et_pb_contact_form]' );

		remove_all_filters( 's3s_divi_migration_targets' );

		$this->assertArrayHasKey( 'not converted: redirect after submit', $with->report()['et_pb_contact_form'] );
		$this->assertArrayNotHasKey( 'not converted: redirect after submit', $without->report()['et_pb_contact_form'] );
	}

	/**
	 * The testimonial block style and its text group take their class names
	 * from the settings.
	 *
	 * @return void
	 */
	public function test_testimonial_class_names_come_from_settings(): void {
		$converter = Converter::create();

		$converter->settings->class_names['testimonial'] = 'is-style-lorem';
		$converter->settings->class_names['text']        = 'is-style-ipsum';

		$groups = $this->find( $converter->convert_content( '[et_pb_testimonial author="Lorem"]Ipsum[/et_pb_testimonial]' ), 'core/group' );
		$names  = array_column( array_column( $groups, 'attributes' ), 'className' );

		$this->assertContains( 'is-style-lorem', $names );
		$this->assertContains( 'is-style-ipsum', $names );
		$this->assertNotContains( 'is-style-testimonial', $names );
		$this->assertNotContains( 'is-style-text', $names );
	}

	/**
	 * The testimonial class name comes from a map.
	 *
	 * @return void
	 */
	public function test_testimonial_class_name_comes_from_map(): void {
		$map = Map::from_array( [ 'classNames' => [ 'testimonial' => 'is-style-lorem' ] ] );

		$this->assertInstanceOf( Map::class, $map );

		$converter = Converter::create( $map );
		$groups    = $this->find( $converter->convert_content( '[et_pb_testimonial author="Lorem"]Ipsum[/et_pb_testimonial]' ), 'core/group' );

		$this->assertContains( 'is-style-lorem', array_column( array_column( $groups, 'attributes' ), 'className' ) );
	}

	/**
	 * A blog that shows the content gets a Content block, not an Excerpt block.
	 *
	 * @return void
	 */
	public function test_blog_show_content_uses_post_content_block(): void {
		$specs = Converter::create()->convert_content( '[et_pb_blog show_content="on"][/et_pb_blog]' );

		$this->assertCount( 1, $this->find( $specs, 'core/post-content' ) );
		$this->assertSame( [], $this->find( $specs, 'core/post-excerpt' ) );
	}

	/**
	 * A blog that ignores manual excerpts adds a note.
	 *
	 * @return void
	 */
	public function test_blog_ignored_manual_excerpt_adds_note(): void {
		$converter = Converter::create();

		$converter->convert_content( '[et_pb_blog use_manual_excerpt="off"][/et_pb_blog]' );

		$this->assertArrayHasKey( 'not converted: ignore manual excerpts', $converter->report()['et_pb_blog'] );
	}

	/**
	 * The testimonial link goes on the author.
	 *
	 * @return void
	 */
	public function test_testimonial_url_links_author(): void {
		$specs      = Converter::create()->convert_content( '[et_pb_testimonial author="Lorem Ipsum" url="https://example.com/" url_new_window="on"]Dolor[/et_pb_testimonial]' );
		$paragraphs = $this->find( $specs, 'core/paragraph' );
		$content    = end( $paragraphs )['attributes']['content'];

		$this->assertStringContainsString( '<strong><a href="https://example.com/" target="_blank" rel="noopener">Lorem Ipsum</a></strong>', $content );
	}

	/**
	 * The blurb text group takes its class name from the settings.
	 *
	 * @return void
	 */
	public function test_blurb_text_class_comes_from_settings(): void {
		$converter = Converter::create();

		$converter->settings->class_names['text'] = 'is-style-lorem';

		$groups = $this->find( $converter->convert_content( '[et_pb_blurb title="Lorem"]Ipsum[/et_pb_blurb]' ), 'core/group' );
		$names  = array_column( array_column( $groups, 'attributes' ), 'className' );

		$this->assertContains( 'is-style-lorem', $names );
		$this->assertNotContains( 'is-style-text', $names );
	}

	/**
	 * A text module with content per viewport has the class name on its
	 * wrapper only.
	 *
	 * @return void
	 */
	public function test_text_class_applies_once(): void {
		$converter = Converter::create();

		$converter->settings->class_names['text'] = 'is-style-lorem';

		$specs  = $converter->convert_content( '[et_pb_text content_last_edited="on|tablet" content_tablet="<p>Dolor</p>"]<p>Lorem</p>[/et_pb_text]' );
		$groups = $this->find( $specs, 'core/group' );
		$names  = array_column( array_column( $groups, 'attributes' ), 'className' );

		$this->assertGreaterThan( 2, count( $groups ) );
		$this->assertSame( [ 'is-style-lorem' ], array_values( array_filter( $names ) ) );
	}

	/**
	 * Only the video services' own hosts and their subdomains make an embed.
	 *
	 * @return void
	 */
	public function test_video_host_match_is_exact(): void {
		$embed = fn( string $url ) => $this->find( Converter::create()->convert_content( '[et_pb_video src="' . $url . '"][/et_pb_video]' ), 'core/embed' );

		$this->assertCount( 1, $embed( 'https://m.youtube.com/watch?v=lorem' ) );
		$this->assertCount( 1, $embed( 'https://youtu.be/lorem' ) );
		$this->assertCount( 1, $embed( 'https://www.youtube-nocookie.com/embed/lorem' ) );
		$this->assertSame( [], $embed( 'https://youtube-nocookie.com.example.com/embed/lorem' ) );
		$this->assertCount( 1, $embed( 'https://player.vimeo.com/video/1' ) );
		$this->assertSame( [], $embed( 'https://notyoutube.com/watch?v=lorem' ) );
		$this->assertSame( [], $embed( 'https://example.com/youtube.com/lorem.mp4' ) );
	}
}
