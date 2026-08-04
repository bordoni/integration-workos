<?php
/**
 * Lock status panel on the user-edit screen.
 *
 * @package WorkOS\Lock\Admin
 */

namespace WorkOS\Lock\Admin;

use WorkOS\Lock\Registry;
use WorkOS\Time\Clock;
use WorkOS\Lock\State;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Shows which locks are holding state against a user, and offers to clear them.
 *
 * Rendered only when at least one lock has state, so the profile screen gains
 * nothing for the overwhelming majority of users. When a lock has engaged, this
 * is where an operator can see *why* someone is being turned away — which lock,
 * how many attempts, how long is left — rather than guessing from a 429 in a
 * support ticket.
 *
 * Registers at priority 22, after the password-reset (20) and change-email (21)
 * panels.
 */
class UserProfilePanel {

	/**
	 * Lock registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * Unlock action handler.
	 *
	 * @var UnlockHandler
	 */
	private UnlockHandler $handler;

	/**
	 * Time source.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Constructor.
	 *
	 * @param Registry      $registry Lock registry.
	 * @param UnlockHandler $handler  Unlock action handler.
	 * @param Clock         $clock    Time source.
	 */
	public function __construct( Registry $registry, UnlockHandler $handler, Clock $clock ) {
		$this->registry = $registry;
		$this->handler  = $handler;
		$this->clock    = $clock;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render' ], 22 );
		add_action( 'edit_user_profile', [ $this, 'render' ], 22 );
	}

	/**
	 * Remove hooks.
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'show_user_profile', [ $this, 'render' ], 22 );
		remove_action( 'edit_user_profile', [ $this, 'render' ], 22 );
	}

	/**
	 * Render the panel.
	 *
	 * @param WP_User $user User being edited.
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}

		$statuses = $this->registry->status_for_user( (int) $user->ID );

		if ( [] === $statuses ) {
			return;
		}

		$now = $this->clock->now();

		echo '<h2>' . esc_html__( 'WorkOS login locks', 'integration-workos' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Repeated attempts against this account have been recorded. Clearing the locks lets the user try again immediately.', 'integration-workos' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="col">' . esc_html__( 'Lock', 'integration-workos' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Attempts', 'integration-workos' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'integration-workos' ) . '</th></tr>';

		foreach ( $statuses as $slug => $state ) {
			$lock  = $this->registry->get( (string) $slug );
			$label = ( $lock && method_exists( $lock, 'label' ) ) ? $lock->label() : (string) $slug;

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( (string) $state->strikes() ) . '</td>';
			echo '<td>' . esc_html( $this->describe( $state, $now ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		printf(
			'<p><a class="button" href="%s">%s</a></p>',
			esc_url( $this->handler->action_url( (int) $user->ID ) ),
			esc_html__( 'Clear login locks', 'integration-workos' )
		);
	}

	/**
	 * Describe a lock's current state in one line.
	 *
	 * @param State $state Lock state.
	 * @param int   $now   Current timestamp.
	 *
	 * @return string
	 */
	private function describe( State $state, int $now ): string {
		if ( $state->is_locked( $now ) ) {
			return sprintf(
				/* translators: %s: human-readable duration, e.g. "5 mins". */
				__( 'Locked for another %s', 'integration-workos' ),
				human_time_diff( $now, $state->locked_until() )
			);
		}

		if ( $state->breaches() > 0 ) {
			return sprintf(
				/* translators: %d: number of previous lockouts. */
				_n( 'Not locked (%d previous lockout)', 'Not locked (%d previous lockouts)', $state->breaches(), 'integration-workos' ),
				$state->breaches()
			);
		}

		return __( 'Not locked', 'integration-workos' );
	}
}
