<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Serializer\Portable;
use S3S\WP\DiviMigration\Serializer\Supports;
use S3S\WP\DiviMigration\Spec;
use WP_Block_Type_Registry;

/**
 * Tests the saved markup of static blocks.
 */
class SavesTest extends TestCase {

	/**
	 * The separator's background color also sets its text color class or
	 * style.
	 *
	 * @return void
	 */
	public function test_separator_writes_color_classes_and_styles(): void {
		$preset = $this->markup( Spec::block( 'core/separator', [ 'backgroundColor' => 'contrast' ] ) );
		$custom = $this->markup( Spec::block( 'core/separator', [ 'style' => [ 'color' => [ 'background' => '#123456' ] ] ] ) );

		$this->assertStringContainsString( '<hr class="wp-block-separator has-text-color has-contrast-color has-alpha-channel-opacity has-contrast-background-color has-background"/>', $preset );
		$this->assertStringContainsString( 'class="wp-block-separator has-text-color has-alpha-channel-opacity has-background" style="background-color:#123456;color:#123456"', $custom );
	}

	/**
	 * A paragraph has no drop cap class when its text is centered or aligned
	 * to the end of the line.
	 *
	 * @return void
	 */
	public function test_paragraph_drop_cap_depends_on_text_alignment(): void {
		$cases = [
			'center' => false,
			'right'  => false,
			'left'   => true,
		];

		foreach ( $cases as $text_align => $has_drop_cap ) {
			$markup = $this->markup(
				Spec::block(
					'core/paragraph',
					[
						'content' => 'Lorem ipsum.',
						'dropCap' => true,
						'style'   => [ 'typography' => [ 'textAlign' => $text_align ] ],
					]
				)
			);

			$this->assertSame( $has_drop_cap, str_contains( $markup, 'has-drop-cap' ), $text_align );
		}
	}

	/**
	 * The button link gets the text alignment class.
	 *
	 * @return void
	 */
	public function test_button_link_gets_text_alignment_class(): void {
		$markup = $this->markup(
			Spec::block(
				'core/button',
				[
					'text'  => 'Lorem',
					'url'   => 'https://example.com/',
					'style' => [ 'typography' => [ 'textAlign' => 'center' ] ],
				]
			)
		);

		$this->assertStringContainsString( '<a class="wp-block-button__link has-text-align-center wp-element-button" href="https://example.com/">Lorem</a>', $markup );
	}

	/**
	 * The cover writes its gradient on the background element.
	 *
	 * @return void
	 */
	public function test_cover_writes_gradient(): void {
		$preset = $this->markup(
			Spec::block(
				'core/cover',
				[
					'url'      => 'https://example.com/lorem.jpg',
					'gradient' => 'vivid-cyan-blue-to-vivid-purple',
					'dimRatio' => 50,
				]
			)
		);
		$custom = $this->markup( Spec::block( 'core/cover', [ 'customGradient' => 'linear-gradient(135deg,rgb(0,0,0) 0%,rgb(255,255,255) 100%)' ] ) );

		$this->assertStringContainsString( '<span aria-hidden="true" class="wp-block-cover__background has-background-dim wp-block-cover__gradient-background has-background-gradient has-vivid-cyan-blue-to-vivid-purple-gradient-background"></span>', $preset );
		$this->assertStringContainsString( 'class="wp-block-cover__background has-background-dim-100 has-background-dim has-background-gradient" style="background:linear-gradient(135deg,rgb(0,0,0) 0%,rgb(255,255,255) 100%)"', $custom );
	}

	/**
	 * The cover writes a video or an embedded video background.
	 *
	 * @return void
	 */
	public function test_cover_writes_video_backgrounds(): void {
		$video = $this->markup(
			Spec::block(
				'core/cover',
				[
					'url'            => 'https://example.com/lorem.mp4',
					'backgroundType' => 'video',
					'focalPoint'     => [
						'x' => 0.3,
						'y' => 0.6,
					],
				]
			)
		);
		$embed = $this->markup(
			Spec::block(
				'core/cover',
				[
					'url'            => 'https://www.youtube.com/watch?v=lorem&t=1',
					'backgroundType' => 'embed-video',
				]
			)
		);

		$this->assertStringContainsString( '<video class="wp-block-cover__video-background intrinsic-ignore" autoplay muted loop playsinline src="https://example.com/lorem.mp4" style="object-position:30% 60%" data-object-fit="cover" data-object-position="30% 60%"></video>', $video );
		$this->assertStringContainsString( '<figure class="wp-block-cover__video-background wp-block-cover__embed-background wp-block-embed"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=lorem&amp;t=1</div></figure>', $embed );
	}

