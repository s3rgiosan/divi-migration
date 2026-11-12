<?php

namespace S3S\WP\DiviMigration\Tests;

use S3S\WP\DiviMigration\CLI;
use S3S\WP\DiviMigration\Content;
use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Rules\Rule;
use WP_CLI;
use WP_UnitTestCase;

/**
 * Base test case: runs the CLI commands and writes and reads post content
 * without the save filters.
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Modified date that test posts start with.
	 */
	const MODIFIED = '2020-01-02 03:04:05';

	/**
	 * Exit code of the last `WP_CLI::halt()` call, or null when the last
	 * command did not halt.
	 *
	 * @var int|null
	 */
	protected ?int $halted = null;

	/**
	 * Theme root that holds a fake Divi theme, or an empty string.
	 *
	 * @var string
	 */
	private string $divi_root = '';

	/**
	 * Clears the recorded WP-CLI output.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		WP_CLI::reset();

		$this->halted = null;
	}

	/**
	 * Removes the fake Divi theme.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wp_theme_directories;

		if ( '' !== $this->divi_root ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Removes the theme root that the test registered.
			$wp_theme_directories = array_values( array_diff( $wp_theme_directories, [ $this->divi_root ] ) );

			$this->remove_directory( $this->divi_root );

			$this->divi_root = '';

			wp_clean_themes_cache();
		}

		parent::tear_down();
	}

	/**
	 * Runs `wp divi-migration convert` and returns the status of each post.
	 *
	 * A `WP_CLI::halt()` call ends the command and sets `$halted`.
	 *
	 * @param int[] $ids   Post IDs.
	 * @param array $flags Associative arguments, such as `[ 'classic' => true ]`.
	 * @return array<int, string> Status keyed by post ID.
	 */
	protected function convert( array $ids, array $flags = [] ): array {
		$this->command(
			'convert',
			array_map( 'strval', $ids ),
			array_merge(
				[
					'status' => 'publish,draft,private,future,pending',
					'format' => 'table',
				],
				$flags
			)
		);

		$statuses = [];

		foreach ( WP_CLI::$rows as $row ) {
			$statuses[ (int) $row['ID'] ] = (string) $row['status'];
		}

		return $statuses;
	}

	/**
	 * Runs `wp divi-migration reset` and returns its summary message.
	 *
	 * A `WP_CLI::halt()` call ends the command and sets `$halted`.
	 *
	 * @param int[] $ids   Post IDs.
	 * @param array $flags Associative arguments, such as `[ 'all' => true ]`.
	 * @return string
	 */
	protected function reset_posts( array $ids, array $flags = [] ): string {
		$this->command( 'reset', array_map( 'strval', $ids ), $flags );

		$summary = '';

		foreach ( WP_CLI::$messages as [ $type, $text ] ) {
			if ( in_array( $type, [ 'success', 'warning' ], true ) && str_starts_with( $text, 'Restored ' ) ) {
				$summary = $text;
			}
		}

		return $summary;
	}

	/**
	 * Runs a command method of the CLI class with the arguments WP-CLI passes
	 * after it applies the documented defaults. A `WP_CLI::halt()` call ends
	 * the command and sets `$halted`; a `WP_CLI::error()` call throws.
	 *
	 * @param string   $method     Command method.
	 * @param string[] $args       Positional arguments.
	 * @param array    $assoc_args Associative arguments.
	 * @return void
	 */
	protected function command( string $method, array $args = [], array $assoc_args = [] ): void {
		WP_CLI::reset();

		$this->halted = null;

		try {
			( new CLI() )->$method( $args, $assoc_args );
		} catch ( \WP_CLI_Halt $halt ) {
			$this->halted = $halt->exit_code;
		}
	}

	/**
	 * Returns the recorded messages of one type.
	 *
	 * @param string $type Message type: `line`, `log`, `success`, `warning` or `error`.
	 * @return string[]
	 */
	protected function messages( string $type ): array {
		$texts = [];

		foreach ( WP_CLI::$messages as [ $message_type, $text ] ) {
			if ( $message_type === $type ) {
				$texts[] = $text;
			}
		}

		return $texts;
	}

	/**
	 * Returns the row of a post in the last table that `convert` printed.
	 *
	 * @param int $id Post ID.
	 * @return array<string, int|string>
	 */
	protected function row( int $id ): array {
		foreach ( WP_CLI::$rows as $row ) {
			if ( (int) $row['ID'] === $id ) {
				return $row;
			}
		}

		$this->fail( sprintf( 'The table has no row for post %d.', $id ) );
	}

	/**
	 * Installs a fake Divi theme whose builder file defines a version, in a
	 * theme root of its own, until the end of the test.
	 *
	 * @param string $version Builder version.
	 * @return void
	 */
	protected function install_divi( string $version ): void {
		$this->divi_root = trailingslashit( get_temp_dir() ) . 'divi-migration-themes-' . wp_generate_password( 8, false );
		$theme_dir       = $this->divi_root . '/Divi';

		wp_mkdir_p( $theme_dir . '/includes/builder' );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary test files.
		file_put_contents( $theme_dir . '/style.css', "/*\nTheme Name: Divi\nVersion: 1.0.0\n*/\n" );
		file_put_contents( $theme_dir . '/index.php', "<?php\n" );
		file_put_contents( $theme_dir . '/includes/builder/functions.php', "<?php\ndefine( 'ET_BUILDER_PRODUCT_VERSION', '" . $version . "' );\n" );
		// phpcs:enable

		register_theme_directory( $this->divi_root );
		wp_clean_themes_cache();
	}

	/**
	 * Creates a post and writes its content straight to the database, so the
	 * content is stored byte for byte, as a site stores unfiltered HTML. The
	 * post gets a fixed modified date.
	 *
	 * @param string $content   Post content.
	 * @param int    $author    Author ID.
	 * @param string $post_type Post type.
	 * @return int Post ID.
	 */
	protected function insert_post( string $content, int $author = 0, string $post_type = 'post' ): int {
		global $wpdb;

		$author = $author > 0 ? $author : self::factory()->user->create( [ 'role' => 'administrator' ] );
		$id     = self::factory()->post->create(
			[
				'post_type'   => $post_type,
				'post_title'  => 'Lorem ipsum',
				'post_author' => $author,
			]
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stores the content without the save filters.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_content'      => $content,
				'post_modified'     => self::MODIFIED,
				'post_modified_gmt' => self::MODIFIED,
			],
			[ 'ID' => $id ]
		);

		clean_post_cache( $id );

		return $id;
	}

	/**
	 * Writes post fields straight to the database, and clears the post cache.
	 *
	 * @param int                  $id     Post ID.
	 * @param array<string, mixed> $fields Columns of the posts table.
	 * @return void
	 */
	protected function set_post_fields( int $id, array $fields ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Stores the fields without the save filters.
		$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $id ] );

		clean_post_cache( $id );
	}

	/**
	 * Returns Divi content that holds modules in one section, row and column.
	 *
	 * @param string $modules Module shortcodes.
	 * @return string
	 */
	protected function divi_page( string $modules ): string {
		return '[et_pb_section][et_pb_row][et_pb_column type="4_4"]' . $modules . '[/et_pb_column][/et_pb_row][/et_pb_section]';
	}

	/**
	 * Adds conversion rules, which replace the default rules of the same tags.
	 *
	 * @param array<string, Rule> $rules Rules keyed by shortcode tag.
	 * @return void
	 */
	protected function add_rules( array $rules ): void {
		add_filter( 's3s_divi_migration_rules', fn( array $defaults ) => array_merge( $defaults, $rules ) );
	}

	/**
	 * Returns a rule that runs a callback.
	 *
	 * @param callable $callback Receives the node and the converter, and returns block specs.
	 * @return Rule
	 */
	protected function rule( callable $callback ): Rule {
		return new class( $callback ) implements Rule {

			/**
			 * Constructor.
			 *
			 * @param callable $callback Callback.
			 */
			public function __construct( private $callback ) {}

			/**
			 * {@inheritDoc}
			 *
			 * @param Node      $node      Module node.
			 * @param Converter $converter Converter.
			 * @return array[]
			 */
			public function convert( Node $node, Converter $converter ): array {
				return ( $this->callback )( $node, $converter );
			}
		};
	}

	/**
	 * Returns a post's content as the database stores it.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	protected function stored_content( int $id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads past the object cache.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) );
	}

	/**
	 * Returns a post's modified dates as the database stores them.
	 *
	 * @param int $id Post ID.
	 * @return string[] Local and GMT modified dates.
	 */
	protected function stored_modified( int $id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads past the object cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_N );

		return is_array( $row ) ? $row : [];
	}

	/**
	 * Returns a post's status and dates as the database stores them.
	 *
	 * @param int $id Post ID.
	 * @return array<string, string>
	 */
	protected function post_state( int $id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reads past the object cache.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_status, post_date, post_date_gmt, post_modified, post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : [];
	}

	/**
	 * Records the new statuses of the post status transitions that follow.
	 *
	 * @return \ArrayObject The new statuses, in order.
	 */
	protected function spy_transitions(): \ArrayObject {
		$new_statuses = new \ArrayObject();

		add_action(
			'transition_post_status',
			function ( string $new_status ) use ( $new_statuses ) {
				$new_statuses[] = $new_status;
			}
		);

		return $new_statuses;
	}

	/**
	 * Whether a post has a backup of its original content.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	protected function has_backup( int $id ): bool {
		return metadata_exists( 'post', $id, Content::BACKUP_META );
	}

	/**
	 * Removes a directory and its files.
	 *
	 * @param string $directory Directory.
	 * @return void
	 */
	private function remove_directory( string $directory ): void {
		$entries = scandir( $directory );

		foreach ( false === $entries ? [] : array_diff( $entries, [ '.', '..' ] ) as $entry ) {
			$path = $directory . '/' . $entry;

			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- A temporary test directory.
	}
}
