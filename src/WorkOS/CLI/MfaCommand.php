<?php
/**
 * CLI MFA Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\App;
use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\Policy;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Manage a user's two-step verification (MFA).
 */
class MfaCommand extends \WP_CLI_Command {

	/**
	 * Show a user's MFA status, read from WorkOS.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : WP user ID, email or login.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos mfa status 42
	 *     wp workos mfa status alice@example.com --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$user   = $this->user( $args[0] );
		$status = $this->factors()->status( $user->ID );
		if ( is_wp_error( $status ) ) {
			WP_CLI::error( $status->get_error_message() );
		}

		$row = [
			'user'          => $user->user_email,
			'enabled'       => $status['enabled'] ? 'yes' : 'no',
			'required_flag' => $status['required'] ? 'yes' : 'no',
			'must_use_mfa'  => App::container()->get( Policy::class )->is_required( $user->ID ) ? 'yes' : 'no',
			'factors'       => count( $status['factors'] ),
		];

		( new Formatter( $assoc_args, array_keys( $row ) ) )->display_item( $row );
	}

	/**
	 * Require MFA for a user. Without a factor, they set one up at their next sign-in.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : WP user ID, email or login.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos mfa require 42
	 *
	 * @param array $args Positional arguments.
	 */
	public function require( $args ) {
		$user    = $this->user( $args[0] );
		$changed = $this->factors()->set_required( $user->ID, true );

		WP_CLI::success( $changed ? "MFA is now required for {$user->user_email}." : "MFA was already required for {$user->user_email}." );
	}

	/**
	 * Clear the require flag on a user. Roles and filters may still require MFA.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : WP user ID, email or login.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos mfa unrequire 42
	 *
	 * @param array $args Positional arguments.
	 */
	public function unrequire( $args ) {
		$user    = $this->user( $args[0] );
		$changed = $this->factors()->set_required( $user->ID, false );

		WP_CLI::success( $changed ? "Cleared the MFA requirement for {$user->user_email}." : "MFA wasn't required for {$user->user_email}." );
	}

	/**
	 * Remove every MFA factor a user has (lost authenticator).
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : WP user ID, email or login.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos mfa reset 42 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function reset( $args, $assoc_args ) {
		$user = $this->user( $args[0] );

		WP_CLI::confirm( "Remove every authenticator app from {$user->user_email}?", $assoc_args );

		$removed = $this->factors()->reset( $user->ID );
		if ( is_wp_error( $removed ) ) {
			WP_CLI::error( $removed->get_error_message() );
		}

		WP_CLI::success( "Removed {$removed} factor(s) from {$user->user_email}." );
	}

	/**
	 * Resolve a user by ID, email or login, or stop.
	 *
	 * @param string $identifier User ID, email or login.
	 *
	 * @return \WP_User
	 */
	private function user( string $identifier ): \WP_User {
		if ( ctype_digit( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
		} elseif ( is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		} else {
			$user = get_user_by( 'login', $identifier );
		}

		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		return $user;
	}

	/**
	 * Factor service.
	 *
	 * @return FactorService
	 */
	private function factors(): FactorService {
		return App::container()->get( FactorService::class );
	}
}
