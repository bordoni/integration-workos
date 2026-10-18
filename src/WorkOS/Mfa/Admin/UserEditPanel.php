<?php
/**
 * Two-step verification tools on another user's edit screen.
 *
 * @package WorkOS\Mfa\Admin
 */

namespace WorkOS\Mfa\Admin;

use WorkOS\Mfa\FactorService;
use WorkOS\Mfa\Policy;
use WorkOS\Mfa\RestApi;
use WorkOS\User;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Shows an admin a user's MFA status on `user-edit.php`, with a "require"
 * checkbox and a "remove every authenticator app" checkbox that apply when
 * the profile form is saved.
 *
 * The user's own screen (`profile.php`) shows the account panel instead.
 */
class UserEditPanel {

	/**
	 * Factor service.
	 *
	 * @var FactorService
	 */
	private FactorService $factors;

	/**
	 * MFA policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Constructor.
	 *
	 * @param FactorService $factors Factor service.
	 * @param Policy        $policy  MFA policy.
	 */
	public function __construct( FactorService $factors, Policy $policy ) {
		$this->factors = $factors;
		$this->policy  = $policy;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'edit_user_profile', [ $this, 'render' ], 22 );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
	}

	/**
	 * Render the panel.
	 *
	 * @param WP_User $user User being edited.
	 *
	 * @return void
	 */
	public function render( WP_User $user ): void {
		if ( ! User::is_sso( $user->ID ) || true !== RestApi::can_manage_user( $user->ID ) ) {
			return;
		}

		$status   = $this->factors->status( $user->ID );
		$flag     = $this->factors->is_required_flag( $user->ID );
		$required = $this->policy->is_required( $user->ID );
		?>
		<h2><?php esc_html_e( 'Two-step verification', 'integration-workos' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'integration-workos' ); ?></th>
				<td>
					<?php
					if ( is_wp_error( $status ) ) {
						printf(
							/* translators: %s: error message. */
							esc_html__( 'Couldn’t read the status from WorkOS: %s', 'integration-workos' ),
							esc_html( $status->get_error_message() )
						);
					} elseif ( $status['enabled'] ) {
						printf(
							/* translators: %d: number of authenticator apps. */
							esc_html( _n( 'On — %d authenticator app.', 'On — %d authenticator apps.', count( $status['factors'] ), 'integration-workos' ) ),
							count( $status['factors'] )
						);
					} else {
						esc_html_e( 'Off.', 'integration-workos' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Require', 'integration-workos' ); ?></th>
				<td>
					<input type="hidden" name="workos_mfa_required" value="0" />
					<label>
						<input type="checkbox" name="workos_mfa_required" value="1" <?php checked( $flag ); ?> />
						<?php esc_html_e( 'Require two-step verification for this user. Without an authenticator app, they set one up at their next sign-in.', 'integration-workos' ); ?>
					</label>
					<?php if ( $required && ! $flag ) : ?>
						<p class="description"><?php esc_html_e( 'Already required by the user’s role or a filter.', 'integration-workos' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( ! is_wp_error( $status ) && $status['enabled'] ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Lost authenticator', 'integration-workos' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="workos_mfa_reset" value="1" />
						<?php esc_html_e( 'Remove every authenticator app from this account when I save.', 'integration-workos' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Use this when the user lost their phone. Confirm who they are first.', 'integration-workos' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Apply the panel on save.
	 *
	 * Core has already checked the `update-user_{id}` nonce and `edit_user`
	 * before this action fires.
	 *
	 * @param int $user_id User being saved.
	 *
	 * @return void
	 */
	public function save( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Core verified the profile form nonce.
		if ( ! isset( $_POST['workos_mfa_required'] ) || true !== RestApi::can_manage_user( $user_id ) ) {
			return;
		}

		$required = sanitize_text_field( wp_unslash( $_POST['workos_mfa_required'] ) );
		$this->factors->set_required( $user_id, '1' === $required );

		if ( ! empty( $_POST['workos_mfa_reset'] ) ) {
			$this->factors->reset( $user_id );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
