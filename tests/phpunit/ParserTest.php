<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Parser;

/**
 * Tests the Divi shortcode parser and the resolution of global modules.
 */
class ParserTest extends TestCase {

	/**
	 * A self-closing module has no children and leaves its siblings alone.
	 *
	 * @return void
	 */
	public function test_self_closing_module_does_not_take_siblings(): void {
		$root = ( new Parser() )->parse( '[et_pb_column type="4_4"][et_pb_text admin_label="Empty" /][et_pb_text]Lorem ipsum.[/et_pb_text][/et_pb_column]' );

		$column = $root->children[0];

		$this->assertSame( 'et_pb_column', $column->tag );
		$this->assertCount( 2, $column->children );
		$this->assertSame( [ 'admin_label' => 'Empty' ], $column->children[0]->attrs );
		$this->assertSame( [], $column->children[0]->children );
		$this->assertSame( '', $column->children[0]->content );
		$this->assertSame( 'Lorem ipsum.', $column->children[1]->content );
	}

	/**
	 * An unquoted attribute that ends in `/`, such as a URL, does not close
	 * the module. ` /]`, or `/]` after a quoted value or the tag name, does.
	 *
	 * @return void
	 */
	public function test_unquoted_value_ending_in_slash_does_not_close_module(): void {
		$root = ( new Parser() )->parse( '[et_pb_column type="4_4"][et_pb_button button_url=https://example.com/lorem/]Ipsum.[/et_pb_button][et_pb_divider /][et_pb_divider color="#123456"/][et_pb_divider/][et_pb_text]Dolor.[/et_pb_text][/et_pb_column]' );

		$children = $root->children[0]->children;

		$this->assertSame( [ 'et_pb_button', 'et_pb_divider', 'et_pb_divider', 'et_pb_divider', 'et_pb_text' ], array_map( fn( $node ) => $node->tag, $children ) );
		$this->assertSame( [ 'button_url' => 'https://example.com/lorem/' ], $children[0]->attrs );
		$this->assertSame( 'Ipsum.', $children[0]->content );
		$this->assertSame( [ 'color' => '#123456' ], $children[2]->attrs );
		$this->assertSame( 'Dolor.', $children[4]->content );
	}

	/**
	 * A closing tag closes the unclosed modules inside it, and a closing tag
	 * with no open module is ignored.
	 *
	 * @return void
	 */
	public function test_unclosed_modules_close_with_their_parent(): void {
		$root = ( new Parser() )->parse( '[et_pb_row][et_pb_column type="1_2"][et_pb_text]Lorem ipsum.[/et_pb_column][et_pb_column type="1_2"][et_pb_text]Dolor sit amet.[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_text]' );

		$this->assertCount( 1, $root->children );

		$columns = $root->children[0]->children;

		$this->assertCount( 2, $columns );
		$this->assertSame( 'Lorem ipsum.', $columns[0]->children[0]->content );
		$this->assertSame( 'Dolor sit amet.', $columns[1]->children[0]->content );
	}

	/**
	 * A global module inside a global module resolves to the Library item's
	 * synced content.
	 *
	 * @return void
	 */
	public function test_nested_global_module_resolves(): void {
		$inner = $this->insert_post( '', 0, 'et_pb_layout' );
		$outer = $this->insert_post( '', 0, 'et_pb_layout' );

		$this->set_content( $inner, sprintf( '[et_pb_text global_module="%d"]<p>Synced inner lorem.</p>[/et_pb_text]', $inner ) );
		$this->set_content( $outer, sprintf( '[et_pb_section global_module="%1$d"][et_pb_row][et_pb_column type="4_4"][et_pb_text global_module="%2$d"]<p>Stale library copy.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]', $outer, $inner ) );

		$markup = $this->markup( sprintf( '[et_pb_section global_module="%d"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Stale page copy.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]', $outer ) );

		$this->assertStringContainsString( 'Synced inner lorem.', $markup );
		$this->assertStringNotContainsString( 'Stale library copy.', $markup );
		$this->assertStringNotContainsString( 'Stale page copy.', $markup );
	}

	/**
	 * A Library item that holds a global module referencing itself converts
	 * once, without a loop.
	 *
	 * @return void
	 */
	public function test_self_referencing_global_module_does_not_loop(): void {
		$layout = $this->insert_post( '', 0, 'et_pb_layout' );

		$this->set_content( $layout, sprintf( '[et_pb_section global_module="%1$d"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<p>Loop lorem.</p>[/et_pb_text][et_pb_section global_module="%1$d"][/et_pb_section][/et_pb_column][/et_pb_row][/et_pb_section]', $layout ) );

		$markup = $this->markup( sprintf( '[et_pb_section global_module="%d"][/et_pb_section]', $layout ) );

		$this->assertSame( 1, substr_count( $markup, 'Loop lorem.' ) );
	}

