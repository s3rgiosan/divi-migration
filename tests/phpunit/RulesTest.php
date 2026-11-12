<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Targets\FormData;
use S3S\WP\DiviMigration\Targets\FormTarget;
use WP_UnitTestCase;

/**
 * Tests the conversion rules of single modules: Divi defaults that an absent
 * attribute stands for, and the attributes a module sets.
 */
class RulesTest extends WP_UnitTestCase {

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
	 * Returns a color the way a rule writes it, as a preset reference when
	 * the theme's palette holds it.
	 *
	 * @param string $color CSS color.
	 * @return string
	 */
	private function color( string $color ): string {
		return Converter::create()->presets->color( $color );
	}

	/**
	 * The dark background layout sets light text on a text module, unless the
	 * module sets a text color.
	 *
	 * @return void
	 */
	public function test_text_dark_layout_sets_light_text(): void {
		$dark = $this->specs( '[et_pb_text background_layout="dark"]<p>Lorem ipsum</p>[/et_pb_text]' );
		$own  = $this->specs( '[et_pb_text background_layout="dark" text_text_color="#112233"]<p>Lorem ipsum</p>[/et_pb_text]' );
		$none = $this->specs( '[et_pb_text]<p>Lorem ipsum</p>[/et_pb_text]' );

		$this->assertSame( $this->color( '#ffffff' ), $dark[0]['attributes']['style']['color']['text'] );
		$this->assertSame( '#112233', $own[0]['attributes']['style']['color']['text'] );
		$this->assertArrayNotHasKey( 'color', $none[0]['attributes']['style'] ?? [] );
	}

	/**
	 * The dark background layout reaches the other modules that have one,
	 * and a call to action is dark without the attribute.
	 *
	 * @return void
	 */
	public function test_dark_layout_on_other_modules(): void {
		$white = $this->color( '#ffffff' );

		$blurb = $this->specs( '[et_pb_blurb title="Lorem ipsum" background_layout="dark"]Lorem[/et_pb_blurb]' );
		$this->assertSame( $white, $blurb[0]['attributes']['style']['color']['text'] );

		$cta = $this->specs( '[et_pb_cta title="Lorem ipsum"]Lorem[/et_pb_cta]' );
		$this->assertSame( $white, $cta[0]['attributes']['style']['color']['text'] );

		$light_cta = $this->specs( '[et_pb_cta title="Lorem ipsum" background_layout="light"]Lorem[/et_pb_cta]' );
		$this->assertArrayNotHasKey( 'text', $light_cta[0]['attributes']['style']['color'] ?? [] );

		$testimonial = $this->specs( '[et_pb_testimonial author="Lorem" background_layout="dark"]Lorem[/et_pb_testimonial]' );
		$this->assertSame( $white, $testimonial[0]['attributes']['style']['color']['text'] );
		$this->assertSame( '1.5', $testimonial[0]['attributes']['style']['typography']['lineHeight'] );

		$number = $this->specs( '[et_pb_number_counter title="Lorem" number="10" background_layout="dark"][/et_pb_number_counter]' );
		$this->assertSame( $white, $number[0]['attributes']['style']['color']['text'] );

		$counters = $this->specs( '[et_pb_counters background_layout="dark"][et_pb_counter percent="40"]Lorem[/et_pb_counter][/et_pb_counters]' );
		$this->assertSame( $white, $counters[0]['attributes']['style']['color']['text'] );
	}

	/**
	 * A toggle reads its title settings from `title_*`, and the open title
	 * color falls back to the title color.
	 *
	 * @return void
	 */
	public function test_toggle_reads_title_settings(): void {
		$specs = $this->specs( '[et_pb_toggle title="Lorem ipsum" title_level="h3" title_font_size="30px" toggle_font_size="12px" title_text_color="#111111" open_toggle_text_color="#222222"]Lorem[/et_pb_toggle]' );

		$headings = $this->find( $specs, 'core/accordion-heading' );
		$items    = $this->find( $specs, 'core/accordion-item' );

		$this->assertCount( 1, $headings );
		$this->assertSame( 3, $headings[0]['attributes']['level'] );
		$this->assertSame( '30px', $headings[0]['attributes']['style']['typography']['fontSize'] );

		$css = $items[0]['attributes']['style']['css'];

		$this->assertStringContainsString( 'color: #111111;', $css );
		$this->assertStringContainsString( '&.is-open .wp-block-accordion-heading { color: #222222; }', $css );
	}

	/**
	 * In a column, accordion items are spaced like modules: half the row gap,
	 * as a share of the column, and 30px on tablets and phones. Outside a
	 * column they keep Divi's 10px.
	 *
	 * @return void
	 */
	public function test_accordion_item_gap(): void {
		$accordion = '[et_pb_accordion][et_pb_accordion_item title="Lorem"]Lorem[/et_pb_accordion_item][/et_pb_accordion]';

		$full = $this->find( $this->specs( '[et_pb_section][et_pb_row][et_pb_column type="4_4"]' . $accordion . '[/et_pb_column][/et_pb_row][/et_pb_section]' ), 'core/accordion' )[0]['attributes']['style'];
		$half = $this->find( $this->specs( '[et_pb_section][et_pb_row][et_pb_column type="1_2"]' . $accordion . '[/et_pb_column][et_pb_column type="1_2"]Ipsum[/et_pb_column][/et_pb_row][/et_pb_section]' ), 'core/accordion' )[0]['attributes']['style'];
		$bare = $this->find( $this->specs( $accordion ), 'core/accordion' )[0]['attributes']['style'];

		$this->assertSame( '2.75%', $full['spacing']['blockGap'] );
		$this->assertSame( '30px', $full['@tablet']['spacing']['blockGap'] );
		$this->assertSame( '30px', $full['@mobile']['spacing']['blockGap'] );
		$this->assertSame( '5.82%', $half['spacing']['blockGap'] );
		$this->assertSame( '10px', $bare['spacing']['blockGap'] );
		$this->assertArrayNotHasKey( '@tablet', $bare );
	}

	/**
	 * An accordion reads `toggle_*` settings, and the open title color falls
	 * back to the title color when `open_toggle_text_color` is missing.
	 *
	 * @return void
	 */
	public function test_accordion_open_title_color(): void {
		$specs = $this->specs( '[et_pb_accordion toggle_level="h2" toggle_text_color="#111111"][et_pb_accordion_item title="Lorem ipsum"]Lorem[/et_pb_accordion_item][/et_pb_accordion]' );

		$items   = $this->find( $specs, 'core/accordion-item' );
		$heading = $this->find( $specs, 'core/accordion-heading' );

		$this->assertSame( 2, $heading[0]['attributes']['level'] );
		$this->assertStringContainsString( '&.is-open .wp-block-accordion-heading { color: #111111; }', $items[0]['attributes']['style']['css'] );
	}

