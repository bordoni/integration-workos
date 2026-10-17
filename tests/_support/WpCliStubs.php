<?php
/**
 * Minimal WP-CLI doubles, so command classes can run inside wpunit.
 *
 * WP-CLI isn't installed in the test environment. These record what a
 * command printed instead of printing it, and `WP_CLI::error()` throws
 * like the real one does under `exit = false`. Each class is declared only
 * when the real one is absent.
 *
 * @package WorkOS\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Generic.Classes.DuplicateClassName

namespace WP_CLI {

	if ( ! class_exists( ExitException::class ) ) {
		/**
		 * Thrown by the WP_CLI::error() double.
		 */
		class ExitException extends \RuntimeException {
		}
	}

	if ( ! class_exists( Formatter::class ) ) {
		/**
		 * Records display_items() calls.
		 */
		class Formatter {

			/**
			 * Every display_items() call: format, fields, items.
			 *
			 * @var array<int, array{format:string, fields:array, items:array}>
			 */
			public static array $displayed = [];

			/**
			 * Output format.
			 *
			 * @var string
			 */
			private string $format;

			/**
			 * Fields to show.
			 *
			 * @var array<int, string>
			 */
			private array $fields;

			/**
			 * Constructor, matching the real signature.
			 *
			 * @param array             $assoc_args Associative args.
			 * @param array|string|null $fields     Fields.
			 * @param string|false      $prefix     Unused.
			 */
			public function __construct( &$assoc_args, $fields = null, $prefix = false ) {
				$this->format = (string) ( $assoc_args['format'] ?? 'table' );
				$this->fields = is_array( $fields ) ? $fields : [];
			}

			/**
			 * Record items instead of printing them.
			 *
			 * @param array $items Rows.
			 */
			public function display_items( $items ): void {
				self::$displayed[] = [
					'format' => $this->format,
					'fields' => $this->fields,
					'items'  => $items,
				];
			}
		}
	}
}

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Records messages instead of printing them.
		 */
		class WP_CLI {

			/**
			 * Messages, as [ type, text ].
			 *
			 * @var array<int, array{0:string,1:string}>
			 */
			public static array $messages = [];

			/**
			 * Forget everything recorded.
			 */
			public static function reset(): void {
				self::$messages               = [];
				\WP_CLI\Formatter::$displayed = [];
			}

			/**
			 * Record a success line.
			 *
			 * @param string $message Text.
			 */
			public static function success( $message ): void {
				self::$messages[] = [ 'success', (string) $message ];
			}

			/**
			 * Record a log line.
			 *
			 * @param string $message Text.
			 */
			public static function log( $message ): void {
				self::$messages[] = [ 'log', (string) $message ];
			}

			/**
			 * Record a warning.
			 *
			 * @param string $message Text.
			 */
			public static function warning( $message ): void {
				self::$messages[] = [ 'warning', (string) $message ];
			}

			/**
			 * Record an error and stop, as WP-CLI does.
			 *
			 * @param string|\WP_Error $message Text or error.
			 *
			 * @throws \WP_CLI\ExitException Always.
			 */
			public static function error( $message ): void {
				$text             = $message instanceof \WP_Error ? $message->get_error_message() : (string) $message;
				self::$messages[] = [ 'error', $text ];

				throw new \WP_CLI\ExitException( $text );
			}

			/**
			 * Nothing to register in tests.
			 *
			 * @param mixed ...$args Ignored.
			 */
			public static function add_command( ...$args ): void {
			}
		}
	}

	if ( ! class_exists( 'WP_CLI_Command' ) ) {
		/**
		 * Base class double.
		 */
		class WP_CLI_Command {
		}
	}
}
