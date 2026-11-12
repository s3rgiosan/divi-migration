<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Serializer\RawHandler;
use S3S\WP\DiviMigration\Serializer\Serializer;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Targets\CoreTable;

/**
 * Tests the raw handler through the serializer, with classic content as
 * `convert --classic` reads it: after `wpautop()`.
 */
class RawHandlerTest extends TestCase {

	/**
	 * Removes the files that the attachment factory uploads.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * Returns the cases for the `more` comment: the classic content and the
	 * More block attributes it gets.
	 *
	 * @return array[]
	 */
	public static function data_more(): array {
		return [
			'bare'                  => [ "Lorem ipsum.\n\n<!--more-->\n\nDolor sit amet.", [] ],
			'in a paragraph'        => [ "<p>Lorem ipsum.</p>\n<p><!--more--></p>\n<p>Dolor sit amet.</p>", [] ],
			'inline after text'     => [ 'Lorem ipsum.<!--more-->Dolor sit amet.', [] ],
			'custom text'           => [ "Lorem ipsum.\n\n<!--more Read on-->\n\nDolor sit amet.", [ 'customText' => 'Read on' ] ],
			'noteaser on same line' => [ "Lorem ipsum.\n\n<!--more--><!--noteaser-->\n\nDolor sit amet.", [ 'noTeaser' => true ] ],
			'qTranslate comments'   => [ '<!--:en-->Lorem ipsum.<!--:--><!--more--><!--:en-->Dolor sit amet.<!--:-->', [] ],
		];
	}