	/**
	 * A toggle without border settings gets Divi's border, and a width alone
	 * takes Divi's color and style.
	 *
	 * @return void
	 */
	public function test_accordion_border_defaults(): void {
		$default = $this->find( $this->specs( '[et_pb_toggle title="Lorem ipsum"]Lorem[/et_pb_toggle]' ), 'core/accordion-item' );
		$width   = $this->find( $this->specs( '[et_pb_toggle title="Lorem ipsum" border_width_all="3px"]Lorem[/et_pb_toggle]' ), 'core/accordion-item' );

		$expected = [
			'width' => '1px',
			'style' => 'solid',
			'color' => $this->color( '#d9d9d9' ),
		];

		$this->assertSame( $expected, $default[0]['attributes']['style']['border']['top'] );
		$this->assertSame(
			[
				'width' => '3px',
				'style' => 'solid',
				'color' => $this->color( '#d9d9d9' ),
			],
			$width[0]['attributes']['style']['border']['left']
		);
	}

	/**
	 * A blog shows the author and the pagination unless they are off.
	 *
	 * @return void
	 */
	public function test_blog_author_and_pagination_default_on(): void {
		$defaults = $this->specs( '[et_pb_blog][/et_pb_blog]' );
		$off      = $this->specs( '[et_pb_blog show_author="off" show_pagination="off"][/et_pb_blog]' );

		$this->assertCount( 1, $this->find( $defaults, 'core/post-author-name' ) );
		$this->assertCount( 1, $this->find( $defaults, 'core/query-pagination' ) );
		$this->assertCount( 0, $this->find( $off, 'core/post-author-name' ) );
		$this->assertCount( 0, $this->find( $off, 'core/query-pagination' ) );
	}

	/**
	 * A post title shows the featured image, the author and the comments
	 * link unless they are off.
	 *
	 * @return void
	 */
	public function test_post_title_defaults(): void {
		$defaults = $this->specs( '[et_pb_post_title][/et_pb_post_title]' );
		$off      = $this->specs( '[et_pb_post_title featured_image="off" author="off" comments="off"][/et_pb_post_title]' );

		$this->assertCount( 1, $this->find( $defaults, 'core/post-featured-image' ) );
		$this->assertCount( 1, $this->find( $defaults, 'core/post-author-name' ) );
		$this->assertCount( 1, $this->find( $defaults, 'core/post-comments-link' ) );
		$this->assertCount( 0, $this->find( $off, 'core/post-featured-image' ) );
		$this->assertCount( 0, $this->find( $off, 'core/post-author-name' ) );
		$this->assertCount( 0, $this->find( $off, 'core/post-comments-link' ) );
	}

	/**
	 * A slide inherits the slider's custom button styles, and its text is
	 * centered without a setting.
	 *
	 * @return void
	 */
	public function test_slider_inherits_button_styles_and_centers_text(): void {
		$specs = $this->specs( '[et_pb_slider custom_button="on" button_bg_color="#123456"][et_pb_slide heading="Lorem ipsum" button_text="Lorem" button_link="https://example.com"]Lorem[/et_pb_slide][/et_pb_slider]' );

		$button  = $this->find( $specs, 'core/button' );
		$heading = $this->find( $specs, 'core/heading' );
		$buttons = $this->find( $specs, 'core/buttons' );

		$this->assertCount( 1, $button );
		$this->assertSame( '#123456', $button[0]['attributes']['style']['color']['background'] );
		$this->assertSame( 'center', $heading[0]['attributes']['style']['typography']['textAlign'] );
		$this->assertSame( 'center', $buttons[0]['attributes']['layout']['justifyContent'] );
	}

	/**
	 * A call to action without a background color gets the theme's accent
	 * color, and Divi's default accent color without the setting.
	 *
	 * @return void
	 */
	public function test_cta_uses_accent_color(): void {
		$content = '[et_pb_cta title="Lorem ipsum"]Lorem[/et_pb_cta]';

		$default = $this->specs( $content );
		$this->assertSame( $this->color( '#7ebec5' ), $default[0]['attributes']['style']['color']['background'] );

		update_option( 'et_divi', [ 'accent_color' => '#445566' ] );

		$custom = $this->specs( $content );
		$this->assertSame( '#445566', $custom[0]['attributes']['style']['color']['background'] );

		update_option( 'et_divi', 'not an array' );

		$corrupt = $this->specs( $content );
		$this->assertSame( $this->color( '#7ebec5' ), $corrupt[0]['attributes']['style']['color']['background'] );

		$own = $this->specs( '[et_pb_cta title="Lorem ipsum" background_color="#778899"]Lorem[/et_pb_cta]' );
		$this->assertSame( '#778899', $own[0]['attributes']['style']['color']['background'] );

		$off = $this->specs( '[et_pb_cta title="Lorem ipsum" use_background_color="off"]Lorem[/et_pb_cta]' );
		$this->assertArrayNotHasKey( 'background', $off[0]['attributes']['style']['color'] ?? [] );
	}

