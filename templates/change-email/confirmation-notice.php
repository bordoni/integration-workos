<?php
/**
 * Post-commit confirmation — delivered to the PREVIOUS email address.
 *
 * Context vars:
 *
 * @var \WP_User $user
 * @var string  $old_email
 * @var string  $new_email
 * @var string  $masked_new_email
 * @var string  $site_name
 * @var string  $site_url
 *
 * @package WorkOS\Templates
 */

defined( 'ABSPATH' ) || exit;

$display_name = $user instanceof \WP_User ? $user->display_name : '';
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
			esc_html__( 'The email address on your %1$s account was changed to %2$s.', 'integration-workos' ),
			esc_html( $site_name ),
			esc_html( $masked_new_email )
		);
		?>
	</p>

	<p>
		<?php esc_html_e( "If you made this change, no further action is needed.", 'integration-workos' ); ?>
	</p>

	<p style="margin: 24px 0; padding: 16px; background: #fcf0f1; border-left: 4px solid #d63638;">
		<strong><?php esc_html_e( "Didn't make this change?", 'integration-workos' ); ?></strong><br />
		<?php
		printf(
			/* translators: %s: site URL. */
			wp_kses(
				__( 'Contact a site administrator at <a href="%s">%s</a> right away.', 'integration-workos' ),
				[ 'a' => [ 'href' => true ] ]
			),
			esc_url( $site_url ),
			esc_html( $site_url )
		);
		?>
	</p>

	<hr style="border: none; border-top: 1px solid #dcdcde; margin: 24px 0;" />
	<p style="font-size: 13px; color: #50575e;">
		<a href="<?php echo esc_url( $site_url ); ?>" style="color: #50575e;"><?php echo esc_html( $site_url ); ?></a>
	</p>
</body>
</html>
