<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Parser;
use WP_UnitTestCase;

/**
 * Tests that text outside any module keeps its position.
 */
class RootTextTest extends WP_UnitTestCase {

	const CONTENT = 'Intro <p>lorem</p>[et_pb_section][et_pb_text]Alpha[/et_pb_text][/et_pb_section] Middle [et_pb_section][et_pb_text]Beta[/et_pb_text][/et_pb_section] Outro';

	/**
	 * The parser records root text by the index of the following child.
	 *
	 * @return void
	 */
	public function test_parser_records_root_segments(): void {
		$root = ( new Parser() )->parse( self::CONTENT );

		$this->assertSame(
			[
				0 => 'Intro <p>lorem</p>',
				1 => ' Middle ',
				2 => ' Outro',
			],
			$root->segments
		);
		$this->assertSame( [], $root->children[0]->segments );
	}

	/**
	 * Text between and after modules stays between and after them.
	 *
	 * @return void
	 */
	public function test_text_stays_in_position(): void {
		$specs = Converter::create()->convert_content( self::CONTENT );
		$json  = (string) wp_json_encode( $specs );

		$positions = [
			strpos( $json, 'Intro' ),
			strpos( $json, 'Alpha' ),
			strpos( $json, 'Middle' ),
			strpos( $json, 'Beta' ),
			strpos( $json, 'Outro' ),
		];

		$this->assertNotContains( false, $positions, 'Every piece of text is converted.' );

		$sorted = $positions;
		sort( $sorted );

		$this->assertSame( $sorted, $positions );
	}

	/**
	 * Blank root text produces no spec.
	 *
	 * @return void
	 */
	public function test_blank_root_text_is_skipped(): void {
		$with    = Converter::create()->convert_content( " \n[et_pb_section][et_pb_text]Alpha[/et_pb_text][/et_pb_section]\n " );
		$without = Converter::create()->convert_content( '[et_pb_section][et_pb_text]Alpha[/et_pb_text][/et_pb_section]' );

		$this->assertSame( count( $without ), count( $with ) );
	}
}
