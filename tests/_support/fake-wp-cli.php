<?php
/**
 * Minimal WP-CLI doubles so wpunit tests can call CLI command methods.
 *
 * `WP_CLI::error()` throws instead of exiting. Output is kept in
 * `WP_CLI::$log` for assertions. The `WP_CLI` constant is never defined,
 * so the plugin's CLI controller stays inactive.
 *
 * @package WorkOS\Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting

if ( ! class_exists( 'WP_CLI_Command', false ) ) {
	class WP_CLI_Command {}
}

if ( ! class_exists( 'WP_CLI', false ) ) {
	class WP_CLI_Error_Exception extends RuntimeException {}

	class WP_CLI {
		/** @var string[] */
		public static array $log = [];

		public static function reset(): void {
			self::$log = [];
		}

		public static function log( $message ): void {
			self::$log[] = (string) $message;
		}

		public static function warning( $message ): void {
			self::$log[] = 'Warning: ' . $message;
		}

		public static function success( $message ): void {
			self::$log[] = 'Success: ' . $message;
		}

		public static function error( $message ): void {
			self::$log[] = 'Error: ' . $message;
			throw new WP_CLI_Error_Exception( (string) $message );
		}

		public static function confirm( $question, $assoc_args = [] ): void {}
	}
}
