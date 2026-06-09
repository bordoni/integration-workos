<?php
/**
 * Old-address notice — delivered to the CURRENT email when a change is
 * requested. Lets the user cancel if it wasn't them.
 *
 * Context vars:
 *
 * @var \WP_User $user
 * @var string  $old_email
 * @var string  $new_email
 * @var string  $masked_new_email
 * @var string  $cancel_url
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
			/* translators: 1: site name, 2: masked new email. */
			esc_html__( 'A request was made to change the email address on your %1$s account to %2$s.', 'integration-workos' ),
			esc_html( $site_name ),
			esc_html( $masked_new_email )
		);
		?>
	</p>

	<p>
		<?php esc_html_e( "If this was you, no action is needed — finish the change from the link sent to the new address.", 'integration-workos' ); ?>
	</p>

	<p style="margin: 24px 0; padding: 16px; background: #fcf0f1; border-left: 4px solid #d63638;">
		<strong><?php esc_html_e( "Wasn't you?", 'integration-workos' ); ?></strong><br />
		<?php esc_html_e( 'Cancel the change right away:', 'integration-workos' ); ?>
		<br /><br />
		<a href="<?php echo esc_url( $cancel_url ); ?>" style="display: inline-block; background: #d63638; color: #fff; padding: 10px 16px; border-radius: 4px; text-decoration: none; font-weight: 600;">
			<?php esc_html_e( 'Cancel email change', 'integration-workos' ); ?>
		</a>
	</p>

	<p style="font-size: 13px; color: #50575e;">
		<?php
		printf(
			/* translators: %d: number of minutes. */
			esc_html__( 'This request expires in %d minutes.', 'integration-workos' ),
			(int) $expires_in_minutes
		);
		?>
	</p>

	<p style="font-size: 13px; color: #50575e;">
		<?php esc_html_e( 'Cancel link:', 'integration-workos' ); ?><br />
		<a href="<?php echo esc_url( $cancel_url ); ?>" style="word-break: break-all; color: #d63638;"><?php echo esc_html( $cancel_url ); ?></a>
	</p>

	<hr style="border: none; border-top: 1px solid #dcdcde; margin: 24px 0;" />
	<p style="font-size: 13px; color: #50575e;">
		<a href="<?php echo esc_url( $site_url ); ?>" style="color: #50575e;"><?php echo esc_html( $site_url ); ?></a>
	</p>
</body>
</html>
