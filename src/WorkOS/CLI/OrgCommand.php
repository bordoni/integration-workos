<?php
/**
 * CLI Organization Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\Organization\Manager;
use WorkOS\Sync\UserSync;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Manage WorkOS organizations.
 */
class OrgCommand extends \WP_CLI_Command {

	/**
	 * List organizations.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Data source.
	 * ---
	 * default: local
	 * options:
	 *   - local
	 *   - remote
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
	 *     wp workos org list
	 *     wp workos org list --source=remote
	 *     wp workos org list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) {
		$source = $assoc_args['source'] ?? 'local';

		if ( 'remote' === $source ) {
			$this->require_enabled();

			$result = workos()->api()->list_organizations();
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( 'Failed to fetch organizations: ' . $result->get_error_message() );
			}

			$rows = array_map(
				static function ( $org ) {
					return [
						'id'   => $org['id'] ?? '',
						'name' => $org['name'] ?? '',
					];
				},
				$result['data'] ?? []
			);

			$formatter = new Formatter( $assoc_args, [ 'id', 'name' ] );
			$formatter->display_items( $rows );
			return;
		}

		$orgs = Manager::get_for_site( get_current_blog_id() );

		$rows = array_map(
			static function ( $org ) {
				return [
					'id'            => $org->id,
					'workos_org_id' => $org->workos_org_id,
					'name'          => $org->name,
					'slug'          => $org->slug,
					'created_at'    => $org->created_at,
					'updated_at'    => $org->updated_at,
				];
			},
			$orgs
		);

		$formatter = new Formatter(
			$assoc_args,
			[ 'id', 'workos_org_id', 'name', 'slug', 'created_at', 'updated_at' ]
		);

		$formatter->display_items( $rows );
	}

	/**
	 * Get a single organization.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The organization identifier.
	 *
	 * [--by=<field>]
	 * : Field to look up by.
	 * ---
	 * default: id
	 * options:
	 *   - id
	 *   - workos_id
	 *   - remote
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
	 *     wp workos org get 1
	 *     wp workos org get org_01ABC --by=workos_id
	 *     wp workos org get org_01ABC --by=remote
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function get( $args, $assoc_args ) {
		$identifier = $args[0];
		$by         = $assoc_args['by'] ?? 'id';

		if ( 'remote' === $by ) {
			$this->require_enabled();

			$result = workos()->api()->get_organization( $identifier );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( 'Failed to fetch organization: ' . $result->get_error_message() );
			}

			$row = [
				[
					'id'   => $result['id'] ?? '',
					'name' => $result['name'] ?? '',
				],
			];

			$formatter = new Formatter( $assoc_args, [ 'id', 'name' ] );
			$formatter->display_items( $row );
			return;
		}

		if ( 'workos_id' === $by ) {
			$org = Manager::get_by_workos_id( $identifier );
		} else {
			$org = Manager::get( (int) $identifier );
		}

		if ( ! $org ) {
			WP_CLI::error( 'Organization not found.' );
		}

		$row = [
			[
				'id'            => $org->id,
				'workos_org_id' => $org->workos_org_id,
				'name'          => $org->name,
				'slug'          => $org->slug,
				'domains'       => $org->domains ?? '',
				'created_at'    => $org->created_at,
				'updated_at'    => $org->updated_at,
			],
		];

		$formatter = new Formatter(
			$assoc_args,
			[ 'id', 'workos_org_id', 'name', 'slug', 'domains', 'created_at', 'updated_at' ]
		);

		$formatter->display_items( $row );
	}

	/**
	 * Sync an organization from WorkOS API to the local database.
	 *
	 * ## OPTIONS
	 *
	 * <workos_org_id>
	 * : WorkOS organization ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos org sync org_01ABC
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync( $args, $assoc_args ) {
		$this->require_enabled();

		$workos_org_id = $args[0];

		$result = workos()->api()->get_organization( $workos_org_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Failed to fetch organization: ' . $result->get_error_message() );
		}

		$local_id = Manager::upsert_organization( $result );
		if ( ! $local_id ) {
			WP_CLI::error( 'Failed to upsert organization locally.' );
		}

		WP_CLI::success( "Synced organization {$workos_org_id} (local ID: {$local_id})." );
	}

	/**
	 * List members of an organization.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Organization identifier.
	 *
	 * [--by=<field>]
	 * : Field to look up organization by.
	 * ---
	 * default: id
	 * options:
	 *   - id
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
	 *     wp workos org members 1
	 *     wp workos org members org_01ABC --by=workos_id
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function members( $args, $assoc_args ) {
		$identifier = $args[0];
		$by         = $assoc_args['by'] ?? 'id';

		if ( 'workos_id' === $by ) {
			$org = Manager::get_by_workos_id( $identifier );
		} else {
			$org = Manager::get( (int) $identifier );
		}

		if ( ! $org ) {
			WP_CLI::error( 'Organization not found.' );
		}

		global $wpdb;
		$mem_table  = $wpdb->prefix . 'workos_org_memberships';
		$user_table = $wpdb->users;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$members = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT u.ID as user_id, u.user_login, u.user_email, m.workos_role, m.wp_role, m.joined_at
				FROM {$mem_table} m
				INNER JOIN {$user_table} u ON m.user_id = u.ID
				WHERE m.org_id = %d
				ORDER BY m.joined_at",
				$org->id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = array_map(
			static function ( $m ) {
				$user = get_user_by( 'id', $m->user_id );
				return [
					'user_id'     => $m->user_id,
					'user_login'  => $m->user_login,
					'user_email'  => $m->user_email,
					'workos_role' => $m->workos_role,
					'wp_role'     => $user ? implode( ', ', $user->roles ) : $m->wp_role,
					'joined_at'   => $m->joined_at,
				];
			},
			$members
		);

		$formatter = new Formatter(
			$assoc_args,
			[ 'user_id', 'user_login', 'user_email', 'workos_role', 'wp_role', 'joined_at' ]
		);

		$formatter->display_items( $rows );
	}

	/**
	 * Add a WordPress user to a local organization.
	 *
	 * ## OPTIONS
	 *
	 * <org_id>
	 * : Local organization ID.
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--role=<role>]
	 * : Organization role.
	 * ---
	 * default: member
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos org add-member 1 42
	 *     wp workos org add-member 1 42 --role=admin
	 *
	 * @subcommand add-member
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function add_member( $args, $assoc_args ) {
		$org_id  = (int) $args[0];
		$user_id = (int) $args[1];
		$role    = $assoc_args['role'] ?? 'member';

		$org = Manager::get( $org_id );
		if ( ! $org ) {
			WP_CLI::error( "Organization #{$org_id} not found." );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			WP_CLI::error( "WordPress user #{$user_id} not found." );
		}

		Manager::add_membership( $org_id, $user_id, [ 'workos_role' => $role ] );
		WP_CLI::success( "Added user #{$user_id} ({$user->user_email}) to organization '{$org->name}' with role '{$role}'." );
	}

	/**
	 * Remove a WordPress user from a local organization.
	 *
	 * ## OPTIONS
	 *
	 * <org_id>
	 * : Local organization ID.
	 *
	 * <user_id>
	 * : WordPress user ID.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp workos org remove-member 1 42
	 *     wp workos org remove-member 1 42 --yes
	 *
	 * @subcommand remove-member
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function remove_member( $args, $assoc_args ) {
		$org_id  = (int) $args[0];
		$user_id = (int) $args[1];

		$org = Manager::get( $org_id );
		if ( ! $org ) {
			WP_CLI::error( "Organization #{$org_id} not found." );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			WP_CLI::error( "WordPress user #{$user_id} not found." );
		}

		WP_CLI::confirm( "Remove user #{$user_id} ({$user->user_email}) from organization '{$org->name}'?", $assoc_args );

		Manager::remove_membership( $org_id, $user_id );
		WP_CLI::success( "Removed user #{$user_id} from organization '{$org->name}'." );
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
