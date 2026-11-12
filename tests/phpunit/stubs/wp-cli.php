<?php
/**
 * Test double for the parts of WP-CLI that the CLI class calls.
 *
 * The tests call the CLI command methods directly. This double records the
 * messages and, through `wp-cli-utils.php`, the table rows that the commands
 * print, so the tests can read them. `WP_CLI::error()` and `WP_CLI::halt()` throw,
 * because WP-CLI stops the command there.
 *
 * @package S3S\WP\DiviMigration
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The test doubles and their exceptions belong together.

/**
 * Thrown by `WP_CLI::error()`.
 */
class WP_CLI_Error extends RuntimeException {}

/**
 * Thrown by `WP_CLI::halt()`.
 */
class WP_CLI_Halt extends RuntimeException {

	/**
	 * Exit code.
	 *
	 * @var int
	 */
	public int $exit_code;

	/**
	 * Constructor.
	 *
	 * @param int $exit_code Exit code.
	 */
	public function __construct( int $exit_code ) {
		$this->exit_code = $exit_code;

		parent::__construct( 'WP_CLI::halt(' . $exit_code . ')', $exit_code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception.
	}
}

/**
 * Records WP-CLI messages.
 */
class WP_CLI {

	/**
	 * Messages, each a list of the message type and its text.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public static array $messages = [];

	/**
	 * Rows of the last table that a command printed.
	 *
	 * @var array[]
	 */
	public static array $rows = [];

	/**
	 * Columns of the last table that a command printed.
	 *
	 * @var string[]
	 */
	public static array $fields = [];

	/**
	 * Clears the recorded messages, rows and columns.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$messages = [];
		self::$rows     = [];
		self::$fields   = [];
	}

	/**
	 * Ignores a command registration.
	 *
	 * @param string $name    Command name.
	 * @param mixed  $handler Command handler.
	 * @return void
	 */
	public static function add_command( string $name, mixed $handler ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

	/**
	 * Records a line of output.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function line( string $message = '' ): void {
		self::$messages[] = [ 'line', $message ];
	}

	/**
	 * Records a log message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( string $message ): void {
		self::$messages[] = [ 'log', $message ];
	}

	/**
	 * Records a success message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function success( string $message ): void {
		self::$messages[] = [ 'success', $message ];
	}

	/**
	 * Records a warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function warning( string $message ): void {
		self::$messages[] = [ 'warning', $message ];
	}

	/**
	 * Records an error and stops the command.
	 *
	 * @param string $message Message.
	 * @return never
	 * @throws WP_CLI_Error Always.
	 */
	public static function error( string $message ): never {
		self::$messages[] = [ 'error', $message ];
		throw new WP_CLI_Error( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception; WP-CLI prints the message as is.
	}

	/**
	 * Records a halt and stops the command.
	 *
	 * @param int $code Exit code.
	 * @return never
	 * @throws WP_CLI_Halt Always.
	 */
	public static function halt( int $code ): never {
		self::$messages[] = [ 'halt', (string) $code ];
		throw new WP_CLI_Halt( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception.
	}
}