	/**
	 * A checkbox field without options becomes one option, labelled by the
	 * field title.
	 *
	 * @return void
	 */
	public function test_contact_form_checkbox_without_options(): void {
		$target = new class() implements FormTarget {
			/**
			 * Form the rule built.
			 *
			 * @var FormData|null
			 */
			public ?FormData $form = null;

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
				$this->form = $form;
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

		$specs = $this->specs( '[et_pb_contact_form title="Lorem" title_font_size="19"][et_pb_contact_field field_id="agree" field_title="Lorem ipsum" field_type="checkbox" /][/et_pb_contact_form]' );

		remove_all_filters( 's3s_divi_migration_targets' );

		$this->assertInstanceOf( FormData::class, $target->form );
		$this->assertSame( 'checkbox', $target->form->fields[0]['type'] );
		$this->assertSame( [ 'Lorem ipsum' ], $target->form->fields[0]['options'] );

		// The title keeps its own settings and Divi's space below it.
		$title = $this->find( $specs, 'core/heading' )[0]['attributes']['style'];

		$this->assertSame( Converter::create()->presets->font_size( '19px' ), $title['typography']['fontSize'] );
		$this->assertSame( '16px', $title['spacing']['padding']['bottom'] );
	}

	/**
	 * The lightbox wins over the link, and the image block gets no height.
	 *
	 * @return void
	 */
	public function test_image_lightbox_wins_and_height_is_skipped(): void {
		$specs = $this->specs( '[et_pb_image src="https://example.com/lorem.jpg" url="https://example.com/ipsum" show_in_lightbox="on" min_height="300px"][/et_pb_image]' );

		$images = $this->find( $specs, 'core/image' );

		$this->assertSame( [ 'enabled' => true ], $images[0]['attributes']['lightbox'] );
		$this->assertArrayNotHasKey( 'href', $images[0]['attributes'] );
		$this->assertArrayNotHasKey( 'dimensions', $images[0]['attributes']['style'] ?? [] );

		$linked = $this->find( $this->specs( '[et_pb_image src="https://example.com/lorem.jpg" url="https://example.com/ipsum"][/et_pb_image]' ), 'core/image' );

		$this->assertSame( 'https://example.com/ipsum', $linked[0]['attributes']['href'] );
	}

	/**
	 * A button link keeps relative URLs and drops unsafe protocols.
	 *
	 * @return void
	 */
	public function test_cta_button_url_is_escaped(): void {
		foreach ( [ '/contact', '#top', '?page=1', 'contact/', 'lorem/ipsum.html?page=1#top', 'https://example.com/lorem' ] as $url ) {
			$buttons = $this->find( $this->specs( '[et_pb_cta button_text="Lorem" button_url="' . $url . '"][/et_pb_cta]' ), 'core/button' );

			$this->assertSame( $url, $buttons[0]['attributes']['url'] );
		}

		$unsafe = $this->find( $this->specs( '[et_pb_cta button_text="Lorem" button_url="javascript:alert(1)"][/et_pb_cta]' ), 'core/button' );

		$this->assertArrayNotHasKey( 'url', $unsafe[0]['attributes'] );
	}

	/**
	 * A relative path without a leading slash stays relative in button, image
	 * and blurb links, as Divi writes it.
	 *
	 * @return void
	 */
	public function test_relative_path_link_stays_relative(): void {
		$buttons = $this->find( $this->specs( '[et_pb_button button_text="Lorem" button_url="lorem/ipsum/"][/et_pb_button]' ), 'core/button' );
		$images  = $this->find( $this->specs( '[et_pb_image src="https://example.com/lorem.jpg" url="lorem/ipsum/"][/et_pb_image]' ), 'core/image' );
		$blurb   = $this->specs( '[et_pb_blurb title="Lorem ipsum" url="lorem/ipsum/" image="https://example.com/lorem.jpg"]Lorem[/et_pb_blurb]' );

		$this->assertSame( 'lorem/ipsum/', $buttons[0]['attributes']['url'] );
		$this->assertSame( 'lorem/ipsum/', $images[0]['attributes']['href'] );
		$this->assertSame( 'lorem/ipsum/', $this->find( $blurb, 'core/image' )[0]['attributes']['href'] );
		$this->assertStringContainsString( '<a href="lorem/ipsum/">', $this->find( $blurb, 'core/heading' )[0]['attributes']['content'] );
	}

	/**
	 * A blurb that opens its link in a new tab sets the target on the image
	 * link and on the heading link.
	 *
	 * @return void
	 */
	public function test_blurb_new_window_link(): void {
		$specs = $this->specs( '[et_pb_blurb title="Lorem ipsum" url="https://example.com/ipsum" url_new_window="on" image="https://example.com/lorem.jpg"]Lorem[/et_pb_blurb]' );

		$images   = $this->find( $specs, 'core/image' );
		$headings = $this->find( $specs, 'core/heading' );

		$this->assertSame( '_blank', $images[0]['attributes']['linkTarget'] );
		$this->assertSame( 'noopener', $images[0]['attributes']['rel'] );
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $headings[0]['attributes']['content'] );
	}

	/**
	 * A section saved before Divi 3.0.74 without "Keep padding on mobile"
	 * gets 50px top and bottom padding on tablets and phones. Newer content,
	 * or a saved responsive setting that is off, keeps its own padding.
	 *
	 * @return void
	 */
	public function test_section_mobile_padding_migration(): void {
		$row = '[et_pb_row][et_pb_column type="4_4"]Lorem[/et_pb_column][/et_pb_row]';

		$old = $this->specs( '[et_pb_section padding_mobile="off"]' . $row . '[/et_pb_section]' )[0]['attributes']['style'];
		$new = $this->specs( '[et_pb_section padding_mobile="off" _builder_version="4.9.11"]' . $row . '[/et_pb_section]' )[0]['attributes']['style'] ?? [];
		$off = $this->specs( '[et_pb_section padding_mobile="off" custom_padding_last_edited="off|desktop"]' . $row . '[/et_pb_section]' )[0]['attributes']['style'] ?? [];

		$this->assertSame(
			[
				'top'    => '50px',
				'right'  => '0px',
				'bottom' => '50px',
				'left'   => '0px',
			],
			$old['@tablet']['spacing']['padding']
		);
		$this->assertArrayNotHasKey( '@tablet', $new );
		$this->assertArrayNotHasKey( '@tablet', $off );
	}

	/**
	 * Full-width posts are 60px apart, and 42px on tablets and phones. Post
	 * titles lose their bottom padding on phones.
	 *
	 * @return void
	 */
	public function test_blog_fullwidth_gap(): void {
		$specs    = $this->specs( '[et_pb_blog][/et_pb_blog]' );
		$template = $this->find( $specs, 'core/post-template' )[0]['attributes']['style'];
		$title    = $this->find( $specs, 'core/post-title' )[0]['attributes']['style'];

		$this->assertSame( '60px', $template['spacing']['blockGap'] );
		$this->assertSame( '42px', $template['@tablet']['spacing']['blockGap'] );
		$this->assertSame( '42px', $template['@mobile']['spacing']['blockGap'] );
		$this->assertSame( '0px', $title['@mobile']['spacing']['padding']['bottom'] );
		$this->assertArrayNotHasKey( 'padding', $title['spacing'] ?? [] );
	}

	/**
	 * A grid of posts spaces its rows from the row width, as Divi's card
	 * margins do, and its columns by the gutter.
	 *
	 * @return void
	 */
	public function test_blog_grid_gap(): void {
		$template = $this->find( $this->specs( '[et_pb_blog fullwidth="off"][/et_pb_blog]' ), 'core/post-template' )[0];
		$style    = $template['attributes']['style'];

		$this->assertSame( '59.4px', $style['spacing']['blockGap']['top'] );
		$this->assertSame( '5.5%', $style['spacing']['blockGap']['left'] );
		$this->assertSame( '9.2vw', $style['@mobile']['spacing']['blockGap']['top'] );
		$this->assertSame( '30px', $style['spacing']['margin']['bottom'] );
	}

	/**
	 * A testimonial's quote sits in a text group, before the author, and
	 * gets no empty paragraph style. The author has Divi's space above it.
	 *
	 * @return void
	 */
	public function test_testimonial_quote_text_group(): void {
		$group = $this->specs( '[et_pb_testimonial author="Lorem"]<p>Lorem</p><p>Ipsum</p>[/et_pb_testimonial]' )[0];
		$quote = $group['innerBlocks'][0];

		$this->assertSame( 'core/group', $quote['name'] );
		$this->assertSame( 'is-style-text', $quote['attributes']['className'] );
		$this->assertEquals( (object) [], $quote['innerBlocks'][0]['apply'] );
		$this->assertSame( 'core/paragraph', $group['innerBlocks'][1]['name'] );
		$this->assertSame( '32px', $group['innerBlocks'][1]['attributes']['style']['spacing']['margin']['top'] );

		$alone = $this->specs( '[et_pb_testimonial author="Lorem"][/et_pb_testimonial]' )[0];

		$this->assertSame( '12px', $alone['innerBlocks'][0]['attributes']['style']['spacing']['margin']['top'] );
	}

