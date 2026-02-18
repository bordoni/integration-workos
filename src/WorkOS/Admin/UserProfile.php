<?php
/**
 * WorkOS section on the user edit page.
 *
 * Shows WorkOS user data, organization memberships, and recent events
 * for users linked to a WorkOS account.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

use WorkOS\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "WorkOS" section to the WordPress user edit page.
 */
class UserProfile {

	/**
	 * Transient cache TTL in seconds (5 minutes).
	 */
	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Maximum number of events to display.
	 */
	private const MAX_EVENTS = 25;

	/**
	 * Maximum pages of events to scan from the API.
	 */
	private const MAX_EVENT_PAGES = 3;

	/**
	 * Events per API page.
	 */
	private const EVENTS_PER_PAGE = 100;

	/**
	 * Event types relevant to users.
	 */
	private const USER_EVENT_TYPES = [
		'user.created',
		'user.updated',
		'user.deleted',
		'authentication.email_verification_succeeded',
		'organization_membership.created',
		'organization_membership.updated',
		'organization_membership.deleted',
		'session.created',
	];

	/**
	 * Constructor — registers hooks.
	 */
	public function __construct() {
		add_action( 'show_user_profile', [ $this, 'render' ] );
		add_action( 'edit_user_profile', [ $this, 'render' ] );
	}

	/**
	 * Render the WorkOS section on the user profile page.
	 *
	 * @param \WP_User $user The user being edited.
	 */
	public function render( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$workos_user_id = get_user_meta( $user->ID, '_workos_user_id', true );

		if ( empty( $workos_user_id ) ) {
			return;
		}

		$local_meta     = $this->get_local_meta( $user->ID );
		$environment_id = Config::get_environment_id();
		$plugin_enabled = workos()->is_enabled();

		echo '<h2>' . esc_html__( 'WorkOS', 'workos' ) . '</h2>';

		$this->render_dashboard_link( $workos_user_id, $environment_id );

		// User Information.
		$workos_user = null;
		if ( $plugin_enabled ) {
			$workos_user = $this->get_cached_workos_user( $workos_user_id );
		}

		$this->render_user_info( $workos_user, $local_meta, $environment_id );

		// Organization Memberships.
		if ( $plugin_enabled ) {
			$memberships = $this->get_cached_memberships( $workos_user_id );
			$this->render_memberships( $memberships, $environment_id );
		}

		// Recent Events.
		if ( $plugin_enabled && ! empty( $local_meta['org_id'] ) ) {
			$events = $this->get_cached_events( $workos_user_id, $local_meta['org_id'] );
			$this->render_events( $events );
		}
	}

	/**
	 * Render the "View in WorkOS Dashboard" button.
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 * @param string $environment_id WorkOS environment ID.
	 */
	private function render_dashboard_link( string $workos_user_id, string $environment_id ): void {
		if ( empty( $environment_id ) ) {
			return;
		}

		$dashboard_url = sprintf(
			'https://dashboard.workos.com/%s/users/%s/details',
			rawurlencode( $environment_id ),
			rawurlencode( $workos_user_id )
		);

		printf(
			'<p><a href="%s" class="button" target="_blank" rel="noopener noreferrer">%s</a></p>',
			esc_url( $dashboard_url ),
			esc_html__( 'View in WorkOS Dashboard', 'workos' ) . ' &#x2197;'
		);
	}

