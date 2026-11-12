<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use WP_UnitTestCase;

/**
 * Tests the button, code and post title rules.
 */
class ButtonCodePostTitleRuleTest extends WP_UnitTestCase {

	/**
	 * Converts Divi content into block specs.
	 *
	 * @param string $content Divi content.
	 * @return array[]
	 */
	private function specs( string $content ): array {
		return Converter::create()->convert_content( $content );
	}

	/**
	 * Returns the block markup for Divi content.
	 *
	 * @param string $content Divi content.
	 * @return string
	 */
	private function markup( string $content ): string {
		$converter = Converter::create();

		return $converter->serializer()->serialize( $converter->convert_content( $content ) );
	}

	/**
	 * Returns the names of the blocks in a list of specs.
	 *
	 * @param array[] $specs Block specs.
	 * @return string[]
	 */
	private function names( array $specs ): array {
		return array_map( fn( $spec ) => $spec['name'], $specs );
	}

	/**
	 * A button converts to a Buttons block with one Button that has the
	 * escaped text and the URL.
	 *
	 * @return void
	 */
	public function test_button_has_text_and_url(): void {
		$specs = $this->specs( '[et_pb_button button_text="Lorem &amp; ipsum" button_url="https://example.com/lorem?a=1&b=2"][/et_pb_button]' );

		$this->assertSame( 'core/buttons', $specs[0]['name'] );
		$this->assertSame( [ 'core/button' ], $this->names( $specs[0]['innerBlocks'] ) );

		$button = $specs[0]['innerBlocks'][0]['attributes'];

		$this->assertSame( 'https://example.com/lorem?a=1&b=2', $button['url'] );
		$this->assertStringContainsString( 'Lorem', $button['text'] );
		$this->assertArrayNotHasKey( 'linkTarget', $button );
		$this->assertArrayNotHasKey( 'rel', $button );
	}

	/**
	 * The saved button markup holds the link with the text and URL.
	 *
	 * @return void
	 */
	public function test_button_markup_holds_link(): void {
		$markup = $this->markup( '[et_pb_button button_text="Lorem ipsum" button_url="https://example.com/lorem"][/et_pb_button]' );

		$this->assertStringContainsString( '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com/lorem">Lorem ipsum</a></div>', $markup );
	}

	/**
	 * The new window setting sets the target and rel of the button.
	 *
	 * @return void
	 */
	public function test_button_new_window_sets_target_and_rel(): void {
		$specs = $this->specs( '[et_pb_button button_text="Lorem" button_url="https://example.com/" url_new_window="on"][/et_pb_button]' );

		$button = $specs[0]['innerBlocks'][0]['attributes'];

		$this->assertSame( '_blank', $button['linkTarget'] );
		$this->assertSame( 'noopener', $button['rel'] );

		$markup = $this->markup( '[et_pb_button button_text="Lorem" button_url="https://example.com/" url_new_window="on"][/et_pb_button]' );

		$this->assertStringContainsString( 'href="https://example.com/" target="_blank" rel="noopener">Lorem</a>', $markup );
	}

	/**
	 * A button without text converts to nothing.
	 *
	 * @return void
	 */
	public function test_button_without_text_converts_to_nothing(): void {
		$this->assertSame( [], $this->specs( '[et_pb_button button_url="https://example.com/"][/et_pb_button]' ) );
	}

	/**
	 * The alignment sets the flex justification, and a different tablet or
	 * phone alignment adds a viewport override.
	 *
	 * @return void
	 */
	public function test_button_alignment_sets_justification(): void {
		$none  = $this->specs( '[et_pb_button button_text="Lorem"][/et_pb_button]' );
		$right = $this->specs( '[et_pb_button button_text="Lorem" button_alignment="right"][/et_pb_button]' );
		$mixed = $this->specs( '[et_pb_button button_text="Lorem" button_alignment="left" button_alignment_last_edited="on|tablet" button_alignment_tablet="center" button_alignment_phone="left"][/et_pb_button]' );

		$this->assertSame( [ 'type' => 'flex' ], $none[0]['attributes']['layout'] );
		$this->assertSame( 'right', $right[0]['attributes']['layout']['justifyContent'] );
		$this->assertSame( 'left', $mixed[0]['attributes']['layout']['justifyContent'] );
		$this->assertSame( 'center', $mixed[0]['attributes']['style']['@tablet']['layout']['justifyContent'] );
		$this->assertArrayNotHasKey( '@mobile', $mixed[0]['attributes']['style'] );
	}

