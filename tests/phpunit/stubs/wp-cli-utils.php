<?php
/**
 * Test double for `WP_CLI\Utils\format_items()`, which records the rows of
 * the table that a command prints.
 *
 * @package S3S\WP\DiviMigration
 */

namespace WP_CLI\Utils;

/**
 * Records the rows of a table. The double checks what the real function
 * needs: a known format, and every field in every row.
 *
 * @param string   $format Output format.
 * @param array[]  $items  Rows.
 * @param string[] $fields Columns.
 * @return void
 * @throws \InvalidArgumentException When the format is unknown or a row lacks a field.
 */
function format_items( string $format, array $items, array $fields ): void {
	if ( ! in_array( $format, [ 'table', 'json', 'csv', 'yaml', 'ids', 'count' ], true ) ) {
		throw new \InvalidArgumentException( esc_html( sprintf( 'Unknown format: %s', $format ) ) );
	}

	foreach ( $items as $index => $item ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $item ) || ! array_key_exists( $field, $item ) ) {
				throw new \InvalidArgumentException( esc_html( sprintf( 'Row %s has no field %s.', $index, $field ) ) );
			}
		}
	}

	\WP_CLI::$rows   = $items;
	\WP_CLI::$fields = $fields;
}
