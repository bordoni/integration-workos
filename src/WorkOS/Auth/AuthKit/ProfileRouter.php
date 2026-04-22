<?php
/**
 * Profile routing rules — maps incoming requests to a Login Profile.
 *
 * @package WorkOS\Auth\AuthKit
 */

namespace WorkOS\Auth\AuthKit;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves which Login Profile applies to a given request context.
 *
 * Order of resolution:
 *
 * 1. Explicit slug provided by caller (shortcode/block/route param). Always wins.
 * 2. Admin-defined routing rules, evaluated top-down; first match wins.
 *    Rule shape: `{ profile: slug, matcher: { type, value } }`
 *    Matcher types:
 *      - `redirect_to`: glob-match against the request's `redirect_to` path.
 *      - `referrer_host`: exact host of the Referer header.
 *      - `user_role`: current user has this role (re-auth scenarios).
 * 3. The reserved `default` profile.
 *
 * Rules live under the `workos_profile_routing_rules` option as an ordered
 * array. The admin UI (task #17) owns editing + reordering.
 */
class ProfileRouter {

	public const OPTION = 'workos_profile_routing_rules';

	/**
	 * Profile repository.
	 *
	 * @var ProfileRepository
	 */
	private ProfileRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ProfileRepository $repository Profile repository.
	 */
	public function __construct( ProfileRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Resolve a profile for the current request.
	 *
	 * @param array{
	 *     explicit_slug?: string,
	 *     redirect_to?: string,
	 *     referrer?: string,
	 *     user_id?: int,
	 * } $context Request context.
	 *
	 * @return Profile The matched profile, or the default profile (creating
	 *                 it when absent so callers can render something sane).
	 */
	public function resolve( array $context ): Profile {
		// 1. Explicit slug wins.
		if ( ! empty( $context['explicit_slug'] ) ) {
			$profile = $this->repository->find_by_slug( (string) $context['explicit_slug'] );
			if ( $profile ) {
				return $profile;
			}
		}

		// 2. Rules.
		$rule = $this->match_rule( $context );
		if ( null !== $rule ) {
			$profile = $this->repository->find_by_slug( (string) ( $rule['profile'] ?? '' ) );
			if ( $profile ) {
				return $profile;
			}
		}

		// 3. Default — ensure it exists before returning.
		return $this->repository->ensure_default();
	}

	/**
	 * Get raw routing rules.
	 *
	 * @return array<int, array{profile: string, matcher: array{type: string, value: string}}>
	 */
	public function get_rules(): array {
		$raw = get_option( self::OPTION, [] );
		return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : [];
	}

	/**
	 * Replace the routing rules.
	 *
	 * Validates each rule and drops malformed entries.
	 *
	 * @param array $rules New rules list.
	 *
	 * @return array The validated rules that were persisted.
	 */
	public function set_rules( array $rules ): array {
		$valid_types = [ 'redirect_to', 'referrer_host', 'user_role' ];
		$validated   = [];

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$profile = sanitize_title( (string) ( $rule['profile'] ?? '' ) );
			if ( '' === $profile ) {
				continue;
			}

			$matcher = is_array( $rule['matcher'] ?? null ) ? $rule['matcher'] : [];
			$type    = (string) ( $matcher['type'] ?? '' );
			$value   = (string) ( $matcher['value'] ?? '' );

			if ( ! in_array( $type, $valid_types, true ) || '' === $value ) {
				continue;
			}

			$validated[] = [
				'profile' => $profile,
				'matcher' => [
					'type'  => $type,
					'value' => sanitize_text_field( $value ),
				],
			];
		}

		update_option( self::OPTION, $validated );

		return $validated;
	}

	/**
	 * Find the first rule that matches the given context.
	 *
	 * @param array $context Request context.
	 *
	 * @return array|null Matched rule, or null when nothing matches.
	 */
	private function match_rule( array $context ): ?array {
		foreach ( $this->get_rules() as $rule ) {
			$matcher = $rule['matcher'] ?? [];
			$type    = (string) ( $matcher['type'] ?? '' );
			$value   = (string) ( $matcher['value'] ?? '' );

			if ( $this->matches( $type, $value, $context ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Evaluate a single matcher.
	 *
	 * @param string $type    Matcher type.
	 * @param string $value   Matcher value.
	 * @param array  $context Request context.
	 *
	 * @return bool
	 */
	private function matches( string $type, string $value, array $context ): bool {
		switch ( $type ) {
			case 'redirect_to':
				$redirect = (string) ( $context['redirect_to'] ?? '' );
				if ( '' === $redirect ) {
					return false;
				}
				return $this->path_matches_glob( $redirect, $value );

			case 'referrer_host':
				$referrer = (string) ( $context['referrer'] ?? '' );
				if ( '' === $referrer ) {
					return false;
				}
				$host = wp_parse_url( $referrer, PHP_URL_HOST );
				return is_string( $host ) && strtolower( $host ) === strtolower( $value );

			case 'user_role':
				$user_id = (int) ( $context['user_id'] ?? 0 );
				if ( $user_id <= 0 ) {
					return false;
				}
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					return false;
				}
				return in_array( $value, (array) $user->roles, true );
		}

		return false;
	}

	/**
	 * Glob-match a request path against a pattern.
	 *
	 * Supports `*` as a wildcard; treats everything else as a literal.
	 *
	 * @param string $target  Path or URL from the request.
	 * @param string $pattern Rule value.
	 *
	 * @return bool
	 */
	private function path_matches_glob( string $target, string $pattern ): bool {
		$path = wp_parse_url( $target, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = $target;
		}

		$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';

		return (bool) preg_match( $regex, $path );
	}
}
