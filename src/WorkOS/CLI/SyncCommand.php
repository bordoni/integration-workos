<?php
/**
 * CLI Sync Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\Organization\Manager;
use WorkOS\Sync\UserSync;
use WP_CLI;

/**
 * Bulk sync operations between WordPress and WorkOS.
 */
class SyncCommand extends \WP_CLI_Command {

	/**
	 * Push unlinked WordPress users to WorkOS.
	 *
	 * Creates each user in WorkOS and links the accounts.
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : Only push users with this WordPress role.
	 *
	 * [--limit=<n>]
	 * : Maximum number of users to push.
	 *
	 * [--dry-run]
	 * : Show what would happen without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos sync push --yes
	 *     wp workos sync push --role=subscriber --limit=10
	 *     wp workos sync push --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function push( $args, $assoc_args ) {
		$this->require_enabled();

		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$query_args = [
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => '_workos_user_id',
					'compare' => 'NOT EXISTS',
				],
			],
			'number'     => $limit ? $limit : 0,
			'fields'     => 'ID',
		];

		if ( ! empty( $assoc_args['role'] ) ) {
			$query_args['role'] = $assoc_args['role'];
		}

		$user_ids = get_users( $query_args );
		$total    = count( $user_ids );

		if ( 0 === $total ) {
			WP_CLI::success( 'No unlinked users found.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( "Dry run: would push {$total} unlinked user(s) to WorkOS." );
			return;
		}

		WP_CLI::confirm( "Push {$total} unlinked user(s) to WorkOS?", $assoc_args );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Pushing users', $total );
		$synced   = 0;
		$failed   = 0;
		$skipped  = 0;

		foreach ( $user_ids as $user_id ) {
			$result = UserSync::sync_existing_user( (int) $user_id );

			if ( is_wp_error( $result ) ) {
				if ( 'workos_already_linked' === $result->get_error_code() ) {
					++$skipped;
				} else {
					WP_CLI::warning( "User #{$user_id}: " . $result->get_error_message() );
					++$failed;
				}
			} else {
				++$synced;
			}

			$progress->tick();
		}

		$progress->finish();
		WP_CLI::success( "{$synced} synced, {$failed} failed, {$skipped} skipped." );
	}

	/**
	 * Pull (re-sync) all linked WordPress users from WorkOS.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of users to pull.
	 *
	 * [--dry-run]
	 * : Show what would happen without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos sync pull --yes
	 *     wp workos sync pull --limit=50
	 *     wp workos sync pull --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function pull( $args, $assoc_args ) {
		$this->require_enabled();

		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		$user_ids = get_users(
			[
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_workos_user_id',
						'compare' => 'EXISTS',
					],
				],
				'number'     => $limit ? $limit : 0,
				'fields'     => 'ID',
			]
		);

		$total = count( $user_ids );

		if ( 0 === $total ) {
			WP_CLI::success( 'No linked users found.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( "Dry run: would re-sync {$total} linked user(s) from WorkOS." );
			return;
		}

		WP_CLI::confirm( "Re-sync {$total} linked user(s) from WorkOS?", $assoc_args );

		$progress = \WP_CLI\Utils\make_progress_bar( 'Pulling users', $total );
		$synced   = 0;
		$failed   = 0;
		$skipped  = 0;

		foreach ( $user_ids as $user_id ) {
			$result = UserSync::resync_from_workos( (int) $user_id );

			if ( is_wp_error( $result ) ) {
				if ( 'workos_not_linked' === $result->get_error_code() ) {
					++$skipped;
				} else {
					WP_CLI::warning( "User #{$user_id}: " . $result->get_error_message() );
					++$failed;
				}
			} else {
				++$synced;
			}

			$progress->tick();
		}

		$progress->finish();
		WP_CLI::success( "{$synced} synced, {$failed} failed, {$skipped} skipped." );
	}

	/**
	 * Import WorkOS users into WordPress.
	 *
	 * Fetches users from the WorkOS API and creates/links local WordPress accounts.
	 *
	 * ## OPTIONS
	 *
	 * [--organization_id=<id>]
	 * : Only import users from this WorkOS organization.
	 *
	 * [--limit=<n>]
	 * : Maximum number of users to import.
	 *
	 * [--dry-run]
	 * : Show what would happen without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos sync import --yes
	 *     wp workos sync import --organization_id=org_01ABC --limit=10
	 *     wp workos sync import --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( $args, $assoc_args ) {
		$this->require_enabled();

		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		if ( ! $dry_run ) {
			WP_CLI::confirm( 'Import WorkOS users into WordPress?', $assoc_args );
		}

		$params = [ 'limit' => 100 ];
		if ( ! empty( $assoc_args['organization_id'] ) ) {
			$params['organization_id'] = $assoc_args['organization_id'];
		}

		$imported       = 0;
		$failed         = 0;
		$skipped        = 0;
		$cursor         = null;
		$header_printed = false;

		do {
			if ( $cursor ) {
				$params['after'] = $cursor;
			}

			$result = workos()->api()->list_users( $params );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( 'Failed to list WorkOS users: ' . $result->get_error_message() );
			}

			$users  = $result['data'] ?? [];
			$cursor = $result['list_metadata']['after'] ?? null;

			if ( empty( $users ) ) {
				break;
			}

			if ( ! $header_printed ) {
				WP_CLI::log( 'Importing WorkOS users...' );
				$header_printed = true;
			}

			foreach ( $users as $workos_user ) {
				$workos_id = $workos_user['id'] ?? '';
				if ( empty( $workos_id ) ) {
					++$skipped;
					continue;
				}

				// Skip users already linked.
				$existing = UserSync::get_wp_user_id_by_workos_id( $workos_id );
				if ( $existing ) {
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					WP_CLI::log( "Would import: {$workos_id} ({$workos_user['email']})" );
					++$imported;
				} else {
					$wp_user = UserSync::find_or_create_wp_user( $workos_user );
					if ( is_wp_error( $wp_user ) ) {
						WP_CLI::warning( "User {$workos_id}: " . $wp_user->get_error_message() );
						++$failed;
					} else {
						++$imported;
					}
				}

				if ( $limit && ( $imported + $failed + $skipped ) >= $limit ) {
					break 2;
				}
			}
		} while ( $cursor );

		$action = $dry_run ? 'Would import' : 'Imported';
		WP_CLI::success( "{$action}: {$imported} imported, {$failed} failed, {$skipped} skipped." );
	}

	/**
	 * Import all organizations from WorkOS.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Maximum number of organizations to import.
	 *
	 * [--dry-run]
	 * : Show what would happen without making changes.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos sync orgs --yes
	 *     wp workos sync orgs --limit=10
	 *     wp workos sync orgs --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function orgs( $args, $assoc_args ) {
		$this->require_enabled();

		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;

		if ( ! $dry_run ) {
			WP_CLI::confirm( 'Import all organizations from WorkOS?', $assoc_args );
		}

		$params   = [ 'limit' => 100 ];
		$imported = 0;
		$failed   = 0;
		$skipped  = 0;
		$cursor   = null;

		do {
			if ( $cursor ) {
				$params['after'] = $cursor;
			}

			$result = workos()->api()->list_organizations( $params );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( 'Failed to list organizations: ' . $result->get_error_message() );
			}

			$orgs   = $result['data'] ?? [];
			$cursor = $result['list_metadata']['after'] ?? null;

			if ( empty( $orgs ) ) {
				break;
			}

			WP_CLI::log( 'Importing organizations...' );

			foreach ( $orgs as $org_data ) {
				$org_id = $org_data['id'] ?? '';
				if ( empty( $org_id ) ) {
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					WP_CLI::log( "Would import: {$org_id} ({$org_data['name']})" );
					++$imported;
				} else {
					$local_id = Manager::upsert_organization( $org_data );
					if ( ! $local_id ) {
						WP_CLI::warning( "Organization {$org_id}: failed to upsert." );
						++$failed;
					} else {
						++$imported;
					}
				}

				if ( $limit && ( $imported + $failed + $skipped ) >= $limit ) {
					break 2;
				}
			}
		} while ( $cursor );

		$action = $dry_run ? 'Would import' : 'Imported';
		WP_CLI::success( "{$action}: {$imported} synced, {$failed} failed, {$skipped} skipped." );
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