	/**
	 * Button styles apply only when custom button styles are on.
	 *
	 * @return void
	 */
	public function test_button_styles_need_custom_button(): void {
		$off = $this->specs( '[et_pb_button button_text="Lorem" button_bg_color="#112233" button_text_color="#445566"][/et_pb_button]' );
		$on  = $this->specs( '[et_pb_button button_text="Lorem" custom_button="on" button_bg_color="#112233" button_text_color="#445566"][/et_pb_button]' );

		$this->assertArrayNotHasKey( 'color', $off[0]['innerBlocks'][0]['attributes']['style'] ?? [] );
		$this->assertArrayHasKey( 'color', $on[0]['innerBlocks'][0]['attributes']['style'] );
	}

	/**
	 * HTML in a code module converts to a Custom HTML block with the code
	 * as it is.
	 *
	 * @return void
	 */
	public function test_code_html_becomes_html_block(): void {
		$code  = '<div class="lorem"><script>console.log("ipsum");</script></div>';
		$specs = $this->specs( '[et_pb_code]' . $code . '[/et_pb_code]' );

		$this->assertSame( 'core/html', $specs[0]['name'] );
		$this->assertSame( $code, $specs[0]['attributes']['content'] );
		$this->assertSame( '<!-- wp:html -->' . "\n" . $code . "\n" . '<!-- /wp:html -->', trim( $this->markup( '[et_pb_code]' . $code . '[/et_pb_code]' ) ) );
	}

	/**
	 * The code is trimmed, and a full-width code module converts the same way.
	 *
	 * @return void
	 */
	public function test_code_is_trimmed_and_fullwidth_converts(): void {
		$specs = $this->specs( '[et_pb_fullwidth_code]  <p>Lorem</p>  [/et_pb_fullwidth_code]' );

		$this->assertSame( 'core/html', $specs[0]['name'] );
		$this->assertSame( '<p>Lorem</p>', $specs[0]['attributes']['content'] );
	}

	/**
	 * A single shortcode converts to a Shortcode block.
	 *
	 * @return void
	 */
	public function test_code_single_shortcode_becomes_shortcode_block(): void {
		$specs = $this->specs( '[et_pb_code][lorem id="1"]ipsum[/lorem][/et_pb_code]' );

		$this->assertSame( 'core/shortcode', $specs[0]['name'] );
		$this->assertSame( '[lorem id="1"]ipsum[/lorem]', $specs[0]['attributes']['text'] );
		$this->assertStringContainsString( '<!-- wp:shortcode -->' . "\n" . '[lorem id="1"]ipsum[/lorem]' . "\n" . '<!-- /wp:shortcode -->', $this->markup( '[et_pb_code][lorem id="1"]ipsum[/lorem][/et_pb_code]' ) );
	}

	/**
	 * A shortcode with other text around it stays HTML.
	 *
	 * @return void
	 */
	public function test_code_shortcode_with_text_stays_html(): void {
		$specs = $this->specs( '[et_pb_code]<p>Lorem</p>[ipsum][/et_pb_code]' );

		$this->assertSame( 'core/html', $specs[0]['name'] );
	}

	/**
	 * An empty code module converts to nothing.
	 *
	 * @return void
	 */
	public function test_empty_code_converts_to_nothing(): void {
		$this->assertSame( [], $this->specs( '[et_pb_code]   [/et_pb_code]' ) );
	}

	/**
	 * A code module with a module class is wrapped in a Group with the class.
	 *
	 * @return void
	 */
	public function test_code_with_module_class_is_wrapped(): void {
		$specs = $this->specs( '[et_pb_code module_class="lorem-code"]<p>Ipsum</p>[/et_pb_code]' );

		$this->assertSame( 'core/group', $specs[0]['name'] );
		$this->assertSame( 'lorem-code', $specs[0]['attributes']['className'] );
		$this->assertSame( [ 'core/html' ], $this->names( $specs[0]['innerBlocks'] ) );
	}

