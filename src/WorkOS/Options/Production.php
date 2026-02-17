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
			'api_key'         => '',
			'client_id'       => '',
			'webhook_secret'  => '',
			'organization_id' => '',
			'environment_id'  => '',
		];
	}
}