	/**
	 * Curly and encoded quotes that delimit attribute values read as straight
	 * quotes, and a straight-quoted value stays as it is.
	 *
	 * @return void
	 */
	public function test_curly_quotes_delimit_attribute_values(): void {
		$content = '[et_pb_text text_font=”Open Sans||||||||” text_color=“#123456” a=&#8221;Lorem ipsum&#8243; b=″c″ d="straight" /]';
		$root    = ( new Parser() )->parse( $content );

		$this->assertSame(
			[
				'text_font'  => 'Open Sans||||||||',
				'text_color' => '#123456',
				'a'          => 'Lorem ipsum',
				'b'          => 'c',
				'd'          => 'straight',
			],
			$root->children[0]->attrs
		);
		$this->assertSame( [], $root->children[0]->children );
	}

	/**
	 * A curly quote inside a value stays in the value, and a self-closing tag
	 * with a curly closing quote has no children.
	 *
	 * @return void
	 */
	public function test_curly_quotes_inside_a_value_are_kept(): void {
		$root = ( new Parser() )->parse( '[et_pb_text a=”He said ”hi” ok” b=1][et_pb_divider color=”#123456”/][/et_pb_text]' );

		$text = $root->children[0];

		$this->assertSame( 'He said ”hi” ok', $text->attrs['a'] );
		$this->assertSame( '1', $text->attrs['b'] );
		$this->assertSame( 'et_pb_divider', $text->children[0]->tag );
		$this->assertSame( [ 'color' => '#123456' ], $text->children[0]->attrs );
	}

	/**
	 * A valueless attribute after a curly-quoted value does not stop the value
	 * from closing, and single-quoted values stay as they are.
	 *
	 * @return void
	 */
	public function test_curly_quotes_with_valueless_and_single_quoted_attributes(): void {
		$root = ( new Parser() )->parse( "[et_pb_text a=”x” disabled b=”y” c='d=”e”' f=”z” disabled]" );

		$this->assertSame(
			[
				'a' => 'x',
				'b' => 'y',
				'c' => 'd=”e”',
				'f' => 'z',
			],
			$root->children[0]->attrs
		);
	}

	/**
	 * A dynamic content value that cannot resolve becomes an empty string and
	 * a note in the report.
	 *
	 * @return void
	 */
	public function test_unresolved_dynamic_content_is_noted(): void {
		$value     = '@ET-DC@' . base64_encode( (string) wp_json_encode( [ 'content' => 'post_title' ] ) ) . '@'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$content   = '[et_pb_text text_font="' . $value . '"]Lorem[/et_pb_text]';
		$parser    = new Parser();
		$root      = $parser->parse( $content );
		$converter = Converter::create();

		$converter->convert_content( $content );

		$this->assertSame( '', $root->children[0]->attrs['text_font'] );
		$this->assertSame(
			[
				[
					'tag'  => 'et_pb_text',
					'type' => 'post_title',
				],
			],
			$parser->unresolved()
		);
		$this->assertArrayHasKey( 'unresolved dynamic content: post_title', $converter->report()['et_pb_text'] );
	}

	/**
	 * An unresolved dynamic content value in a global Library module is noted.
	 *
	 * @return void
	 */
	public function test_unresolved_dynamic_content_in_a_global_module_is_noted(): void {
		$layout = $this->insert_post( '', 0, 'et_pb_layout' );
		$value  = '@ET-DC@' . base64_encode( (string) wp_json_encode( [ 'content' => 'post_title' ] ) ) . '@'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->set_content( $layout, sprintf( '[et_pb_text global_module="%d" text_font="%s"]<p>Lorem.</p>[/et_pb_text]', $layout, $value ) );

		$converter = Converter::create();

		$converter->convert_content( sprintf( '[et_pb_section][et_pb_row][et_pb_column type="4_4"][et_pb_text global_module="%d"]<p>Stale.</p>[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]', $layout ) );

		$this->assertArrayHasKey( 'unresolved dynamic content: post_title', $converter->report()['et_pb_text'] );
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
	 * Writes a post's content straight to the database.
	 *
	 * @param int    $id      Post ID.
	 * @param string $content Post content.
	 * @return void
	 */
	private function set_content( int $id, string $content ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stores the content without the save filters.
		$wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $id ] );

		clean_post_cache( $id );
	}
}