	/**
	 * A post title module shows the title, the meta group and the featured
	 * image below by default.
	 *
	 * @return void
	 */
	public function test_post_title_defaults(): void {
		$specs = $this->specs( '[et_pb_post_title][/et_pb_post_title]' );

		$this->assertSame( 'core/group', $specs[0]['name'] );
		$this->assertSame( [ 'core/post-title', 'core/group', 'core/post-featured-image' ], $this->names( $specs[0]['innerBlocks'] ) );
		$this->assertSame( 1, $specs[0]['innerBlocks'][0]['attributes']['level'] );

		$meta = array_map( fn( $spec ) => $spec['name'], $specs[0]['innerBlocks'][1]['innerBlocks'] );

		$this->assertSame( [ 'core/post-author-name', 'core/post-date', 'core/post-terms', 'core/post-comments-link' ], $meta );
		$this->assertSame( 'category', $specs[0]['innerBlocks'][1]['innerBlocks'][2]['attributes']['term'] );
	}

	/**
	 * The meta setting off removes the whole meta group.
	 *
	 * @return void
	 */
	public function test_post_title_meta_off_removes_meta_group(): void {
		$specs = $this->specs( '[et_pb_post_title meta="off"][/et_pb_post_title]' );

		$this->assertSame( [ 'core/post-title', 'core/post-featured-image' ], $this->names( $specs[0]['innerBlocks'] ) );
	}

	/**
	 * Each meta item has its own toggle, and the group is dropped when all
	 * items are off.
	 *
	 * @return void
	 */
	public function test_post_title_meta_items_toggle(): void {
		$some = $this->specs( '[et_pb_post_title author="off" categories="off"][/et_pb_post_title]' );
		$none = $this->specs( '[et_pb_post_title author="off" date="off" categories="off" comments="off"][/et_pb_post_title]' );

		$meta = array_map( fn( $spec ) => $spec['name'], $some[0]['innerBlocks'][1]['innerBlocks'] );

		$this->assertSame( [ 'core/post-date', 'core/post-comments-link' ], $meta );
		$this->assertSame( [ 'core/post-title', 'core/post-featured-image' ], $this->names( $none[0]['innerBlocks'] ) );
	}

	/**
	 * The title and featured image toggles remove their blocks, and the
	 * placement sets where the image goes.
	 *
	 * @return void
	 */
	public function test_post_title_toggles_and_image_placement(): void {
		$no_title = $this->specs( '[et_pb_post_title title="off" featured_image="off"][/et_pb_post_title]' );
		$above    = $this->specs( '[et_pb_post_title featured_placement="above"][/et_pb_post_title]' );

		$this->assertSame( [ 'core/group' ], $this->names( $no_title[0]['innerBlocks'] ) );
		$this->assertSame( [ 'core/post-featured-image', 'core/post-title', 'core/group' ], $this->names( $above[0]['innerBlocks'] ) );
	}

	/**
	 * The text alignment reaches the title and the meta group.
	 *
	 * @return void
	 */
	public function test_post_title_alignment_reaches_title_and_meta(): void {
		$specs = $this->specs( '[et_pb_post_title text_orientation="center" title_all_caps="on"][/et_pb_post_title]' );

		$title = $specs[0]['innerBlocks'][0]['attributes']['style']['typography'];

		$this->assertSame( 'center', $title['textAlign'] );
		$this->assertSame( 'uppercase', $title['textTransform'] );
		$this->assertSame( 'center', $specs[0]['innerBlocks'][1]['attributes']['layout']['justifyContent'] );
	}

	/**
	 * The saved markup holds the post blocks in order.
	 *
	 * @return void
	 */
	public function test_post_title_markup_holds_post_blocks(): void {
		$markup = $this->markup( '[et_pb_post_title][/et_pb_post_title]' );

		$this->assertStringContainsString( '<!-- wp:post-title {"level":1,"style":{"typography":{"textAlign":"left"}}} /-->', $markup );
		$this->assertStringContainsString( '<!-- wp:post-terms {"term":"category"} /-->', $markup );
		$this->assertLessThan( strpos( $markup, '<!-- wp:post-featured-image /-->' ), strpos( $markup, '<!-- wp:post-title ' ) );
	}
}
