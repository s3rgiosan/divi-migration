<?php // phpcs:ignore Generic.Commenting.DocComment.MissingShort
/**
 * @wordpress-plugin
 * Plugin Name:       Divi Migration
 * Description:       Convert Divi Builder content to blocks.
 * Plugin URI:        https://github.com/s3rgiosan/divi-migration
 * Requires at least: 7.1
 * Requires PHP:      8.2
 * Divi tested up to: 4.9.11
 * Version:           1.0.0
 * Author:            Sérgio Santos
 * Author URI:        https://s3rgiosan.dev/?utm_source=wp-plugins&utm_medium=divi-migration&utm_campaign=author-uri
 * License:           GPL-3.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-3.0-or-later.html
 * Update URI:        https://s3rgiosan.dev/
 * GitHub Plugin URI: https://github.com/s3rgiosan/divi-migration
 * Text Domain:       divi-migration
 * Domain Path:       /languages
 */

namespace S3S\WP\DiviMigration;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'S3S_DIVI_MIGRATION_VERSION', '1.0.0' );
define( 'S3S_DIVI_MIGRATION_BASENAME', plugin_basename( __FILE__ ) );
define( 'S3S_DIVI_MIGRATION_PATH', plugin_dir_path( __FILE__ ) );

if ( ! file_exists( S3S_DIVI_MIGRATION_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Divi Migration is inactive: the Composer dependencies are missing. Run "composer install --no-dev" in the plugin folder, or install a release package.', 'divi-migration' )
			);
		}
	);

	return;
}

require_once S3S_DIVI_MIGRATION_PATH . 'vendor/autoload.php';

if ( class_exists( PucFactory::class ) ) {
	PucFactory::buildUpdateChecker(
		'https://github.com/s3rgiosan/divi-migration/',
		__FILE__,
		'divi-migration'
	)->setBranch( 'main' );
}

add_action(
	'plugins_loaded',
	function () {
		Plugin::get_instance()->enable();
	}
);
