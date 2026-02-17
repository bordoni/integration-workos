<?php
/**
 * Global (non-environment) options.
 *
 * @package WorkOS\Options
 */

namespace WorkOS\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Serialized option row for global plugin settings.
 */
class Global_Options extends Options {

	/**
	 * {@inheritDoc}
	 */
	protected function option_name(): string {
		return 'workos_global';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function defaults(): array {
		return [
			'login_mode'              => 'redirect',
			'allow_password_fallback' => true,
			'deprovision_action'      => 'deactivate',
			'reassign_user'           => 0,
			'role_map'                => [],
			'audit_logging_enabled'   => false,
		];
	}
}