	/**
	 * A column with a width of zero has no flex basis; a width of `0` as text
	 * has one.
	 *
	 * @return void
	 */
	public function test_column_without_width_has_no_flex_basis(): void {
		$markup = $this->markup(
			Spec::block(
				'core/columns',
				[],
				[
					Spec::block( 'core/column', [ 'width' => 0 ] ),
					Spec::block( 'core/column', [ 'width' => '0' ] ),
				]
			)
		);

		$this->assertStringContainsString( "<!-- wp:column {\"width\":0} -->\n<div class=\"wp-block-column\"></div>", $markup );
		$this->assertStringContainsString( '<div class="wp-block-column" style="flex-basis:0"></div>', $markup );
	}

	/**
	 * The Shortcode block's save drops HTML comments from the text, so a
	 * block delimiter cannot end the block early.
	 *
	 * @return void
	 */
	public function test_shortcode_save_drops_comments(): void {
		$markup = $this->markup( Spec::block( 'core/shortcode', [ 'text' => '[lorem]<!-- /wp:shortcode --><<!--!--x[/lorem]' ] ) );

		$this->assertSame( "<!-- wp:shortcode -->\n[lorem]x[/lorem]\n<!-- /wp:shortcode -->", $markup );
	}

	/**
	 * Portable markup equals the save of the same image without an ID, at a
	 * root-relative upload URL, and keeps the image's other classes.
	 *
	 * @return void
	 */
	public function test_portable_markup_drops_ids_and_host(): void {
		$base_url = wp_get_upload_dir()['baseurl'];
		$relative = wp_make_link_relative( $base_url );

		$with_id = $this->markup(
			Spec::block(
				'core/image',
				[
					'id'       => 12,
					'url'      => $base_url . '/2020/01/lorem.png',
					'sizeSlug' => 'full',
				]
			)
		);
		$without = $this->markup(
			Spec::block(
				'core/image',
				[
					'url'      => $relative . '/2020/01/lorem.png',
					'sizeSlug' => 'full',
				]
			)
		);

		$raw = '<p><img src="' . $base_url . '/x.png" class="wp-image-7 alignnone size-full"></p>';

		$this->assertSame( $without, Portable::markup( $with_id ) );
		$this->assertSame( '<p><img src="' . $relative . '/x.png" class="alignnone size-full"></p>', Portable::markup( $raw ) );
	}

	/**
	 * Portable markup drops the attachment ID of a background image, at the
	 * base style and in a viewport state.
	 *
	 * @return void
	 */
	public function test_portable_markup_drops_background_image_ids(): void {
		$image = [
			'url'    => 'https://example.com/lorem.png',
			'source' => 'file',
			'title'  => '',
			'id'     => 55,
		];

		$markup = $this->markup(
			Spec::block(
				'core/group',
				[
					'style' => [
						'background' => [ 'backgroundImage' => $image ],
						'@tablet'    => [ 'background' => [ 'backgroundImage' => $image ] ],
					],
				]
			)
		);

		$portable = Portable::markup( $markup );

		$this->assertStringContainsString( '"id":55', $markup );
		$this->assertStringNotContainsString( '"id":55', $portable );
		$this->assertStringContainsString( 'https://example.com/lorem.png', $portable );
	}

	/**
	 * A block without color support gets no color classes.
	 *
	 * @return void
	 */
	public function test_block_without_color_support_gets_no_color_classes(): void {
		$attributes = [
			'backgroundColor' => 'contrast',
			'textColor'       => 'base',
		];

		$spacer    = new Supports( WP_Block_Type_Registry::get_instance()->get_registered( 'core/spacer' ) );
		$paragraph = new Supports( WP_Block_Type_Registry::get_instance()->get_registered( 'core/paragraph' ) );

		$this->assertSame( 'wp-block-spacer', $spacer->props( $attributes )['class'] );
		$this->assertStringContainsString( 'has-contrast-background-color', $paragraph->props( $attributes )['class'] );
	}

	/**
	 * A gradient drops the background color class: a custom gradient on a
	 * block wrapper with gradient support, and any gradient on an inner
	 * element.
	 *
	 * @return void
	 */
	public function test_gradient_drops_background_color_class(): void {
		$group = new Supports( WP_Block_Type_Registry::get_instance()->get_registered( 'core/group' ) );

		$custom = $group->props(
			[
				'backgroundColor' => 'contrast',
				'style'           => [ 'color' => [ 'gradient' => 'linear-gradient(red,blue)' ] ],
			]
		)['class'];

		$this->assertStringNotContainsString( 'has-contrast-background-color', $custom );
		$this->assertStringContainsString( 'has-background', $custom );

		$inner = $group->color_classes(
			[
				'backgroundColor' => 'contrast',
				'gradient'        => 'vivid',
			],
			true
		);

		$this->assertStringNotContainsString( 'has-contrast-background-color', $inner );
		$this->assertStringContainsString( 'has-vivid-gradient-background', $inner );
	}

	/**
	 * Returns the block markup for a block spec.
	 *
	 * @param array $spec Block spec.
	 * @return string
	 */
	private function markup( array $spec ): string {
		return Converter::create()->serializer()->serialize( [ $spec ] );
	}
}