	/**
	 * Render the User Information table.
	 *
	 * @param array|null $workos_user    WorkOS user data from the API (null if unavailable).
	 * @param array      $local_meta     Local user meta.
	 * @param string     $environment_id WorkOS environment ID.
	 */
	private function render_user_info( ?array $workos_user, array $local_meta, string $environment_id ): void {
		echo '<h3>' . esc_html__( 'User Information', 'workos' ) . '</h3>';

		if ( null === $workos_user ) {
			if ( workos()->is_enabled() ) {
				echo '<p class="description">' . esc_html__( 'Could not fetch user data from WorkOS API.', 'workos' ) . '</p>';
			}
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		// WorkOS User ID.
		$this->render_form_row(
			__( 'WorkOS User ID', 'workos' ),
			'<code>' . esc_html( $local_meta['user_id'] ) . '</code>'
		);

		// WorkOS Org ID.
		if ( ! empty( $local_meta['org_id'] ) ) {
			$org_value = '<code>' . esc_html( $local_meta['org_id'] ) . '</code>';

			if ( ! empty( $environment_id ) ) {
				$org_dashboard_url = sprintf(
					'https://dashboard.workos.com/%s/organizations/%s',
					rawurlencode( $environment_id ),
					rawurlencode( $local_meta['org_id'] )
				);
				$org_value = '<code><a href="' . esc_url( $org_dashboard_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $local_meta['org_id'] ) . '</a></code>';
			}

			$this->render_form_row( __( 'Organization ID', 'workos' ), $org_value );
		}

		// API-sourced data.
		if ( $workos_user ) {
			// Email.
			$email_html = esc_html( $workos_user['email'] ?? '' );
			if ( ! empty( $workos_user['email_verified'] ) ) {
				$email_html .= ' <span style="color:#00a32a;" title="' . esc_attr__( 'Verified', 'workos' ) . '">&#10003;</span>';
			}
			$this->render_form_row( __( 'Email', 'workos' ), $email_html );

			// Name.
			$name_parts = array_filter( [
				$workos_user['first_name'] ?? '',
				$workos_user['last_name'] ?? '',
			] );
			if ( $name_parts ) {
				$this->render_form_row( __( 'Name', 'workos' ), esc_html( implode( ' ', $name_parts ) ) );
			}

			// Profile Picture.
			if ( ! empty( $workos_user['profile_picture_url'] ) ) {
				$this->render_form_row(
					__( 'Profile Picture', 'workos' ),
					'<img src="' . esc_url( $workos_user['profile_picture_url'] ) . '" alt="" style="width:64px;height:64px;border-radius:50%;" />'
				);
			}

			// Created At.
			if ( ! empty( $workos_user['created_at'] ) ) {
				$this->render_form_row( __( 'Created At', 'workos' ), $this->format_datetime( $workos_user['created_at'] ) );
			}

			// Updated At.
			if ( ! empty( $workos_user['updated_at'] ) ) {
				$this->render_form_row( __( 'Updated At', 'workos' ), $this->format_datetime( $workos_user['updated_at'] ) );
			}

			// Last Active At.
			if ( ! empty( $workos_user['last_active_at'] ) ) {
				$this->render_form_row( __( 'Last Active At', 'workos' ), $this->format_datetime( $workos_user['last_active_at'] ) );
			}
		}

		// Local meta: Last Synced.
		if ( ! empty( $local_meta['last_synced_at'] ) ) {
			$this->render_form_row( __( 'Last Synced', 'workos' ), $this->format_datetime( $local_meta['last_synced_at'] ) );
		}

		// Local meta: Deprovisioned.
		if ( ! empty( $local_meta['deactivated'] ) ) {
			$this->render_form_row(
				__( 'Status', 'workos' ),
				'<span style="color:#d63638;">' . esc_html__( 'Deprovisioned', 'workos' ) . '</span>'
			);
		}

		// Local meta: First Login.
		if ( ! empty( $local_meta['first_login'] ) ) {
			$this->render_form_row( __( 'First Login', 'workos' ), $this->format_datetime( $local_meta['first_login'] ) );
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the Organization Memberships table.
	 *
	 * @param array|null $memberships    Membership data from the API (null on error).
	 * @param string     $environment_id WorkOS environment ID.
	 */
	private function render_memberships( ?array $memberships, string $environment_id ): void {
		echo '<h3>' . esc_html__( 'Organization Memberships', 'workos' ) . '</h3>';

		if ( null === $memberships ) {
			echo '<p class="description">' . esc_html__( 'Could not fetch organization memberships from WorkOS API.', 'workos' ) . '</p>';
			return;
		}

		$items = $memberships['data'] ?? [];

		if ( empty( $items ) ) {
			echo '<p class="description">' . esc_html__( 'No organization memberships found.', 'workos' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Organization ID', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Role', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Created At', 'workos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $items as $membership ) {
			$org_id = $membership['organization_id'] ?? '';
			echo '<tr>';

			// Org ID (linked to dashboard if environment_id available).
			echo '<td>';
			if ( $org_id && $environment_id ) {
				$org_url = sprintf(
					'https://dashboard.workos.com/%s/organizations/%s',
					rawurlencode( $environment_id ),
					rawurlencode( $org_id )
				);
				echo '<code><a href="' . esc_url( $org_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $org_id ) . '</a></code>';
			} else {
				echo '<code>' . esc_html( $org_id ) . '</code>';
			}
			echo '</td>';

			echo '<td>' . esc_html( $membership['role']['slug'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $membership['status'] ?? '' ) . '</td>';
			echo '<td>' . ( ! empty( $membership['created_at'] ) ? esc_html( $this->format_datetime( $membership['created_at'] ) ) : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the Recent Events table.
	 *
	 * @param array|null $events Filtered events (null on error).
	 */
	private function render_events( ?array $events ): void {
		echo '<h3>' . esc_html__( 'Recent Events', 'workos' ) . '</h3>';

		if ( null === $events ) {
			echo '<p class="description">' . esc_html__( 'Could not fetch events from WorkOS API.', 'workos' ) . '</p>';
			return;
		}

		if ( empty( $events ) ) {
			echo '<p class="description">' . esc_html__( 'No recent events found.', 'workos' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Event Type', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Event ID', 'workos' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'workos' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $event ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $event['event'] ?? '' ) . '</code></td>';
			echo '<td><code>' . esc_html( $event['id'] ?? '' ) . '</code></td>';
			echo '<td>' . ( ! empty( $event['created_at'] ) ? esc_html( $this->format_datetime( $event['created_at'] ) ) : '' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Showing up to 25 events from last 90 days. Cached 5 min.', 'workos' ) . '</p>';
	}

	/**
	 * Get local WorkOS user meta.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return array
	 */
	private function get_local_meta( int $user_id ): array {
		return [
			'user_id'        => get_user_meta( $user_id, '_workos_user_id', true ),
			'org_id'         => get_user_meta( $user_id, '_workos_org_id', true ),
			'last_synced_at' => get_user_meta( $user_id, '_workos_last_synced_at', true ),
			'deactivated'    => get_user_meta( $user_id, '_workos_deactivated', true ),
			'first_login'    => get_user_meta( $user_id, '_workos_first_login', true ),
		];
	}

	/**
	 * Get WorkOS user data with transient caching.
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 *
	 * @return array|null User data or null on failure.
	 */
	private function get_cached_workos_user( string $workos_user_id ): ?array {
		$cache_key = 'workos_profile_user_' . md5( $workos_user_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = workos()->api()->get_user( $workos_user_id );

		if ( is_wp_error( $result ) ) {
			workos_log( 'UserProfile: Failed to fetch user ' . $workos_user_id . ': ' . $result->get_error_message() );
			return null;
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get organization memberships with transient caching.
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 *
	 * @return array|null Memberships response or null on failure.
	 */
	private function get_cached_memberships( string $workos_user_id ): ?array {
		$cache_key = 'workos_profile_memberships_' . md5( $workos_user_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = workos()->api()->list_organization_memberships( [ 'user_id' => $workos_user_id ] );

		if ( is_wp_error( $result ) ) {
			workos_log( 'UserProfile: Failed to fetch memberships for ' . $workos_user_id . ': ' . $result->get_error_message() );
			return null;
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get user-related events with transient caching.
	 *
	 * Fetches events filtered by organization and event type, then
	 * client-side filters for the specific user.
	 *
	 * @param string $workos_user_id WorkOS user ID.
	 * @param string $org_id         WorkOS organization ID.
	 *
	 * @return array|null Filtered events or null on failure.
	 */
	private function get_cached_events( string $workos_user_id, string $org_id ): ?array {
		$cache_key = 'workos_profile_events_' . md5( $workos_user_id . $org_id );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$matched = [];
		$cursor  = null;

		for ( $page = 0; $page < self::MAX_EVENT_PAGES; $page++ ) {
			$params = [
				'organization_id' => $org_id,
				'events'          => self::USER_EVENT_TYPES,
				'limit'           => self::EVENTS_PER_PAGE,
				'range_start'     => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '-90 days' ) ),
			];

			if ( $cursor ) {
				$params['after'] = $cursor;
			}

			$result = workos()->api()->list_events( $params );

			if ( is_wp_error( $result ) ) {
				workos_log( 'UserProfile: Failed to fetch events: ' . $result->get_error_message() );
				return null;
			}

			$events = $result['data'] ?? [];

			foreach ( $events as $event ) {
				if ( $this->event_matches_user( $event, $workos_user_id ) ) {
					$matched[] = $event;

					if ( count( $matched ) >= self::MAX_EVENTS ) {
						break 2;
					}
				}
			}

			// Check for more pages.
			$cursor = $result['list_metadata']['after'] ?? null;
			if ( ! $cursor || empty( $events ) ) {
				break;
			}
		}

		set_transient( $cache_key, $matched, self::CACHE_TTL );

		return $matched;
	}

	/**
	 * Check if an event is related to a specific user.
	 *
	 * @param array  $event          Event data from the API.
	 * @param string $workos_user_id WorkOS user ID to match.
	 *
	 * @return bool
	 */
	private function event_matches_user( array $event, string $workos_user_id ): bool {
		$data = $event['data'] ?? [];

		// Direct match: data.id (for user.* events).
		if ( ! empty( $data['id'] ) && $data['id'] === $workos_user_id ) {
			return true;
		}

		// Match: data.user_id (for membership events).
		if ( ! empty( $data['user_id'] ) && $data['user_id'] === $workos_user_id ) {
			return true;
		}

		// Match: data.user.id (for nested user objects).
		if ( ! empty( $data['user']['id'] ) && $data['user']['id'] === $workos_user_id ) {
			return true;
		}

		return false;
	}

	/**
	 * Render a single form-table row.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value (pre-escaped HTML).
	 */
	private function render_form_row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row">%s</th><td>%s</td></tr>',
			esc_html( $label ),
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped in callers.
		);
	}

	/**
	 * Format an ISO 8601 datetime string using WordPress site settings.
	 *
	 * @param string $datetime ISO 8601 datetime string.
	 *
	 * @return string Formatted datetime or the original string on failure.
	 */
	private function format_datetime( string $datetime ): string {
		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return $datetime;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $format, $timestamp );
	}
}
