<?php
/**
 * Abstract base for serialized array options.
 *
 * @package WorkOS\Options
 */

namespace WorkOS\Options;

use WorkOS\Vendor\StellarWP\Arrays\Arr;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for storing plugin settings as a single serialized wp_options row.
 */
abstract class Options {

	/**
	 * The wp_options row name.
	 *
	 * @return string
	 */
	abstract protected function option_name(): string;

	/**
	 * Default values for all keys in this option group.
	 *
	 * @return array
	 */
	abstract protected function defaults(): array;

	/**
	 * In-memory cache of the option array.
	 *
	 * @var array|null
	 */
	private ?array $options = null;

	/**
	 * Get a single value by key.
	 *
	 * @param string $key     Dot-notation key.
	 * @param mixed  $default Fallback (uses defaults() when null).
	 *
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$resolved = $default ?? Arr::get( $this->defaults(), $key );
		return Arr::get( $this->all(), $key, $resolved );
	}

	/**
	 * Set a single value by key and persist.
	 *
	 * @param string $key   Dot-notation key.
	 * @param mixed  $value Value to store.
	 */
	public function set( string $key, $value ): void {
		$this->options = Arr::set( $this->all(), $key, $value );
		$this->save();
	}

	/**
	 * Delete a key and persist.
	 *
	 * @param string $key Key to remove.
	 */
	public function delete( string $key ): void {
		$options = $this->all();
		unset( $options[ $key ] );
		$this->options = $options;
		$this->save();
	}

	/**
	 * Get the full options array (lazy-loaded from DB).
	 *
	 * @return array
	 */
	public function all(): array {
		if ( null === $this->options ) {
			$stored        = get_option( $this->option_name(), [] );
			$this->options = is_array( $stored ) ? $stored : [];
		}
		return $this->options;
	}

	/**
	 * Persist the current options to the database.
	 */
	protected function save(): void {
		update_option( $this->option_name(), $this->options ?? [] );
	}

	/**
	 * Clear the in-memory cache so the next read fetches from DB.
	 */
	public function reset(): void {
		$this->options = null;
	}
}
