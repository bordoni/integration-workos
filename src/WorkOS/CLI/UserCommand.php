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
	 * Validates the WorkOS user exists via API before linking.
	 *
	 * ## OPTIONS
	 *
	 * <wp_user_id>
	 * : WordPress user ID.
	 *
	 * <workos_user_id>
	 * : WorkOS user ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos user link 1 user_01ABC
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function link( $args, $assoc_args ) {
		$this->require_enabled();

		$wp_user_id     = (int) $args[0];
		$workos_user_id = $args[1];

		$user = get_user_by( 'id', $wp_user_id );
		if ( ! $user ) {
			WP_CLI::error( "WordPress user #{$wp_user_id} not found." );
		}

		$existing_link = get_user_meta( $wp_user_id, '_workos_user_id', true );
		if ( $existing_link ) {
			WP_CLI::error( "User #{$wp_user_id} is already linked to WorkOS ID: {$existing_link}" );
		}

		// Validate the WorkOS user exists.
		$workos_user = workos()->api()->get_user( $workos_user_id );
		if ( is_wp_error( $workos_user ) ) {
			WP_CLI::error( 'Failed to fetch WorkOS user: ' . $workos_user->get_error_message() );
		}

		UserSync::link_user( $wp_user_id, $workos_user );
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
		WP_CLI::success( "Unlinked WordPress user #{$wp_user_id} from WorkOS." );
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
			$result = UserSync::resync_from_workos( $wp_user_id );
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

		$wp_user = UserSync::find_or_create_wp_user( $workos_user );
		if ( is_wp_error( $wp_user ) ) {
			WP_CLI::error( $wp_user->get_error_message() );
		}

		if ( isset( $assoc_args['porcelain'] ) ) {
			WP_CLI::line( $wp_user->ID );
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