	/**
	 * The `more` comment becomes a More block between the paragraphs around
	 * it.
	 *
	 * @dataProvider data_more
	 *
	 * @param string $html       Classic content.
	 * @param array  $attributes More block attributes.
	 * @return void
	 */
	public function test_more_comment_becomes_more_block( string $html, array $attributes ): void {
		$blocks = $this->blocks( $html );

		$this->assertSame( [ 'core/paragraph', 'core/more', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( $attributes, $blocks[1]['attrs'] );
		$this->assertStringContainsString( 'Lorem ipsum.', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( 'Dolor sit amet.', $blocks[2]['innerHTML'] );
		$this->assertStringNotContainsString( '<!--:', $this->markup( $html ) );
	}

	/**
	 * A `noteaser` comment on the line after `more` sets `noTeaser`, as core
	 * reads it anywhere in the content and the editor's raw handler reads it
	 * from any later sibling of `more`. `wpautop()` puts a `<br />` between
	 * the two comments.
	 *
	 * @return void
	 */
	public function test_noteaser_on_next_line_sets_no_teaser(): void {
		$blocks = $this->blocks( "Lorem ipsum.\n\n<!--more-->\n<!--noteaser-->\n\nDolor sit amet." );
		$more   = array_values( array_filter( $blocks, fn( array $block ) => 'core/more' === $block['blockName'] ) );

		$this->assertCount( 1, $more );
		$this->assertSame( [ 'noTeaser' => true ], $more[0]['attrs'] );
		$this->assertSame( [ 'core/paragraph', 'core/more', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
	}

	/**
	 * The `nextpage` comment becomes a Page Break block.
	 *
	 * @return void
	 */
	public function test_nextpage_comment_becomes_page_break_block(): void {
		$blocks = $this->blocks( "Lorem ipsum.\n\n<!--nextpage-->\n\nDolor sit amet." );

		$this->assertSame( [ 'core/paragraph', 'core/nextpage', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
	}

	/**
	 * Block comments on their own lines in classic content stay out of the
	 * HTML of the blocks around them.
	 *
	 * @return void
	 */
	public function test_block_comments_on_own_lines_stay_out_of_block_html(): void {
		$blocks = $this->blocks( "<p>Lorem ipsum.</p>\n<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->\n<p>Dolor sit amet.</p>" );

		$this->assertContains( 'core/separator', array_column( $blocks, 'blockName' ) );
		$this->assert_flat_blocks( $blocks );
	}

	/**
	 * A block comment on the line after text stays out of the paragraph's
	 * HTML. `wpautop()` puts the comment inside the paragraph; the block
	 * stays a block with its attributes, and the paragraph before it ends
	 * without a line break.
	 *
	 * @return void
	 */
	public function test_block_comment_after_text_line_stays_out_of_paragraph_html(): void {
		$blocks = $this->blocks( "Lorem ipsum.\n<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Dolor sit amet.</p>\n<!-- /wp:paragraph -->" );

		$this->assert_flat_blocks( $blocks );
		$this->assertSame( [ 'core/paragraph', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( [ 'align' => 'center' ], $blocks[1]['attrs'] );
		$this->assertSame( '<p>Lorem ipsum.</p>', trim( $blocks[0]['innerHTML'] ) );
	}

	/**
	 * Serialized blocks in text keep their attributes and markup, and the
	 * paragraph tags that `wpautop()` writes around their delimiters leave no
	 * empty paragraph and no line break.
	 *
	 * @return void
	 */
	public function test_serialized_blocks_in_text_stay_as_they_are(): void {
		$blocks = $this->blocks( "Lorem ipsum.\n<!-- wp:group {\"className\":\"is-style-lorem\"} -->\n<div class=\"wp-block-group is-style-lorem\"><!-- wp:paragraph -->\n<p>Dolor sit amet.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->\nConsectetur adipiscing." );

		$this->assertSame( [ 'core/paragraph', 'core/group', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( [ 'className' => 'is-style-lorem' ], $blocks[1]['attrs'] );
		$this->assertSame( "\n<div class=\"wp-block-group is-style-lorem\">", $blocks[1]['innerContent'][0] );
		$this->assertSame( '<p>Dolor sit amet.</p>', trim( $blocks[1]['innerBlocks'][0]['innerHTML'] ) );
		$this->assertSame( '<p>Lorem ipsum.</p>', trim( $blocks[0]['innerHTML'] ) );
		$this->assertSame( '<p>Consectetur adipiscing.</p>', trim( $blocks[2]['innerHTML'] ) );
	}

	/**
	 * Returns the cases for block markup in a Divi text module: as the Divi
	 * builder saves it, and with the paragraph tags that `wpautop()` writes.
	 *
	 * @return array[]
	 */
	public static function data_block_markup_in_divi_text(): array {
		return [
			'as saved'    => [ "Lorem ipsum.\n<!-- wp:paragraph {\"align\":\"center\"} -->\n<p class=\"has-text-align-center\">Dolor sit amet.</p>\n<!-- /wp:paragraph -->" ],
			'after autop' => [ "<p>Lorem ipsum.<br />\n<!-- wp:paragraph {\"align\":\"center\"} --></p>\n<p class=\"has-text-align-center\">Dolor sit amet.</p>\n<p><!-- /wp:paragraph --></p>" ],
		];
	}

	/**
	 * Block markup in a Divi text module stays a block with its attributes.
	 * The text before it gets no line break, and no empty paragraph follows
	 * the block.
	 *
	 * @dataProvider data_block_markup_in_divi_text
	 *
	 * @param string $text Text module content.
	 * @return void
	 */
	public function test_block_markup_in_divi_text_keeps_its_attributes( string $text ): void {
		$blocks = $this->divi_text_blocks( $text );

		$this->assertSame( [ 'core/paragraph', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( '<p>Lorem ipsum.</p>', trim( $blocks[0]['innerHTML'] ) );
		$this->assertSame( [ 'align' => 'center' ], $blocks[1]['attrs'] );
		$this->assertSame( '<p class="has-text-align-center">Dolor sit amet.</p>', trim( $blocks[1]['innerHTML'] ) );
	}

	/**
	 * Block delimiters that do not pair up are removed, and the HTML around
	 * them converts as usual.
	 *
	 * @return void
	 */
	public function test_unpaired_block_delimiters_are_removed(): void {
		$blocks = $this->raw_blocks( '<p>Lorem ipsum.</p><!-- wp:paragraph --><p>Dolor sit amet.</p>' );

		$this->assert_flat_blocks( $blocks );
		$this->assertSame( [ 'core/paragraph', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
	}

	/**
	 * A linked image already in a figure stays in that figure and keeps its
	 * link, relationship and target.
	 *
	 * @return void
	 */
	public function test_linked_image_in_figure_keeps_link(): void {
		$blocks = $this->raw_blocks( '<figure class="wp-block-image"><a href="https://example.com/lorem/" rel="noopener" target="_blank"><img src="https://example.com/lorem.jpg" alt="Lorem"></a></figure>' );

		$this->assertSame( [ 'core/image' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( 'custom', $blocks[0]['attrs']['linkDestination'] );
		$this->assertSame( 1, substr_count( $blocks[0]['innerHTML'], '<figure' ) );
		$this->assertStringContainsString( '<a href="https://example.com/lorem/" target="_blank" rel="noopener"><img src="https://example.com/lorem.jpg" alt="Lorem"/></a>', $blocks[0]['innerHTML'] );
	}

	/**
	 * The ID of a paragraph or heading becomes its anchor.
	 *
	 * @return void
	 */
	public function test_text_ids_become_anchors(): void {
		$blocks = $this->raw_blocks( '<p id="lorem">Lorem ipsum.</p><h2 id="dolor">Dolor sit amet</h2>' );

		$this->assertSame( [ 'anchor' => 'lorem' ], $blocks[0]['attrs'] );
		$this->assertSame( [ 'anchor' => 'dolor' ], $blocks[1]['attrs'] );
		$this->assertStringContainsString( '<p id="lorem">', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( 'id="dolor"', $blocks[1]['innerHTML'] );
	}

	/**
	 * The text of a list item before its nested list keeps its entities and
	 * the white space between its elements.
	 *
	 * @return void
	 */
	public function test_list_item_text_before_nested_list_keeps_entities(): void {
		$blocks = $this->raw_blocks( '<ul><li>Lorem &lt;b&gt; &amp; <b>ipsum</b> <i>dolor</i><ul><li>Sit amet.</li></ul></li></ul>' );
		$item   = $blocks[0]['innerBlocks'][0];

		$this->assertSame( 'core/list-item', $item['blockName'] );
		$this->assertStringStartsWith( '<li>Lorem &lt;b&gt; &amp; <b>ipsum</b> <i>dolor</i>', trim( $item['innerContent'][0] ) );
		$this->assertSame( 'core/list', $item['innerBlocks'][0]['blockName'] );
	}

	/**
	 * The rows of several table bodies, and rows outside a section, all go to
	 * the table body in their order.
	 *
	 * @return void
	 */
	public function test_table_sections_keep_all_rows(): void {
		$blocks = $this->raw_blocks( '<table><thead><tr><th>Lorem</th></tr></thead><tbody><tr><td>Ipsum</td></tr></tbody><tbody><tr><td>Dolor</td></tr></tbody><tr><td>Sit</td></tr></table>' );

		$this->assertSame( [ 'core/table' ], array_column( $blocks, 'blockName' ) );
		$this->assertStringContainsString( '<tbody><tr><td>Ipsum</td></tr><tr><td>Dolor</td></tr><tr><td>Sit</td></tr></tbody>', $blocks[0]['innerHTML'] );
	}

	/**
	 * A video that plays from `<source>` elements becomes a Custom HTML block
	 * with the video, and a video with a `src` becomes a Video block.
	 *
	 * @return void
	 */
	public function test_video_with_sources_becomes_custom_html(): void {
		$blocks = $this->raw_blocks( '<p><video controls><source src="https://example.com/lorem.mp4" type="video/mp4"></video></p><p><video src="https://example.com/ipsum.mp4"></video></p>' );

		$this->assertSame( [ 'core/html', 'core/video' ], array_column( $blocks, 'blockName' ) );
		$this->assertStringContainsString( '<video controls=""><source src="https://example.com/lorem.mp4" type="video/mp4"></video>', $blocks[0]['innerHTML'] );
	}

	/**
	 * A classed `<div>` becomes a Group with its ID as anchor and its inline
	 * styles as attributes. A `<div>` with other attributes becomes a Custom
	 * HTML block.
	 *
	 * @return void
	 */
	public function test_classed_div_keeps_id_styles_and_attributes(): void {
		$blocks = $this->raw_blocks( '<div class="lorem" id="ipsum" style="padding:20px;color:#123456"><p>Dolor.</p></div><div class="lorem" data-lorem="ipsum"><p>Sit amet.</p></div>' );

		$this->assertSame( [ 'core/group', 'core/html' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( 'ipsum', $blocks[0]['attrs']['anchor'] );
		$this->assertSame( '#123456', $blocks[0]['attrs']['style']['color']['text'] );
		$this->assertSame( '20px', $blocks[0]['attrs']['style']['spacing']['padding']['top'] );
		$this->assertStringContainsString( 'id="ipsum"', $blocks[0]['innerContent'][0] );
		$this->assertStringContainsString( '<div class="lorem" data-lorem="ipsum"><p>Sit amet.</p></div>', $blocks[1]['innerHTML'] );
	}

	/**
	 * A block delimiter inside a shortcode stays out of the Shortcode block,
	 * so the block ends where it should.
	 *
	 * @return void
	 */
	public function test_comment_in_shortcode_stays_out_of_shortcode_block(): void {
		$blocks = $this->raw_blocks( '<p>[lorem]<!-- /wp:shortcode -->Ipsum.[/lorem]</p><p>Dolor sit amet.</p>' );

		$this->assertSame( [ 'core/shortcode', 'core/paragraph' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( '[lorem]Ipsum.[/lorem]', trim( $blocks[0]['innerHTML'] ) );
	}

	/**
	 * A `[caption]` shortcode inside a paragraph becomes an Image block, even
	 * with text next to the image.
	 *
	 * @return void
	 */
	public function test_caption_in_paragraph_becomes_image(): void {
		$blocks = $this->raw_blocks( '<p>[caption id="attachment_100" align="alignnone" width="300"]<img class="wp-image-100" src="https://example.com/lorem.jpg" alt="" width="300" height="200" /> Lorem ipsum.[/caption]</p>' );

		$this->assertSame( [ 'core/image' ], array_column( $blocks, 'blockName' ) );
		$this->assertStringContainsString( 'Lorem ipsum.</figcaption>', $blocks[0]['innerHTML'] );
	}

	/**
	 * Text outside an element keeps its escaped entities.
	 *
	 * @return void
	 */
	public function test_top_level_text_keeps_entities(): void {
		$blocks = $this->raw_blocks( 'Lorem &amp;lt;b&amp;gt; ipsum.' );

		$this->assertSame( '<p>Lorem &amp;lt;b&amp;gt; ipsum.</p>', trim( $blocks[0]['innerHTML'] ) );
	}

	/**
	 * A `[gallery]` shortcode becomes a Gallery block with an Image block per
	 * attachment, with the attachment's URL, alternative text and caption.
	 *
	 * @return void
	 */
	public function test_gallery_shortcode_becomes_gallery_block(): void {
		$ids = [];

		foreach ( [ 'Lorem', 'Ipsum' ] as $label ) {
			$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

			update_post_meta( $id, '_wp_attachment_image_alt', $label . ' alt' );
			wp_update_post(
				[
					'ID'           => $id,
					'post_excerpt' => $label . ' caption',
				]
			);

			$ids[] = $id;
		}

		$blocks = $this->blocks( sprintf( '[gallery ids="%s" columns="2" link="file"]', implode( ',', $ids ) ) );

		$this->assertSame( [ 'core/gallery' ], array_column( $blocks, 'blockName' ) );
		$this->assertSame( 2, $blocks[0]['attrs']['columns'] );
		$this->assertSame( 'media', $blocks[0]['attrs']['linkTo'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );

		foreach ( [ 'Lorem', 'Ipsum' ] as $index => $label ) {
			$image = $blocks[0]['innerBlocks'][ $index ];
			$url   = (string) wp_get_attachment_url( $ids[ $index ] );

			$this->assertSame( 'core/image', $image['blockName'] );
			$this->assertSame( $ids[ $index ], $image['attrs']['id'] );
			$this->assertSame( 'media', $image['attrs']['linkDestination'] );
			$this->assertStringContainsString( 'alt="' . $label . ' alt"', $image['innerHTML'] );
			$this->assertStringContainsString( $label . ' caption', $image['innerHTML'] );
			$this->assertStringContainsString( 'href="' . $url . '"', $image['innerHTML'] );
			$this->assertStringContainsString( 'class="wp-image-' . $ids[ $index ] . '"', $image['innerHTML'] );
		}
	}

	/**
	 * Text written as bare `<div>` lines becomes one paragraph with line
	 * breaks. Divs next to other elements, or with attributes, stay.
	 *
	 * @return void
	 */
	public function test_div_lines_become_one_paragraph(): void {
		$lines = $this->blocks( "<div>Lorem ipsum</div>\n<div><strong>Dolor</strong> sit</div>\n<div></div>" );
		$mixed = $this->blocks( '<p>Lorem</p><div>Ipsum</div>' );
		$class = $this->blocks( '<div class="lorem">Ipsum</div>' );

		$this->assertSame( [ 'core/paragraph' ], array_column( $lines, 'blockName' ) );
		$this->assertStringContainsString( 'Lorem ipsum<br><strong>Dolor</strong> sit', $lines[0]['innerHTML'] );
		$this->assertSame( [ 'core/paragraph', 'core/html' ], array_column( $mixed, 'blockName' ) );
		$this->assertNotSame( [ 'core/paragraph' ], array_column( $class, 'blockName' ) );
	}

	/**
	 * Module attributes apply as defaults: a paragraph keeps its own inline
	 * text alignment.
	 *
	 * @return void
	 */
	public function test_apply_attributes_do_not_override_own_attributes(): void {
		$apply = [ 'core/paragraph' => [ 'style' => [ 'typography' => [ 'textAlign' => 'center' ] ] ] ];

		$own     = Converter::create()->serializer()->blocks( [ Spec::raw( '<p style="text-align:right">Lorem</p>', $apply ) ] );
		$default = Converter::create()->serializer()->blocks( [ Spec::raw( '<p>Ipsum</p>', $apply ) ] );

		$this->assertSame( 'right', $own[0]['attrs']['style']['typography']['textAlign'] );
		$this->assertSame( 'center', $default[0]['attrs']['style']['typography']['textAlign'] );
	}

	/**
	 * A `[button]` link goes through URL sanitizing.
	 *
	 * @return void
	 */
	public function test_button_shortcode_link_is_sanitized(): void {
		$markup = $this->markup( '[button link="javascript:alert(1)"]Lorem[/button]' );

		$this->assertStringContainsString( 'wp-block-button', $markup );
		$this->assertStringNotContainsString( 'javascript:', $markup );
	}

	/**
	 * A paragraph with inline images drops the HTML comments in its content.
	 *
	 * @return void
	 */
	public function test_inline_image_paragraph_drops_comments(): void {
		$markup = $this->markup( '<p>Lorem <img src="https://example.com/a.png"> <!-- ipsum --> dolor <img src="https://example.com/b.png"></p>' );

		$this->assertStringNotContainsString( 'ipsum', $markup );
		$this->assertStringContainsString( 'dolor', $markup );
	}

	/**
	 * Audio and video flags read the string `false` as off.
	 *
	 * @return void
	 */
	public function test_media_shortcode_flags_read_false_as_off(): void {
		$off = $this->markup( '[audio src="https://example.com/a.mp3" loop="false" autoplay="false"][/audio]' );
		$on  = $this->markup( '[video src="https://example.com/a.mp4" loop="true" autoplay="1"][/video]' );

		// Both flags are source attributes of the block, so the saved element holds them.
		$this->assertStringContainsString( '<audio ', $off );
		$this->assertDoesNotMatchRegularExpression( '/<audio[^>]*\s(loop|autoplay)[\s=>]/', $off );
		$this->assertMatchesRegularExpression( '/<video[^>]*\sloop[\s=>]/', $on );
		$this->assertMatchesRegularExpression( '/<video[^>]*\sautoplay[\s=>]/', $on );
	}

	/**
	 * The embed provider table holds the editor's Instagram, Facebook and
	 * WordPress variations with their responsive settings.
	 *
	 * @return void
	 */
	public function test_embed_providers_hold_editor_variations(): void {
		$this->assertTrue( RawHandler::EMBED_PROVIDERS['instagram'][0] );
		$this->assertTrue( RawHandler::EMBED_PROVIDERS['facebook'][0] );
		$this->assertFalse( RawHandler::EMBED_PROVIDERS['wordpress'][0] );
		$this->assertSame( 'dailymotion', RawHandler::embed_provider( 'https://www.dailymotion.com/video/lorem' )['slug'] );
		$this->assertSame( [], RawHandler::embed_provider( 'https://example.com/lorem' ) );
	}

	/**
	 * A text alignment with no block attribute adds a note to the serializer.
	 *
	 * @return void
	 */
	public function test_dropped_text_align_adds_note(): void {
		$paragraph = Converter::create()->serializer();
		$paragraph->serialize( [ Spec::raw( '<p style="text-align:justify">Lorem</p>' ) ] );

		$this->assertContains( 'dropped inline style: text-align', $paragraph->notes );

		$list = Converter::create()->serializer();
		$list->serialize( [ Spec::raw( '<ul><li style="text-align:center">Lorem</li></ul>' ) ] );

		$this->assertContains( 'dropped inline style: text-align', $list->notes );

		$kept = Converter::create()->serializer();
		$kept->serialize( [ Spec::raw( '<p style="text-align:center">Lorem</p>' ) ] );

		$this->assertNotContains( 'dropped inline style: text-align', $kept->notes );
	}

	/**
	 * An inline style with no block attribute adds a note to the serializer.
	 *
	 * @return void
	 */
	public function test_dropped_inline_style_adds_note(): void {
		$serializer = Converter::create()->serializer();

		$serializer->serialize( [ Spec::raw( '<div class="lorem" style="float:left;color:#123456">Ipsum</div>' ) ] );

		$this->assertContains( 'dropped inline style: float', $serializer->notes );
		$this->assertNotContains( 'dropped inline style: color', $serializer->notes );
	}

	/**
	 * A palette color slug becomes its kebab-case class in the text color
	 * format.
	 *
	 * @return void
	 */
	public function test_inline_color_class_uses_kebab_case_slug(): void {
		$presets    = ( new Presets() )->with( [ '#aabbcc' => 'accent_1' ], [] );
		$serializer = new Serializer( new RawHandler( new CoreTable(), $presets ) );

		$markup = $serializer->serialize( [ Spec::raw( '<p>Lorem <span style="color:#aabbcc">ipsum</span></p>' ) ] );

		$this->assertStringContainsString( 'has-accent-1-color', $markup );
		$this->assertStringNotContainsString( 'has-accent_1-color', $markup );
	}

	/**
	 * Checks that no block holds a block comment in its HTML, which
	 * `parse_blocks()` reads as inner blocks.
	 *
	 * @param array[] $blocks Parsed blocks.
	 * @return void
	 */
	private function assert_flat_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			$this->assertSame( [], $block['innerBlocks'], sprintf( 'Bug: a block comment is kept inside the HTML of a %s block.', $block['blockName'] ) );
			$this->assertDoesNotMatchRegularExpression( '/<!--\s*\/?wp:/', $block['innerHTML'] );
		}

		$text = implode( '', array_column( $blocks, 'innerHTML' ) );

		$this->assertStringContainsString( 'Lorem ipsum.', $text );
		$this->assertStringContainsString( 'Dolor sit amet.', $text );
	}

	/**
	 * Returns the block markup for classic content.
	 *
	 * @param string $html Classic content.
	 * @return string
	 */
	private function markup( string $html ): string {
		return Converter::create()->serializer()->serialize( [ Spec::raw( wpautop( $html ) ) ] );
	}

	/**
	 * Returns the parsed blocks for classic content, without the whitespace
	 * between blocks.
	 *
	 * @param string $html Classic content.
	 * @return array[]
	 */
	private function blocks( string $html ): array {
		return array_values( array_filter( parse_blocks( $this->markup( $html ) ), fn( array $block ) => null !== $block['blockName'] ) );
	}

	/**
	 * Returns the parsed blocks for HTML, without `wpautop()` and without the
	 * whitespace between blocks.
	 *
	 * @param string $html HTML.
	 * @return array[]
	 */
	private function raw_blocks( string $html ): array {
		$markup = Converter::create()->serializer()->serialize( [ Spec::raw( $html ) ] );

		return array_values( array_filter( parse_blocks( $markup ), fn( array $block ) => null !== $block['blockName'] ) );
	}

	/**
	 * Returns the blocks inside the block of a Divi text module, without the
	 * whitespace between blocks.
	 *
	 * @param string $text Text module content.
	 * @return array[]
	 */
	private function divi_text_blocks( string $text ): array {
		$converter = Converter::create();
		$content   = '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text]' . $text . '[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
		$markup    = $converter->serializer()->serialize( $converter->convert_content( $content ) );
		$block     = parse_blocks( $markup )[0];

		while ( 'is-style-text' !== ( $block['attrs']['className'] ?? '' ) && [] !== $block['innerBlocks'] ) {
			$block = $block['innerBlocks'][0];
		}

		return $block['innerBlocks'];
	}
}
