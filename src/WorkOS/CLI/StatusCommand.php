<?php
/**
 * CLI Status Command
 *
 * @package WorkOS\CLI
 */

namespace WorkOS\CLI;

use WorkOS\Config;
use WP_CLI;
use WP_CLI\Formatter;

/**
 * Show WorkOS plugin configuration and health.
 */
class StatusCommand extends \WP_CLI_Command {

	/**
	 * Display plugin configuration and health status.
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
	 *     wp workos status
	 *     wp workos status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function __invoke( $args, $assoc_args ) {
		$rows = [
			[
				'setting' => 'plugin_version',
				'value'   => defined( 'WORKOS_VERSION' ) ? WORKOS_VERSION : 'unknown',
				'source'  => 'constant',
			],
			[
				'setting' => 'environment',
				'value'   => Config::get_active_environment(),
				'source'  => Config::is_environment_overridden() ? 'constant' : 'database',
			],
			[
				'setting' => 'api_key',
				'value'   => Config::mask_secret( Config::get_api_key() ),
				'source'  => Config::is_overridden( 'api_key' ) ? 'constant' : 'database',
			],
			[
				'setting' => 'client_id',
				'value'   => Config::get_client_id(),
				'source'  => Config::is_overridden( 'client_id' ) ? 'constant' : 'database',
			],
			[
				'setting' => 'organization_id',
				'value'   => Config::get_organization_id(),
				'source'  => Config::is_overridden( 'organization_id' ) ? 'constant' : 'database',
			],
			[
				'setting' => 'environment_id',
				'value'   => Config::get_environment_id(),
				'source'  => Config::is_overridden( 'environment_id' ) ? 'constant' : 'database',
			],
			[
				'setting' => 'enabled',
				'value'   => workos()->is_enabled() ? 'yes' : 'no',
				'source'  => 'computed',
			],
			[
				'setting' => 'login_mode',
				'value'   => workos()->option( 'login_mode', 'redirect' ),
				'source'  => 'database',
			],
			[
				'setting' => 'db_version',
				'value'   => get_option( 'workos_db_version', 'none' ),
				'source'  => 'database',
			],
		];

		$formatter = new Formatter(
			$assoc_args,
			[ 'setting', 'value', 'source' ]
		);

		$formatter->display_items( $rows );
	}
}