	/**
	 * Columns keep the column gap and Divi's 30px space between stacked
	 * columns, with or without a row width.
	 *
	 * @return void
	 */
	public function test_columns_stacked_gap(): void {
		$plain = $this->find( $this->specs( '[et_pb_section][et_pb_row][et_pb_column type="1_2"]Lorem[/et_pb_column][et_pb_column type="1_2"]Ipsum[/et_pb_column][/et_pb_row][/et_pb_section]' ), 'core/columns' )[0];
		$sized = $this->find( $this->specs( '[et_pb_section][et_pb_row max_width="800px"][et_pb_column type="1_2"]Lorem[/et_pb_column][et_pb_column type="1_2"]Ipsum[/et_pb_column][/et_pb_row][/et_pb_section]' ), 'core/columns' )[0];

		foreach ( [ $plain, $sized ] as $columns ) {
			$this->assertSame( '30px', $columns['attributes']['style']['spacing']['blockGap']['top'] );
			$this->assertArrayHasKey( 'left', $columns['attributes']['style']['spacing']['blockGap'] );
		}
	}

	/**
	 * A custom row gutter of 0 counts as 1, as in Divi, and a row without a
	 * custom gutter uses the site's gutter.
	 *
	 * @return void
	 */
	public function test_row_gutter_zero_counts_as_one(): void {
		$columns = '[et_pb_column type="1_2"]Lorem[/et_pb_column][et_pb_column type="1_2"]Ipsum[/et_pb_column]';

		$zero    = $this->find( $this->specs( '[et_pb_section][et_pb_row use_custom_gutter="on" gutter_width="0"]' . $columns . '[/et_pb_row][/et_pb_section]' ), 'core/columns' )[0];
		$default = $this->find( $this->specs( '[et_pb_section][et_pb_row use_custom_gutter="off" gutter_width="1"]' . $columns . '[/et_pb_row][/et_pb_section]' ), 'core/columns' )[0];

		$this->assertSame( '0%', $zero['attributes']['style']['spacing']['blockGap']['left'] );
		$this->assertSame( '50%', $zero['innerBlocks'][0]['attributes']['width'] );
		$this->assertSame( '5.5%', $default['attributes']['style']['spacing']['blockGap']['left'] );
	}

	/**
	 * A specialty section gives its columns and its block gap the same
	 * gutter, and reads the gutter width only with the custom gutter on.
	 *
	 * @return void
	 */
	public function test_specialty_section_gutter(): void {
		$columns = '[et_pb_column type="1_2"]Lorem[/et_pb_column][et_pb_column type="1_2"]Ipsum[/et_pb_column]';

		$custom = $this->find( $this->specs( '[et_pb_section specialty="on" use_custom_gutter="on" gutter_width="4"]' . $columns . '[/et_pb_section]' ), 'core/columns' )[0];
		$off    = $this->find( $this->specs( '[et_pb_section specialty="on" use_custom_gutter="off" gutter_width="1"]' . $columns . '[/et_pb_section]' ), 'core/columns' )[0];
		$zero   = $this->find( $this->specs( '[et_pb_section specialty="on" use_custom_gutter="on" gutter_width="0"]' . $columns . '[/et_pb_section]' ), 'core/columns' )[0];

		$this->assertSame( '8%', $custom['attributes']['style']['spacing']['blockGap']['left'] );
		$this->assertSame( '46%', $custom['innerBlocks'][0]['attributes']['width'] );
		$this->assertSame( '5.5%', $off['attributes']['style']['spacing']['blockGap']['left'] );
		$this->assertSame( '47.25%', $off['innerBlocks'][0]['attributes']['width'] );
		$this->assertSame( '0%', $zero['attributes']['style']['spacing']['blockGap']['left'] );
	}

	/**
	 * A module without a rule keeps its non-empty attributes as JSON on the
	 * hidden marker.
	 *
	 * @return void
	 */
	public function test_fallback_keeps_module_attributes(): void {
		$specs = $this->specs( '[et_pb_team_member name="Lorem" position="" facebook_url="https://example.com/ipsum"][/et_pb_team_member]' );

		$this->assertSame( 'core/html', $specs[0]['name'] );

		$content = $specs[0]['attributes']['content'];

		$this->assertStringContainsString( 'data-divi-module="et_pb_team_member"', $content );
		$this->assertSame( 1, preg_match( '/data-divi-attrs="([^"]*)"/', $content, $matches ) );

		$attrs = json_decode( html_entity_decode( $matches[1] ), true );

		$this->assertSame( 'Lorem', $attrs['name'] );
		$this->assertSame( 'https://example.com/ipsum', $attrs['facebook_url'] );
		$this->assertArrayNotHasKey( 'position', $attrs );
	}

	/**
	 * A module ID keeps every character but whitespace.
	 *
	 * @return void
	 */
	public function test_module_id_keeps_valid_characters(): void {
		$specs = $this->specs( '[et_pb_text module_id="lorem.ipsum:dolor%20 sit"]Lorem[/et_pb_text]' );

		$this->assertSame( 'lorem.ipsum:dolor%20sit', $specs[0]['attributes']['anchor'] );
	}

	/**
	 * List text alignment has no block attribute, so lists get none and the
	 * report notes it.
	 *
	 * @return void
	 */
	public function test_text_alignment_skips_lists(): void {
		$converter = Converter::create();
		$specs     = $converter->convert_content( '[et_pb_text text_orientation="center"]<p>Lorem</p><ul><li>Ipsum</li></ul>[/et_pb_text]' );
		$blocks    = parse_blocks( $converter->serializer()->serialize( $specs ) );
		$list      = $this->find_parsed( $blocks, 'core/list' );
		$paragraph = $this->find_parsed( $blocks, 'core/paragraph' );

		$this->assertNotSame( [], $paragraph );
		$this->assertSame( 'center', $paragraph[0]['attrs']['style']['typography']['textAlign'] ?? 'none' );
		$this->assertNotSame( [], $list );
		$this->assertArrayNotHasKey( 'style', $list[0]['attrs'] );
		$this->assertArrayHasKey( 'not converted: list text alignment', $converter->report()['et_pb_text'] );
	}

	/**
	 * Returns the parsed blocks with a name, at any depth.
	 *
	 * @param array[] $blocks Blocks that `parse_blocks()` returns.
	 * @param string  $name   Block name.
	 * @return array[]
	 */
	private function find_parsed( array $blocks, string $name ): array {
		$found = [];

		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === $name ) {
				$found[] = $block;
			}

