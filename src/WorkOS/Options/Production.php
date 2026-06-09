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
			'api_key'                                 => '',
			'client_id'                               => '',
			'webhook_secret'                          => '',
			'organization_id'                         => '',
			'environment_id'                          => '',
			'login_mode'                              => 'custom',
			'allow_password_fallback'                 => true,
			'wp_password_fallback_email_confirmation' => true,
			'deprovision_action'                      => 'deactivate',
			'reassign_user'                           => 0,
			'role_map'                                => [],
			'redirect_urls'                           => [],
			'redirect_first_login_only'               => true,
			'logout_redirect_urls'                    => [],
			'audit_logging_enabled'                   => false,
			// Change-email feature (see src/WorkOS/Auth/ChangeEmail).
			'change_email_enabled'                    => true,
			'change_email_conflict_policy'            => 'block',
			'change_email_token_lifetime'             => 3600,
			'change_email_rate_limit_user_count'      => 3,
			'change_email_rate_limit_user_window'     => 3600,
			'change_email_rate_limit_ip_count'        => 10,
			'change_email_rate_limit_ip_window'       => 3600,
			'change_email_notify_old_address'         => true,
			'change_email_require_reauth'             => true,
			'change_email_admin_bypass_verification'  => false,
			'change_email_confirm_path'               => 'workos/change-email',
		];
	}
}
