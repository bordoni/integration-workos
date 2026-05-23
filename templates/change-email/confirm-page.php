<?php
/**
 * Frontend confirmation page rendered when a user follows the link in
 * the verification email.
 *
 * The page is bare HTML; the actual confirm POST is fired by
 * `src/js/change-email-confirm/index.ts`, which reads the token from
 * the URL and calls the REST endpoint.
 *
 * Context vars:
 *
 * @var string $site_name
 * @var string $token       The plaintext token from the URL (for the JS to pick up).
 * @var int    $user_id     The target user ID from the URL.
 *
 * @package WorkOS\Templates
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main">
	<div class="workos-change-email-confirm" style="max-width: 560px; margin: 64px auto; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
		<h1 style="font-size: 24px; margin: 0 0 16px;">
			<?php esc_html_e( 'Confirm your email change', 'integration-workos' ); ?>
		</h1>

		<div id="workos-change-email-confirm-status" data-token="<?php echo esc_attr( (string) $token ); ?>" data-user-id="<?php echo esc_attr( (string) (int) $user_id ); ?>">
			<p>
				<?php esc_html_e( 'Confirming…', 'integration-workos' ); ?>
			</p>
		</div>

		<noscript>
			<p style="padding: 16px; background: #fcf0f1; border-left: 4px solid #d63638;">
				<?php esc_html_e( 'JavaScript is required to confirm your email change. Please enable JavaScript and refresh this page.', 'integration-workos' ); ?>
			</p>
		</noscript>
	</div>
</main>
<?php
get_footer();