			array_push( $found, ...$this->find_parsed( $block['innerBlocks'], $name ) );
		}

		return $found;
	}

	/**
	 * An accordion without an accordion target stays as a marked fallback,
	 * and the report notes it.
	 *
	 * @return void
	 */
	public function test_accordion_without_target_falls_back(): void {
		add_filter(
			's3s_divi_migration_targets',
			function ( array $targets ): array {
				$targets['accordion'] = [];
				return $targets;
			}
		);

		$converter = Converter::create();
		$specs     = $converter->convert_content( '[et_pb_accordion][et_pb_accordion_item title="Lorem"]Ipsum[/et_pb_accordion_item][/et_pb_accordion]' );

		remove_all_filters( 's3s_divi_migration_targets' );

		$this->assertNotSame( [], $specs );
		$this->assertStringContainsString( 'data-divi-module="et_pb_accordion"', $specs[0]['attributes']['content'] );
		$this->assertArrayHasKey( 'kept: no accordion plugin', $converter->report()['et_pb_accordion'] );
	}

	/**
	 * A slider without a carousel target stays as a marked fallback, and the
	 * report notes it.
	 *
	 * @return void
	 */
	public function test_slider_without_target_falls_back(): void {
		add_filter(
			's3s_divi_migration_targets',
			function ( array $targets ): array {
				$targets['carousel'] = [];
				return $targets;
			}
		);

		$converter = Converter::create();
		$specs     = $converter->convert_content( '[et_pb_slider][et_pb_slide heading="Lorem"]Ipsum[/et_pb_slide][/et_pb_slider]' );

		remove_all_filters( 's3s_divi_migration_targets' );

		$this->assertNotSame( [], $specs );
		$this->assertStringContainsString( 'data-divi-module="et_pb_slider"', $specs[0]['attributes']['content'] );
		$this->assertArrayHasKey( 'kept: no carousel plugin', $converter->report()['et_pb_slider'] );
	}

	/**
	 * A contact form keeps one key in every locale.
	 *
	 * @return void
	 */
	public function test_contact_form_key_ignores_locale(): void {
		$key = function (): string {
			$form = new \S3S\WP\DiviMigration\Rules\ContactForm();
			$call = new \ReflectionMethod( $form, 'form' );

			return $call->invoke( $form, new \S3S\WP\DiviMigration\Parser\Node( 'et_pb_contact_form' ) )->key;
		};

		$english = $key();

		add_filter(
			'gettext',
			fn( string $text, string $original, string $domain ) => 'et_builder' === $domain ? 'Lorem ' . $original : $text,
			10,
			3
		);

		$this->assertSame( $english, $key() );
	}

	/**
	 * A blurb image on top keeps Divi's 30px space above the text; an image on
	 * the left gets none.
	 *
	 * @return void
	 */
	public function test_blurb_image_gap(): void {
		$top  = $this->find( $this->specs( '[et_pb_blurb title="Lorem" image="https://example.com/lorem.jpg"]Lorem[/et_pb_blurb]' ), 'core/image' )[0];
		$left = $this->find( $this->specs( '[et_pb_blurb title="Lorem" icon_placement="left" image="https://example.com/lorem.jpg"]Lorem[/et_pb_blurb]' ), 'core/image' )[0];

		$this->assertSame( '30px', $top['attributes']['style']['spacing']['margin']['bottom'] );
		$this->assertArrayNotHasKey( 'style', $left['attributes'] );
	}

	/**
	 * A single-column row without column styles keeps the column's class, ID,
	 * label and visibility on its group.
	 *
	 * @return void
	 */
	public function test_single_column_row_keeps_column_attributes(): void {
		$specs = $this->specs( '[et_pb_section][et_pb_row module_class="row-lorem"][et_pb_column type="4_4" module_class="column-ipsum" module_id="column-id" admin_label="Lorem column" disabled_on="on|off|off"]Lorem[/et_pb_column][/et_pb_row][/et_pb_section]' );

		$group = $specs[0]['innerBlocks'][0];

		$this->assertSame( 'core/group', $group['name'] );
		$this->assertStringContainsString( 'row-lorem', $group['attributes']['className'] );
		$this->assertStringContainsString( 'column-ipsum', $group['attributes']['className'] );
		$this->assertSame( 'column-id', $group['attributes']['anchor'] );
		$this->assertSame( 'Lorem column', $group['attributes']['metadata']['name'] );
		$this->assertSame( [ 'mobile' => false ], $group['attributes']['metadata']['blockVisibility']['viewport'] );
	}

	/**
	 * Each bar counter keeps its own track color, and the module's color
	 * applies to the others.
	 *
	 * @return void
	 */
	public function test_counters_use_own_track_color(): void {
		$specs = $this->specs( '[et_pb_counters background_color="#abcdef"][et_pb_counter percent="40" background_color="#123456"]Lorem[/et_pb_counter][et_pb_counter percent="60"]Ipsum[/et_pb_counter][/et_pb_counters]' );

		$bars = $specs[0]['innerBlocks'];

		$this->assertSame( '#123456', $bars[0]['innerBlocks'][1]['attributes']['style']['color']['background'] );
		$this->assertSame( '#abcdef', $bars[1]['innerBlocks'][1]['attributes']['style']['color']['background'] );
	}

	/**
	 * A counter's title sits above its bar, and the percentage sits inside
	 * the filled part. The last bar has no space below.
	 *
	 * @return void
	 */
	public function test_counter_title_above_bar(): void {
		$bars = $this->specs( '[et_pb_counters][et_pb_counter percent="10"]Lorem[/et_pb_counter][et_pb_counter percent="90"]Ipsum[/et_pb_counter][/et_pb_counters]' )[0]['innerBlocks'];

		[ $title, $track ] = $bars[0]['innerBlocks'];

		$fill = $track['innerBlocks'][0];

		$this->assertSame( 'Lorem', $title['attributes']['content'] );
		$this->assertSame( '12px', $title['attributes']['style']['typography']['fontSize'] );
		$this->assertSame( 'width: 10%; overflow: hidden; white-space: nowrap;', $fill['attributes']['style']['css'] );
		$this->assertSame( '10%', $fill['innerBlocks'][0]['attributes']['content'] );
		$this->assertSame( '10px', $track['attributes']['style']['spacing']['margin']['bottom'] );
		$this->assertArrayNotHasKey( 'spacing', $bars[1]['innerBlocks'][1]['attributes']['style'] );
	}

	/**
	 * A number counter title uses its heading level, `h3` without a setting.
	 *
	 * @return void
	 */
	public function test_number_counter_title_level(): void {
		$default = $this->find( $this->specs( '[et_pb_number_counter title="Lorem" number="10"][/et_pb_number_counter]' ), 'core/heading' );
		$custom  = $this->find( $this->specs( '[et_pb_number_counter title="Lorem" number="10" title_level="h2"][/et_pb_number_counter]' ), 'core/heading' );

		$this->assertSame( 3, $default[0]['attributes']['level'] );
		$this->assertSame( 2, $custom[0]['attributes']['level'] );
	}

	/**
	 * Colors match the palette after normalizing, and a transparent tablet
	 * color stays while a transparent desktop color is dropped.
	 *
	 * @return void
	 */
	public function test_presets_normalize_colors_and_keep_transparent_overrides(): void {
		$presets = ( new Presets() )->with(
			[
				'#ABC'               => 'lorem',
				'rgba(1, 2, 3, 0.5)' => 'ipsum',
			],
			[]
		);

		$this->assertSame( 'var:preset|color|lorem', $presets->color( '#aabbcc' ) );
		$this->assertSame( 'var:preset|color|lorem', $presets->color( ' #AABBCC ' ) );
		$this->assertSame( 'var:preset|color|ipsum', $presets->color( 'rgba(1,2,3,0.5)' ) );

		$colors = $presets->responsive(
			[
				'desktop' => 'rgba(0, 0, 0, 0)',
				'tablet'  => 'transparent',
				'phone'   => '#ff0000',
			]
		);

		$this->assertSame( '', $colors['desktop'] );
		$this->assertSame( '', $colors['tablet'] );
		$this->assertSame( '#ff0000', $colors['phone'] );

		$overrides = $presets->responsive(
			[
				'desktop' => '#ff0000',
				'tablet'  => 'transparent',
				'phone'   => '',
			]
		);

		$this->assertSame( 'transparent', $overrides['tablet'] );
	}

	/**
	 * A two-part padding is the top and bottom padding of a section, the
	 * top, right and bottom padding of a row, and the top and right padding
	 * of other modules.
	 *
	 * @return void
	 */
	public function test_two_part_padding(): void {
		$specs = $this->specs( '[et_pb_section custom_padding="0|50px"][et_pb_row custom_padding="10px|20px"][et_pb_column type="4_4"][et_pb_blog custom_padding="10px|20px"][/et_pb_blog][/et_pb_column][/et_pb_row][/et_pb_section]' );

		$section = $specs[0];
		$row     = $section['innerBlocks'][0];
		$query   = $this->find( $specs, 'core/query' );

		$this->assertSame(
			[
				'top'    => '0px',
				'bottom' => '50px',
			],
			$section['attributes']['style']['spacing']['padding']
		);
		$this->assertSame(
			[
				'top'    => '10px',
				'right'  => '20px',
				'bottom' => '20px',
			],
			$row['attributes']['style']['spacing']['padding']
		);
		$this->assertSame(
			[
				'top'   => '10px',
				'right' => '20px',
			],
			$query[0]['attributes']['style']['spacing']['padding']
		);
	}

	/**
	 * A section with a width sits in a full-width wrapper that holds the
	 * width and the alignment. A section without one is full width.
	 *
	 * @return void
	 */
	public function test_section_width(): void {
		$sized = $this->specs( '[et_pb_section width="80%" module_alignment="center" background_color="#123456"][et_pb_row][et_pb_column type="4_4"]Lorem[/et_pb_column][/et_pb_row][/et_pb_section]' );
		$plain = $this->specs( '[et_pb_section][et_pb_row][et_pb_column type="4_4"]Lorem[/et_pb_column][/et_pb_row][/et_pb_section]' );

		$wrapper = $sized[0];
		$section = $wrapper['innerBlocks'][0];

		$this->assertSame( 'full', $wrapper['attributes']['align'] );
		$this->assertSame(
			[
				'type'           => 'constrained',
				'contentSize'    => '80%',
				'justifyContent' => 'center',
			],
			$wrapper['attributes']['layout']
		);
		$this->assertArrayNotHasKey( 'align', $section['attributes'] );
		$this->assertSame( $this->color( '#123456' ), $section['attributes']['style']['color']['background'] );
		$this->assertSame( 'full', $plain[0]['attributes']['align'] );
		$this->assertSame( 'core/group', $plain[0]['innerBlocks'][0]['name'] );
		$this->assertArrayNotHasKey( 'contentSize', $plain[0]['attributes']['layout'] );
	}

	/**
	 * A bare number in a font size, a letter spacing or a large line height
	 * is a pixel value. A small line height stays a bare number.
	 *
	 * @return void
	 */
	public function test_bare_numbers_in_typography(): void {
		$small = $this->find( $this->specs( '[et_pb_blog header_font_size="19" header_letter_spacing="1" header_line_height="1.5" header_font_size_last_edited="on|phone" header_font_size_phone="17"][/et_pb_blog]' ), 'core/post-title' );
		$large = $this->find( $this->specs( '[et_pb_blog header_line_height="30"][/et_pb_blog]' ), 'core/post-title' );

		$typography = $small[0]['attributes']['style']['typography'];

		$this->assertSame( '19px', $typography['fontSize'] );
		$this->assertSame( '1px', $typography['letterSpacing'] );
		$this->assertSame( '1.5', $typography['lineHeight'] );
		$this->assertSame( '17px', $small[0]['attributes']['style']['@mobile']['typography']['fontSize'] );
		$this->assertSame( '30px', $large[0]['attributes']['style']['typography']['lineHeight'] );
	}

	/**
	 * The "read more" link has its own line in a full-width blog list and
	 * follows the excerpt in a grid.
	 *
	 * @return void
	 */
	public function test_blog_read_more_line(): void {
		$list = $this->find( $this->specs( '[et_pb_blog show_more="on"][/et_pb_blog]' ), 'core/post-excerpt' );
		$grid = $this->find( $this->specs( '[et_pb_blog fullwidth="off" show_more="on"][/et_pb_blog]' ), 'core/post-excerpt' );

		$this->assertTrue( $list[0]['attributes']['showMoreOnNewLine'] );
		$this->assertFalse( $grid[0]['attributes']['showMoreOnNewLine'] );
	}

	/**
	 * A slide holds Divi's slide and text box padding. The custom padding of
	 * the slider and of the slide replaces the text box padding per side.
	 *
	 * @return void
	 */
	public function test_slide_padding(): void {
		$default = $this->slides( '[et_pb_slider][et_pb_slide heading="Lorem"]Lorem[/et_pb_slide][/et_pb_slider]' );
		$custom  = $this->slides( '[et_pb_slider custom_padding="10px|||"][et_pb_slide heading="Lorem" custom_padding="|20px||20px|false|false" custom_padding_phone="70px|18px||18px|false|false" custom_padding_last_edited="on|desktop"]Lorem[/et_pb_slide][et_pb_slide heading="Ipsum" image="https://example.com/image.png"]Ipsum[/et_pb_slide][/et_pb_slider]' );

		$this->assertSame(
			[
				'top'    => '14.08%',
				'right'  => '13.04%',
				'bottom' => '14.08%',
				'left'   => '13.04%',
			],
			$default[0]['attributes']['style']['spacing']['padding']
		);

		$slide = $custom[0]['attributes']['style'];

		$this->assertSame(
			[
				'top'    => '10px',
				'right'  => 'calc(6% + 20px)',
				'bottom' => '14.08%',
				'left'   => 'calc(6% + 20px)',
			],
			$slide['spacing']['padding']
		);
		$this->assertSame(
			[
				'top'   => '70px',
				'right' => 'calc(6% + 18px)',
				'left'  => 'calc(6% + 18px)',
			],
			$slide['@mobile']['spacing']['padding']
		);
		$this->assertArrayNotHasKey( '@tablet', $slide );
		$this->assertSame( '6%', $custom[1]['attributes']['style']['spacing']['padding']['left'] );
	}

	/**
	 * With a content width other than Divi's default, the slide padding
	 * follows Divi's text box, which is capped at that width and centered.
	 *
	 * @return void
	 */
	public function test_slide_padding_with_content_width(): void {
		update_option( 'et_divi', [ 'content_width' => '1090' ] );

		$slides = $this->slides( '[et_pb_slider][et_pb_slide heading="Lorem"]Lorem[/et_pb_slide][et_pb_slide heading="Ipsum" custom_padding="|20px||0px"]Ipsum[/et_pb_slide][/et_pb_slider]' );

		$this->assertSame(
			[
				'top'    => 'calc(min(88%, 1090px) * 0.16)',
				'right'  => 'calc(50% - min(88%, 1090px) * 0.42)',
				'bottom' => 'calc(min(88%, 1090px) * 0.16)',
				'left'   => 'calc(50% - min(88%, 1090px) * 0.42)',
			],
			$slides[0]['attributes']['style']['spacing']['padding']
		);
		$this->assertSame( 'calc(50% - min(88%, 1090px) / 2 + 20px)', $slides[1]['attributes']['style']['spacing']['padding']['right'] );
		$this->assertSame( 'calc(50% - min(88%, 1090px) / 2)', $slides[1]['attributes']['style']['spacing']['padding']['left'] );
	}

	/**
	 * With `padding_mobile="off"`, the bottom padding of a two-part row
	 * padding applies on desktop only, and tablets and phones get Divi's row
	 * height. A four-part padding applies at every width.
	 *
	 * @return void
	 */
	public function test_row_padding_mobile_off(): void {
		update_option( 'et_divi', [ 'tablet_row_height' => '12' ] );

		$two_part  = $this->specs( '[et_pb_section][et_pb_row custom_padding="10px|40px" padding_mobile="off"][et_pb_column type="4_4"][et_pb_text]Lorem[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]' );
		$four_part = $this->specs( '[et_pb_section][et_pb_row custom_padding="10px|20px|40px|20px" padding_mobile="off"][et_pb_column type="4_4"][et_pb_text]Lorem[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]' );

		$style = $two_part[0]['innerBlocks'][0]['attributes']['style'];

		$this->assertSame(
			[
				'top'    => '10px',
				'right'  => '40px',
				'bottom' => '40px',
			],
			$style['spacing']['padding']
		);
		$this->assertSame( [ 'bottom' => '12px' ], $style['@tablet']['spacing']['padding'] );
		$this->assertSame( [ 'bottom' => '12px' ], $style['@mobile']['spacing']['padding'] );
		$this->assertArrayNotHasKey( '@tablet', $four_part[0]['innerBlocks'][0]['attributes']['style'] );
	}

	/**
	 * An empty column is hidden on tablets and phones, as in Divi.
	 *
	 * @return void
	 */
	public function test_empty_column_hidden_on_small_screens(): void {
		$columns = $this->find( $this->specs( '[et_pb_section][et_pb_row][et_pb_column type="1_2"][et_pb_text]Lorem[/et_pb_text][/et_pb_column][et_pb_column type="1_2"][/et_pb_column][/et_pb_row][/et_pb_section]' ), 'core/column' );

		$this->assertArrayNotHasKey( 'metadata', $columns[0]['attributes'] );
		$this->assertSame(
			[
				'mobile' => false,
				'tablet' => false,
			],
			$columns[1]['attributes']['metadata']['blockVisibility']['viewport']
		);
	}

	/**
	 * A blog's meta line and excerpt use the desktop body size at every
	 * viewport and the body line height in pixels, when the body size is
	 * not Divi's default.
	 *
	 * @return void
	 */
	public function test_blog_post_text_uses_body_size(): void {
		$default = $this->specs( '[et_pb_blog][/et_pb_blog]' );

		update_option(
			'et_divi',
			[
				'body_font_size'        => '15',
				'tablet_body_font_size' => '17',
			]
		);

		$custom  = $this->specs( '[et_pb_blog][/et_pb_blog]' );
		$presets = Converter::create()->presets;

		$this->assertSame( $presets->font_size( '14px' ), $this->blog_meta( $default )['typography']['fontSize'] );
		$this->assertSame( '6px', $this->blog_meta( $default )['spacing']['margin']['bottom'] );
		$this->assertArrayNotHasKey( 'style', array_filter( $this->find( $default, 'core/post-excerpt' )[0]['attributes'] ) );

		$excerpt = $this->find( $custom, 'core/post-excerpt' )[0]['attributes']['style'];

		foreach ( [ $this->blog_meta( $custom ), $excerpt ] as $style ) {
			$this->assertSame( $presets->font_size( '15px' ), $style['typography']['fontSize'] );
			$this->assertSame( '25.5px', $style['typography']['lineHeight'] );
			$this->assertSame( '28.9px', $style['@tablet']['typography']['lineHeight'] );
			$this->assertSame( '28.9px', $style['@mobile']['typography']['lineHeight'] );
			$this->assertArrayNotHasKey( 'fontSize', $style['@tablet']['typography'] );
		}
	}

	/**
	 * A slide's text sits in a text group with Divi's slide text size,
	 * weight, color and line height.
	 *
	 * @return void
	 */
	public function test_slide_text_defaults(): void {
		$content = '[et_pb_slider][et_pb_slide heading="Lorem" background_layout="light"]Lorem[/et_pb_slide][/et_pb_slider]';
		$default = $this->slide_text( $content );

		update_option(
			'et_divi',
			[
				'body_font_size'        => '15',
				'tablet_body_font_size' => '17',
			]
		);

		$custom  = $this->slide_text( $content );
		$presets = Converter::create()->presets;

		$this->assertSame( 'is-style-text', $default['attributes']['className'] );
		$this->assertSame( $presets->font_size( '14px' ), $default['attributes']['style']['typography']['fontSize'] );
		$this->assertArrayNotHasKey( '@tablet', $default['attributes']['style'] );
		$this->assertSame( '400', $default['attributes']['style']['typography']['fontWeight'] );
		$this->assertSame( $presets->color( '#333333' ), $default['attributes']['style']['color']['text'] );

		$style = $custom['attributes']['style'];

		$this->assertSame( $presets->font_size( '17px' ), $style['typography']['fontSize'] );
		$this->assertSame( $presets->font_size( '19px' ), $style['@tablet']['typography']['fontSize'] );
		$this->assertSame( '25.5px', $style['typography']['lineHeight'] );
		$this->assertSame( '28.9px', $style['@tablet']['typography']['lineHeight'] );

		// The slider's own body size applies at every viewport.
		$own = $this->slide_text( '[et_pb_slider body_font_size="14px"][et_pb_slide heading="Lorem"]Lorem[/et_pb_slide][/et_pb_slider]' )['attributes']['style'];

		$this->assertSame( $presets->font_size( '14px' ), $own['typography']['fontSize'] );
		$this->assertArrayNotHasKey( 'fontSize', $own['@tablet']['typography'] ?? [] );
		$this->assertArrayNotHasKey( 'fontSize', $own['@mobile']['typography'] ?? [] );
	}

	/**
	 * A text module with other content on tablets and phones gets one text
	 * group per content, each hidden where it does not apply. An empty value
	 * hides the content there.
	 *
	 * @return void
	 */
	public function test_responsive_content(): void {
		$text  = $this->specs( '[et_pb_text content_last_edited="on|tablet" content_tablet="<p>Ipsum</p>"]<p>Lorem</p>[/et_pb_text]' );
		$blurb = $this->specs( '[et_pb_blurb title="Lorem" content_last_edited="on|phone" content_tablet="" content_phone=""]Ipsum[/et_pb_blurb]' );
		$plain = $this->specs( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' );

		$variants = $text[0]['innerBlocks'];

		$this->assertCount( 2, $variants );
		$this->assertSame(
			[
				'tablet' => false,
				'mobile' => false,
			],
			$variants[0]['attributes']['metadata']['blockVisibility']['viewport']
		);
		$this->assertSame( [ 'desktop' => false ], $variants[1]['attributes']['metadata']['blockVisibility']['viewport'] );
		$this->assertStringContainsString( 'Ipsum', $variants[1]['innerBlocks'][0]['raw'] );

		$hidden = array_filter( $this->find( $blurb, 'core/group' ), fn( array $group ) => isset( $group['attributes']['metadata']['blockVisibility'] ) );

		$this->assertCount( 1, $hidden );
		$this->assertArrayHasKey( 'raw', $plain[0]['innerBlocks'][0] );
	}

	/**
	 * A text module with its own font size and no line height gets the body
	 * line height in pixels.
	 *
	 * @return void
	 */
	public function test_text_size_keeps_body_line_height(): void {
		update_option( 'et_divi', [ 'body_font_size' => '15' ] );

		$sized = $this->specs( '[et_pb_text text_font_size="25"]<p>Lorem</p>[/et_pb_text]' );
		$plain = $this->specs( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' );

		$this->assertSame( '25.5px', $sized[0]['attributes']['style']['typography']['lineHeight'] );
		$this->assertArrayNotHasKey( 'typography', $plain[0]['attributes']['style'] ?? [] );
	}

	/**
	 * A number counter title is sized like an h4, and the number has 20px
	 * below it.
	 *
	 * @return void
	 */
	public function test_number_counter_title_size(): void {
		update_option( 'et_divi', [ 'body_header_size' => '24' ] );

		$specs   = $this->specs( '[et_pb_number_counter title="Lorem" number="10"][/et_pb_number_counter]' );
		$number  = $this->find( $specs, 'core/paragraph' )[0];
		$heading = $this->find( $specs, 'core/heading' )[0];

		$this->assertSame( '20px', $number['attributes']['style']['spacing']['padding']['bottom'] );
		$this->assertSame( Converter::create()->presets->font_size( '14px' ), $heading['attributes']['style']['typography']['fontSize'] );
	}

	/**
	 * An accordion panel has no layout role class. Its blocks sit in a Group
	 * with the text class, which is a Group block style.
	 *
	 * @return void
	 */
	public function test_accordion_panel_text_group(): void {
		$specs  = $this->specs( '[et_pb_accordion][et_pb_accordion_item title="Lorem"]<p>Ipsum</p>[/et_pb_accordion_item][/et_pb_accordion]' );
		$panels = array_merge( $this->find( $specs, 'outstand/accordion-panel' ), $this->find( $specs, 'core/accordion-panel' ) );

		$this->assertCount( 1, $panels );
		$this->assertArrayNotHasKey( 'className', $panels[0]['attributes'] );
		$this->assertSame( 'core/group', $panels[0]['innerBlocks'][0]['name'] );
		$this->assertSame( 'is-style-text', $panels[0]['innerBlocks'][0]['attributes']['className'] );
	}

	/**
	 * A module gets the settings of its Global Preset, or of its type's
	 * default preset, where it sets none itself.
	 *
	 * @return void
	 */
	public function test_global_presets_fill_settings(): void {
		update_option(
			'et_divi',
			[
				'builder_global_presets' => (object) [
					'et_pb_text' => (object) [
						'default' => 'lorem',
						'presets' => (object) [
							'lorem' => (object) [ 'settings' => (object) [ 'text_text_color' => '#123456' ] ],
							'ipsum' => (object) [
								'settings' => (object) [
									'text_text_color' => '#654321',
									'text_font_size'  => '19px',
								],
							],
						],
					],
				],
			]
		);

		$default = $this->specs( '[et_pb_text]<p>Lorem</p>[/et_pb_text]' );
		$named   = $this->specs( '[et_pb_text _module_preset="ipsum" text_text_color="#abcdef"]<p>Lorem</p>[/et_pb_text]' );

		$this->assertSame( '#123456', $default[0]['attributes']['style']['color']['text'] );
		$this->assertSame( '#abcdef', $named[0]['attributes']['style']['color']['text'] );
		$this->assertSame( Converter::create()->presets->font_size( '19px' ), $named[0]['attributes']['style']['typography']['fontSize'] );
	}

	/**
	 * A blog with `all` in its categories is unfiltered, and IDs that are not
	 * categories are dropped, as in Divi.
	 *
	 * @return void
	 */
	public function test_blog_categories(): void {
		$category = self::factory()->category->create();

		$all      = $this->find( $this->specs( '[et_pb_blog include_categories="' . $category . ',all,current"][/et_pb_blog]' ), 'core/query' );
		$filtered = $this->find( $this->specs( '[et_pb_blog include_categories="' . $category . ',999999,current"][/et_pb_blog]' ), 'core/query' );

		$this->assertArrayNotHasKey( 'taxQuery', $all[0]['attributes']['query'] );
		$this->assertSame( [ 'category' => [ $category ] ], $filtered[0]['attributes']['query']['taxQuery'] );
	}

	/**
	 * Returns the style of a blog's meta line.
	 *
	 * @param array[] $specs Block specs.
	 * @return array
	 */
	private function blog_meta( array $specs ): array {
		$terms = $this->find( $specs, 'core/post-terms' );

		foreach ( $this->find( $specs, 'core/group' ) as $group ) {
			if ( in_array( $terms[0], $group['innerBlocks'], true ) ) {
				return $group['attributes']['style'];
			}
		}

		return [];
	}

	/**
	 * Returns the text group of a slider's first slide.
	 *
	 * @param string $content Divi content.
	 * @return array
	 */
	private function slide_text( string $content ): array {
		$groups = $this->find( $this->slides( $content )[0]['innerBlocks'], 'core/group' );

		return $groups[0] ?? [];
	}

	/**
	 * Converts slider content and returns its slides, at any depth.
	 *
	 * @param string $content Divi content.
	 * @return array[]
	 */
	private function slides( string $content ): array {
		$specs = $this->specs( $content );

		return array_merge( $this->find( $specs, 'outstand/slide' ), $this->find( $specs, 'core/cover' ) );
	}
}
