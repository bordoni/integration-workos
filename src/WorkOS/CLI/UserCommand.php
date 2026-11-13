<?php
/**
 * CLI User Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\Sync\UserSync;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Manage WorkOS-linked WordPress users.
 */
class UserCommand extends \WP_CLI_Command {

	/**
	 * Inbound user refresh service.
	 *
	 * @var UserSync
	 */
	private UserSync $user_sync;

	/**
	 * Receive the shared user sync service from the CLI controller.
	 *
	 * @param UserSync $user_sync User refresh service.
	 */
	public function __construct( UserSync $user_sync ) {
		$this->user_sync = $user_sync;
	}


	/**
	 * Get a user with WorkOS metadata.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The user identifier (WP user ID, email, or WorkOS user ID).
	 *
	 * [--by=<field>]
	 * : Field to look up by.
	 * ---
	 * default: id
	 * options:
	 *   - id
	 *   - email
	 *   - workos_id
	 * ---
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
	 *     wp workos user get 1
	 *     wp workos user get admin@example.com --by=email
	 *     wp workos user get user_01ABC --by=workos_id
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( $args, $assoc_args ) {
		$identifier = $args[0];
		$by         = $assoc_args['by'] ?? 'id';

		switch ( $by ) {
			case 'email':
				$user = get_user_by( 'email', $identifier );
				break;

			case 'workos_id':
				$wp_id = UserSync::get_wp_user_id_by_workos_id( $identifier );
				$user  = $wp_id ? get_user_by( 'id', $wp_id ) : false;
				break;

			case 'id':
			default:
				$user = get_user_by( 'id', (int) $identifier );
				break;
		}

		if ( ! $user ) {
			WP_CLI::error( 'User not found.' );
		}

		$workos_id   = get_user_meta( $user->ID, '_workos_user_id', true );
		$workos_org  = get_user_meta( $user->ID, '_workos_org_id', true );
		$last_synced = get_user_meta( $user->ID, '_workos_last_synced_at', true );
		$deactivated = get_user_meta( $user->ID, '_workos_deactivated', true );

		$row = [
			[
				'wp_user_id'     => $user->ID,
				'user_login'     => $user->user_login,
				'user_email'     => $user->user_email,
				'display_name'   => $user->display_name,
				'role'           => implode( ', ', $user->roles ),
				'workos_user_id' => $workos_id ? $workos_id : '(none)',
				'workos_org_id'  => $workos_org ? $workos_org : '(none)',
				'last_synced_at' => $last_synced ? $last_synced : '(never)',
				'is_deactivated' => $deactivated ? 'yes' : 'no',
			],
		];

		$formatter = new Formatter(
			$assoc_args,
			[ 'wp_user_id', 'user_login', 'user_email', 'display_name', 'role', 'workos_user_id', 'workos_org_id', 'last_synced_at', 'is_deactivated' ]
		);

		$formatter->display_items( $row );
	}

	/**
	 * List WordPress users with WorkOS link status.
	 *
	 * ## OPTIONS
	 *
	 * [--linked]
	 * : Show only users linked to WorkOS.
	 *
	 * [--unlinked]
	 * : Show only users not linked to WorkOS.
	 *
	 * [--role=<role>]
	 * : Filter by WordPress role.
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
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of fields to show.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user list
	 *     wp workos user list --linked --format=json
	 *     wp workos user list --unlinked --role=subscriber
	 *     wp workos user list --format=ids
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		$query_args = [
			'number' => 0, // All users.
		];

		if ( ! empty( $assoc_args['role'] ) ) {
			$query_args['role'] = $assoc_args['role'];
		}

		$linked   = isset( $assoc_args['linked'] );
		$unlinked = isset( $assoc_args['unlinked'] );

		if ( $linked && ! $unlinked ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => '_workos_user_id',
					'compare' => 'EXISTS',
				],
			];
		} elseif ( $unlinked && ! $linked ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => '_workos_user_id',
					'compare' => 'NOT EXISTS',
				],
			];
		}

		$format = $assoc_args['format'] ?? 'table';

		if ( 'ids' === $format ) {
			$query_args['fields'] = 'ID';
			$users                = get_users( $query_args );
			WP_CLI::line( implode( ' ', $users ) );
			return;
		}

		$users = get_users( $query_args );

		$rows = array_map(
			static function ( $user ) {
				$workos_id = get_user_meta( $user->ID, '_workos_user_id', true );
				return [
					'ID'             => $user->ID,
					'user_login'     => $user->user_login,
					'user_email'     => $user->user_email,
					'display_name'   => $user->display_name,
					'role'           => implode( ', ', $user->roles ),
					'workos_user_id' => $workos_id ? $workos_id : '(none)',
					'linked'         => $workos_id ? 'yes' : 'no',
				];
			},
			$users
		);

		$default_fields = [ 'ID', 'user_login', 'user_email', 'role', 'workos_user_id', 'linked' ];

		$formatter = new Formatter(
			$assoc_args,
			$default_fields
		);

		$formatter->display_items( $rows );
	}

	/**
	 * Link a WordPress user to a WorkOS user ID.
	 *
	 * Validates the WorkOS user exists via API before linking. A user that is
	 * already linked keeps its link unless --force is given; this is the
	 * explicit way to reassign an account when Re-link Protection is on.
	 *
	 * ## OPTIONS
	 *
	 * <wp_user_id>
	 * : WordPress user ID.
	 *
	 * <workos_user_id>
	 * : WorkOS user ID.
	 *
	 * [--force]
	 * : Replace an existing link to a different WorkOS user.
	 *
	 * [--reactivate]
	 * : Also clear the deactivation. Required to link an account that was
	 * deactivated because its WorkOS user was deleted.
	 *
	 * [--yes]
	 * : Skip the confirmation for --force.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user link 1 user_01ABC
	 *     wp workos user link 1 user_01XYZ --force
	 *     wp workos user link 1 user_01XYZ --force --reactivate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function link( $args, $assoc_args ) {
		$this->require_enabled();

		$wp_user_id     = (int) $args[0];
		$workos_user_id = $args[1];
		$force          = ! empty( $assoc_args['force'] );
		$reactivate     = ! empty( $assoc_args['reactivate'] );

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			WP_CLI::error( "WordPress user #{$wp_user_id} not found." );
		}

		$existing_link = (string) get_user_meta( $wp_user_id, '_workos_user_id', true );
		if ( $existing_link === $workos_user_id ) {
			WP_CLI::success( "User #{$wp_user_id} is already linked to WorkOS user {$workos_user_id}." );
			return;
		}

		if ( $existing_link && ! $force ) {
			WP_CLI::error( "User #{$wp_user_id} is already linked to WorkOS ID: {$existing_link}. Use --force to replace the link." );
		}

		// One WorkOS user maps to one WP user.
		$other_user_id = UserSync::get_wp_user_id_by_workos_id( $workos_user_id );
		if ( $other_user_id && $other_user_id !== $wp_user_id ) {
			WP_CLI::error( "WorkOS user {$workos_user_id} is already linked to WordPress user #{$other_user_id}. Unlink that user first." );
		}

		// An account deactivated by user.deleted belongs to a WorkOS user that no longer
		// exists. Handing it to a new identity is a separate decision from re-linking.
		$deactivated = (bool) get_user_meta( $wp_user_id, '_workos_deactivated', true );
		if ( $deactivated && ! $reactivate ) {
			WP_CLI::error( "User #{$wp_user_id} was deactivated when its WorkOS user was deleted. Pass --reactivate to link it to {$workos_user_id} and reactivate it." );
		}

		// Validate the WorkOS user exists.
		$workos_user = workos()->api()->get_user( $workos_user_id );
		if ( is_wp_error( $workos_user ) ) {
			WP_CLI::error( 'Failed to fetch WorkOS user: ' . $workos_user->get_error_message() );
		}

		if ( $existing_link ) {
			WP_CLI::confirm( "Replace the link of user #{$wp_user_id} ({$user->user_email}) from WorkOS user {$existing_link} to {$workos_user_id}?", $assoc_args );
		}

		if ( ! UserSync::link_user( $wp_user_id, $workos_user, 'cli_link', $force ) ) {
			WP_CLI::error( "Could not link WordPress user #{$wp_user_id} to WorkOS user {$workos_user_id}. See the debug log." );
		}

		if ( $deactivated ) {
			delete_user_meta( $wp_user_id, '_workos_deactivated' );
			WP_CLI::log( "Reactivated WordPress user #{$wp_user_id}. Check its role: a demoted account keeps the role it was demoted to." );
		}

		WP_CLI::success( "Linked WordPress user #{$wp_user_id} to WorkOS user {$workos_user_id}." );
	}

	/**
	 * Remove the WorkOS link from a WordPress user.
	 *
	 * ## OPTIONS
	 *
	 * <wp_user_id>
	 * : WordPress user ID.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user unlink 1
	 *     wp workos user unlink 1 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unlink( $args, $assoc_args ) {
		$wp_user_id = (int) $args[0];

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			WP_CLI::error( "WordPress user #{$wp_user_id} not found." );
		}

		$workos_id = get_user_meta( $wp_user_id, '_workos_user_id', true );
		if ( ! $workos_id ) {
			WP_CLI::error( "User #{$wp_user_id} is not linked to WorkOS." );
		}

		WP_CLI::confirm( "Unlink user #{$wp_user_id} ({$user->user_email}) from WorkOS ID {$workos_id}?", $assoc_args );

		UserSync::unlink_user( $wp_user_id );
		WP_CLI::success( "Unlinked WordPress user #{$wp_user_id} from WorkOS. Its sessions were ended." );
	}

	/**
	 * Move a WorkOS sign-in from one WordPress user to another.
	 *
	 * For accounts re-owned on the WordPress side: the customer's email and data
	 * moved to another WordPress user, but the WorkOS link stayed on the old one,
	 * so signing in lands in the old account (or is refused as a split account).
	 *
	 * Unlinks the WorkOS user from <from_user_id> and ends every session that user
	 * has (WordPress login sessions and the WorkOS session), then links the WorkOS
	 * user to <to_user_id>. Nothing changes if a check fails.
	 *
	 * When the WorkOS user's email differs from <to_user_id>'s email, pass
	 * --use-wp-email to set the WorkOS email to <to_user_id>'s first, or
	 * --keep-workos-email to keep it (the next sign-in then copies it onto
	 * <to_user_id>).
	 *
	 * ## OPTIONS
	 *
	 * <from_user_id>
	 * : WordPress user that holds the link now.
	 *
	 * <to_user_id>
	 * : WordPress user that should hold it.
	 *
	 * [--use-wp-email]
	 * : Set the WorkOS user's email to <to_user_id>'s email before moving.
	 *
	 * [--keep-workos-email]
	 * : Move without changing the WorkOS user's email.
	 *
	 * [--force]
	 * : Replace a link <to_user_id> already has to a different WorkOS user.
	 *
	 * [--dry-run]
	 * : Show what would change, and change nothing.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user move 245554 425806 --dry-run
	 *     wp workos user move 245554 425806
	 *     wp workos user move 245554 425806 --use-wp-email --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function move( $args, $assoc_args ) {
		$this->require_enabled();

		$from_id     = (int) $args[0];
		$to_id       = (int) $args[1];
		$use_wp      = ! empty( $assoc_args['use-wp-email'] );
		$keep        = ! empty( $assoc_args['keep-workos-email'] );
		$force       = ! empty( $assoc_args['force'] );
		$dry_run     = ! empty( $assoc_args['dry-run'] );
		$from        = get_user_by( 'id', $from_id );
		$to          = get_user_by( 'id', $to_id );
		$workos_id   = $from ? (string) get_user_meta( $from_id, '_workos_user_id', true ) : '';
		$to_link     = $to ? (string) get_user_meta( $to_id, '_workos_user_id', true ) : '';
		$to_inactive = $to && (bool) get_user_meta( $to_id, '_workos_deactivated', true );

		if ( $from_id === $to_id ) {
			WP_CLI::error( 'The two users are the same.' );
		}
		if ( ! $from ) {
			WP_CLI::error( "WordPress user #{$from_id} not found." );
		}
		if ( ! $to ) {
			WP_CLI::error( "WordPress user #{$to_id} not found." );
		}
		if ( '' === $workos_id ) {
			WP_CLI::error( "User #{$from_id} is not linked to WorkOS." );
		}
		if ( $use_wp && $keep ) {
			WP_CLI::error( 'Pass --use-wp-email or --keep-workos-email, not both.' );
		}
		if ( '' !== $to_link && ! $force ) {
			WP_CLI::error( "User #{$to_id} is already linked to WorkOS user {$to_link}. Use --force to replace that link." );
		}
		if ( $to_inactive ) {
			WP_CLI::error( "User #{$to_id} was deactivated when its WorkOS user was deleted. Reactivate it first (`wp workos user link {$to_id} {$workos_id} --force --reactivate` after unlinking #{$from_id})." );
		}

		$workos_user = workos()->api()->get_user( $workos_id );
		if ( is_wp_error( $workos_user ) ) {
			WP_CLI::error( 'Failed to fetch WorkOS user: ' . $workos_user->get_error_message() );
		}

		$workos_email  = (string) ( $workos_user['email'] ?? '' );
		$emails_differ = 0 !== strcasecmp( $workos_email, (string) $to->user_email );
		$change_email  = $emails_differ && $use_wp;

		WP_CLI::log( "WorkOS user: {$workos_id} ({$workos_email})" );
		WP_CLI::log( "From:        #{$from_id} ({$from->user_email}): unlink and end its sessions" );
		WP_CLI::log( "To:          #{$to_id} ({$to->user_email}): link" . ( $to_link ? " (replaces {$to_link}; its sessions end too)" : '' ) );

		if ( $emails_differ ) {
			if ( ! $use_wp && ! $keep ) {
				WP_CLI::error( "The WorkOS email ({$workos_email}) differs from #{$to_id}'s ({$to->user_email}). Pass --use-wp-email to set the WorkOS email to #{$to_id}'s, or --keep-workos-email to keep it (the next sign-in copies it onto #{$to_id})." );
			}
			WP_CLI::log(
				$change_email
					? "Email:       set the WorkOS email to {$to->user_email}"
					: "Email:       keep {$workos_email} in WorkOS; the next sign-in copies it onto #{$to_id}"
			);
		}

		if ( $dry_run ) {
			WP_CLI::success( 'Dry run: nothing changed.' );
			return;
		}

		WP_CLI::confirm( "Move WorkOS user {$workos_id} from #{$from_id} to #{$to_id}?", $assoc_args );

		if ( $change_email ) {
			$updated = workos()->api()->update_user( $workos_id, [ 'email' => $to->user_email ] );
			if ( is_wp_error( $updated ) ) {
				WP_CLI::error( 'Failed to update the WorkOS email, nothing changed: ' . $updated->get_error_message() );
			}
			$workos_user = is_array( $updated ) && ! empty( $updated['id'] ) ? $updated : array_merge( $workos_user, [ 'email' => $to->user_email ] );
		}

		UserSync::unlink_user( $from_id );

		if ( ! UserSync::link_user( $to_id, $workos_user, 'cli_move', true ) ) {
			// Put the link back where it was rather than leave the WorkOS user unlinked.
			UserSync::link_user( $from_id, $workos_user, 'cli_move_rollback', true );
			WP_CLI::error( "Could not link user #{$to_id} to WorkOS user {$workos_id}; the link stays on #{$from_id} (its sessions were ended). See the debug log." );
		}

		\WorkOS\ActivityLog\EventLogger::log(
			'account_moved',
			[
				'user_id'        => $to_id,
				'workos_user_id' => $workos_id,
				'metadata'       => [
					'from_user_id'  => $from_id,
					'email_changed' => $change_email,
				],
			]
		);

		WP_CLI::success( "Moved WorkOS user {$workos_id} from #{$from_id} to #{$to_id}. #{$from_id}'s sessions were ended." );
	}

	/**
	 * Sync a single user between WordPress and WorkOS.
	 *
	 * ## OPTIONS
	 *
	 * <wp_user_id>
	 * : WordPress user ID.
	 *
	 * [--direction=<direction>]
	 * : Sync direction.
	 * ---
	 * default: push
	 * options:
	 *   - push
	 *   - pull
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user sync 1
	 *     wp workos user sync 1 --direction=push
	 *     wp workos user sync 1 --direction=pull
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		$this->require_enabled();

		$wp_user_id = (int) $args[0];
		$direction  = $assoc_args['direction'] ?? 'push';

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			WP_CLI::error( "WordPress user #{$wp_user_id} not found." );
		}

		if ( 'push' === $direction ) {
			$result = UserSync::sync_existing_user( $wp_user_id );
		} else {
			$result = $this->user_sync->resync_from_workos( $wp_user_id );
		}

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Synced user #{$wp_user_id} ({$direction})." );
	}

	/**
	 * Import a single WorkOS user into WordPress.
	 *
	 * Finds an existing WP user by email or creates a new one.
	 *
	 * ## OPTIONS
	 *
	 * <workos_user_id>
	 * : WorkOS user ID.
	 *
	 * [--porcelain]
	 * : Output only the WP user ID.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user import user_01ABC
	 *     wp workos user import user_01ABC --porcelain
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( $args, $assoc_args ) {
		$this->require_enabled();

		$workos_user_id = $args[0];

		$workos_user = workos()->api()->get_user( $workos_user_id );
		if ( is_wp_error( $workos_user ) ) {
			WP_CLI::error( 'Failed to fetch WorkOS user: ' . $workos_user->get_error_message() );
		}

		WP_CLI::confirm(
			sprintf( 'Import WorkOS user %s (%s) into WordPress?', $workos_user_id, $workos_user['email'] ?? 'unknown' ),
			$assoc_args
		);

		$wp_user = UserSync::find_or_create_wp_user( $workos_user, 'cli_import' );
		if ( is_wp_error( $wp_user ) ) {
			WP_CLI::error( $wp_user->get_error_message() );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( (string) $wp_user->ID );
			return;
		}

		WP_CLI::success( "Imported WorkOS user {$workos_user_id} as WordPress user #{$wp_user->ID} ({$wp_user->user_email})." );
	}

	/**
	 * Require that WorkOS is enabled, or exit with error.
	 */
	private function require_enabled(): void {
		if ( ! workos()->is_enabled() ) {
			WP_CLI::error( 'WorkOS is not configured. Run `wp workos status` for details.' );
		}
	}
}
