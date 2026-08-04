<?php
/**
 * CLI Lock Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\App;
use WorkOS\Lock\GarbageCollector;
use WorkOS\Lock\Registry;
use WorkOS\Time\Clock;
use WP_CLI;
use WP_CLI\Formatter;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and clear attempt locks.
 *
 * The counterpart to the admin screens, for the cases where those are the wrong
 * tool: a support engineer working over SSH, a bulk clear after a misconfigured
 * threshold, or checking whether a lock is what is actually turning someone
 * away before changing anything else.
 */
class LockCommand extends \WP_CLI_Command {

	/**
	 * List the registered locks and their configured thresholds.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos lock list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) {
		$registry = $this->registry();
		$rows     = [];

		foreach ( $registry->all() as $slug => $lock ) {
			$probe = $lock->for_key( 'cli-probe' );

			$rows[] = [
				'slug'      => $slug,
				'label'     => method_exists( $lock, 'label' ) ? $lock->label() : $slug,
				'threshold' => method_exists( $lock, 'threshold' ) ? $lock->threshold( $probe ) : '',
				'cooldowns' => method_exists( $lock, 'cooldowns' ) ? implode( ', ', $lock->cooldowns( $probe ) ) : '',
				'decay'     => method_exists( $lock, 'decay' ) ? $lock->decay( $probe ) : '',
			];
		}

		$formatter = new Formatter( $assoc_args, [ 'slug', 'label', 'threshold', 'cooldowns', 'decay' ] );
		$formatter->display_items( $rows );
	}

	/**
	 * Show the current state of a lock for a subject.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The lock slug. Use `wp workos lock list` to see them.
	 *
	 * <subject>
	 * : The subject to inspect — an email address, an IP, a WP user ID, or an
	 * arbitrary key. The kind is inferred unless --type is given.
	 *
	 * [--type=<type>]
	 * : Force the subject kind rather than inferring it.
	 * ---
	 * options:
	 *   - user
	 *   - email
	 *   - ip
	 *   - key
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos lock status otp_send someone@example.com
	 *     wp workos lock status password_auth 203.0.113.4 --type=ip
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$lock    = $this->resolve_lock( (string) $args[0] );
		$subject = $this->resolve_subject( $lock, (string) $args[1], $assoc_args['type'] ?? '' );
		$state   = $lock->status( $subject );
		$now     = App::container()->get( Clock::class )->now();

		WP_CLI::line( sprintf( 'Lock:      %s', $lock->slug() ) );
		WP_CLI::line( sprintf( 'Subject:   %s (%s)', $subject->value(), $subject->type() ) );
		WP_CLI::line(
			sprintf(
				'Stored in: %s',
				$subject->is_user_backed() ? sprintf( 'user meta (user %d)', $subject->user_id() ) : 'options'
			)
		);
		WP_CLI::line( sprintf( 'Attempts:  %d', $state->strikes() ) );
		WP_CLI::line( sprintf( 'Lockouts:  %d', $state->breaches() ) );

		if ( $state->is_locked( $now ) ) {
			WP_CLI::warning( sprintf( 'Locked for another %d seconds.', $state->retry_after( $now ) ) );

			return;
		}

		WP_CLI::success( 'Not locked.' );
	}

	/**
	 * Clear one lock for one subject.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The lock slug.
	 *
	 * <subject>
	 * : The subject to clear.
	 *
	 * [--type=<type>]
	 * : Force the subject kind rather than inferring it.
	 * ---
	 * options:
	 *   - user
	 *   - email
	 *   - ip
	 *   - key
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos lock clear otp_send someone@example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( $args, $assoc_args ) {
		$lock    = $this->resolve_lock( (string) $args[0] );
		$subject = $this->resolve_subject( $lock, (string) $args[1], $assoc_args['type'] ?? '' );

		$lock->release( $subject );

		WP_CLI::success( sprintf( 'Cleared %s for %s.', $lock->slug(), $subject->value() ) );
	}

	/**
	 * Clear every lock held against a WordPress user.
	 *
	 * ## OPTIONS
	 *
	 * <user>
	 * : WP user ID, login, or email.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos lock clear-user 42
	 *     wp workos lock clear-user someone@example.com
	 *
	 * @subcommand clear-user
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear_user( $args, $assoc_args ) {
		$user = get_user_by( 'id', (int) $args[0] );

		if ( ! $user ) {
			$user = is_email( $args[0] ) ? get_user_by( 'email', $args[0] ) : get_user_by( 'login', $args[0] );
		}

		if ( ! $user ) {
			WP_CLI::error( sprintf( 'No user found for "%s".', $args[0] ) );
		}

		$cleared = $this->registry()->release_all_for_user( (int) $user->ID );

		WP_CLI::success(
			sprintf( 'Cleared %d lock(s) for %s.', $cleared, $user->user_login )
		);
	}

	/**
	 * Run the cleanup sweep now instead of waiting for the scheduled one.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos lock gc
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function gc( $args, $assoc_args ) {
		$removed = App::container()->get( GarbageCollector::class )->run();

		WP_CLI::success( sprintf( 'Removed %d expired lock record(s).', $removed ) );
	}

	/**
	 * Resolve a lock by slug, or halt.
	 *
	 * @param string $slug Lock slug.
	 *
	 * @return \WorkOS\Lock\Contracts\Lockable
	 */
	private function resolve_lock( string $slug ) {
		$registry = $this->registry();
		$lock     = $registry->get( $slug );

		if ( null === $lock ) {
			WP_CLI::error(
				sprintf( 'Unknown lock "%s". Available: %s', $slug, implode( ', ', $registry->slugs() ) )
			);
		}

		return $lock;
	}

	/**
	 * Build a subject from a CLI argument.
	 *
	 * @param \WorkOS\Lock\Contracts\Lockable $lock  Lock the subject belongs to.
	 * @param string                          $value Raw subject value.
	 * @param string                          $type  Forced subject kind, or an empty string to infer.
	 *
	 * @return \WorkOS\Lock\Subject
	 */
	private function resolve_subject( $lock, string $value, string $type ) {
		if ( '' === $type ) {
			if ( is_email( $value ) ) {
				$type = 'email';
			} elseif ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
				$type = 'ip';
			} elseif ( ctype_digit( $value ) ) {
				$type = 'user';
			} else {
				$type = 'key';
			}
		}

		switch ( $type ) {
			case 'user':
				return $lock->for_user( (int) $value );
			case 'email':
				return $lock->for_email( $value );
			case 'ip':
				return $lock->for_ip( $value );
			default:
				return $lock->for_key( $value );
		}
	}

	/**
	 * Lock registry.
	 *
	 * @return Registry
	 */
	private function registry(): Registry {
		return App::container()->get( Registry::class );
	}
}
