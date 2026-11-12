<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library and the plugin.
 *
 * The test library directory comes from `WP_TESTS_DIR`, or from
 * `WP_PHPUNIT__DIR` when the library is the `wp-phpunit/wp-phpunit` Composer package
 * (its autoloader sets that variable).
 *
 * @package S3S\WP\DiviMigration
 */

$divi_migration_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $divi_migration_tests_dir || '' === $divi_migration_tests_dir ) {
	$divi_migration_tests_dir = (string) getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! is_readable( $divi_migration_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "The WordPress test library is missing. Set WP_TESTS_DIR, install the Composer dev dependencies, or run the tests with `wpc test`.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $divi_migration_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__, 2 ) . '/plugin.php';
	}
);

require_once __DIR__ . '/stubs/wp-cli.php';
require_once __DIR__ . '/stubs/wp-cli-utils.php';
require_once $divi_migration_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
