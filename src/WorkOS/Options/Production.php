<?php
/**
 * Production environment options.
 *
 * @package WorkOS\Options
 */

namespace WorkOS\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Serialized option row for production credentials.
 */
class Production extends Options {

	/**
	 * {@inheritDoc}
	 */
	protected function option_name(): string {
		return 'workos_production';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function defaults(): array {
		return [
			'api_key'                 => '',
			'client_id'               => '',
			'webhook_secret'          => '',
			'organization_id'         => '',
			'environment_id'          => '',
			'login_mode'              => 'redirect',
			'allow_password_fallback' => true,
			'deprovision_action'      => 'deactivate',
			'reassign_user'           => 0,
			'role_map'                    => [],
			'redirect_urls'               => [],
			'redirect_first_login_only'   => true,
			'audit_logging_enabled'       => false,
		];
	}
}
