<?php
/**
 * Webhook receiver and event router.
 *
 * @package WorkOS\Webhook
 */

namespace WorkOS\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * Handles incoming WorkOS webhooks.
 */
class Receiver {

	/**
	 * Constructor — register the REST route.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	/**
	 * Register the webhook endpoint.
	 */
	public function register_route(): void {
		register_rest_route(
			'workos/v1',
			'/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param \WP_REST_Request $request The incoming request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$signature = $request->get_header( 'workos-signature' );
		$payload   = $request->get_body();
		$secret    = \WorkOS\Config::get_webhook_secret();

		// Verify signature.
		if ( ! empty( $secret ) ) {
			if ( empty( $signature ) || ! \WorkOS\Api\Client::verify_webhook_signature( $payload, $signature, $secret ) ) {
				return new \WP_REST_Response( [ 'error' => 'Invalid signature' ], 401 );
			}
		}

		// Parse event.
		$event = json_decode( $payload, true );

		if ( empty( $event['event'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid event payload' ], 400 );
		}

		$event_type = sanitize_text_field( $event['event'] );

		// Route to internal handlers via WP actions.
		$handlers = [
			'user.created',
			'user.updated',
			'user.deleted',
			'dsync.user.created',
			'dsync.user.updated',
			'dsync.user.deleted',
			'dsync.group.user_added',
			'dsync.group.user_removed',
			'organization.created',
			'organization.updated',
			'organization_membership.created',
			'organization_membership.updated',
			'organization_membership.deleted',
			'connection.activated',
			'connection.deactivated',
			'authentication.email_verification_succeeded',
		];

		if ( in_array( $event_type, $handlers, true ) ) {
			/**
			 * Fires for a specific WorkOS webhook event type.
			 *
			 * @param array $event Full event payload.
			 */
			do_action( "workos_webhook_{$event_type}", $event );
		}

		/**
		 * Fires for all WorkOS webhook events.
		 *
		 * @param array  $event      Full event payload.
		 * @param string $event_type The event type string.
		 */
		do_action( 'workos_webhook', $event, $event_type );

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}
}
