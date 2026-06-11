<?php
/**
 * Verification email body — delivered to the NEW address.
 *
 * Context vars:
 *
 * @var \WP_User $user
 * @var string  $new_email
 * @var string  $confirm_url
 * @var int     $expires_at
 * @var string  $site_name
 * @var string  $site_url
 *
 * @package WorkOS\Templates
 */

defined( 'ABSPATH' ) || exit;

$expires_in_minutes = max( 1, (int) round( max( 0, $expires_at - time() ) / 60 ) );
$display_name       = $user instanceof \WP_User ? $user->display_name : '';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.5; color: #1d2327; max-width: 560px; margin: 0 auto; padding: 24px;">
	<h1 style="font-size: 20px; margin: 0 0 16px;"><?php echo esc_html( $site_name ); ?></h1>

	<p>
		<?php
		if ( '' !== $display_name ) {
			/* translators: %s: display name. */
			printf( esc_html__( 'Hi %s,', 'integration-workos' ), esc_html( $display_name ) );
		} else {
			echo esc_html__( 'Hi,', 'integration-workos' );
		}
		?>
	</p>

	<p>
		<?php
		printf(
			/* translators: 1: site name. */
			esc_html__( 'Someone — likely you — asked to change the email address on the %1$s account associated with this inbox.', 'integration-workos' ),
			esc_html( $site_name )
		);
		?>
	</p>

	<p>
		<?php esc_html_e( 'To confirm, click the button below:', 'integration-workos' ); ?>
	</p>

	<p style="margin: 24px 0;">
		<a href="<?php echo esc_url( $confirm_url ); ?>" style="display: inline-block; background: #2271b1; color: #fff; padding: 12px 20px; border-radius: 4px; text-decoration: none; font-weight: 600;">
			<?php esc_html_e( 'Confirm email change', 'integration-workos' ); ?>
		</a>
	</p>

	<p style="font-size: 13px; color: #50575e;">
		<?php
		printf(
			/* translators: %d: number of minutes. */
			esc_html__( 'This link expires in %d minutes.', 'integration-workos' ),
			(int) $expires_in_minutes
		);
		?>
	</p>

	<p style="font-size: 13px; color: #50575e;">
		<?php esc_html_e( 'If the button does not work, paste this URL into your browser:', 'integration-workos' ); ?><br />
		<a href="<?php echo esc_url( $confirm_url ); ?>" style="word-break: break-all; color: #2271b1;"><?php echo esc_html( $confirm_url ); ?></a>
	</p>

	<hr style="border: none; border-top: 1px solid #dcdcde; margin: 24px 0;" />

	<p style="font-size: 13px; color: #50575e;">
		<?php esc_html_e( "If you didn't request this change, you can ignore this email — the request will expire on its own.", 'integration-workos' ); ?>
	</p>

	<p style="font-size: 13px; color: #50575e;">
		<a href="<?php echo esc_url( $site_url ); ?>" style="color: #50575e;"><?php echo esc_html( $site_url ); ?></a>
	</p>
</body>
</html>
