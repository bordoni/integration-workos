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
			'diagnostics_results'  => [],
			'diagnostics_last_run' => 0,
		];
	}
}
