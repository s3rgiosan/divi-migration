<?php

namespace S3S\WP\DiviMigration;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Singleton instance of the Plugin.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns singleton instance.
	 *
	 * @return Plugin The singleton instance.
	 */
	public static function get_instance(): Plugin {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Enable plugin functionality.
	 *
	 * @return void
	 */
	public function enable(): void {

		add_action( 'init', [ $this, 'load_textdomain' ] );

		$modules = [
			new CLI(),
		];

		foreach ( $modules as $module ) {
			if ( $module instanceof BaseModule && $module->can_register() ) {
				$module->register();
			}
		}
	}

	/**
	 * Loads the plugin's translations from its `languages` folder.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'divi-migration', false, dirname( S3S_DIVI_MIGRATION_BASENAME ) . '/languages' );
	}
}
