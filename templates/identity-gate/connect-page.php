<?php
/**
 * "Connect your account" page, shown by the linked-identity gate.
 *
 * Standalone on purpose: no theme header, navigation or admin bar, so a
 * blocked user sees nothing of the site but this.
 *
 * Context vars:
 *
 * @var string $site_name   Site name.
 * @var string $site_icon   Site icon URL, or ''.
 * @var string $heading     Heading.
 * @var string $message     Message.
 * @var string $button      Button label.
 * @var string $connect_url "Connect account" URL.
 * @var string $logout_url  Sign-out URL.
 *
 * @package WorkOS\Templates
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $heading . ' · ' . $site_name ); ?></title>
	<style>
		:root { color-scheme: light dark; --bg: #f6f7f7; --card: #fff; --text: #1d2327; --muted: #50575e; --border: #dcdcde; --accent: #2271b1; --accent-text: #fff; }
		@media (prefers-color-scheme: dark) {
			:root { --bg: #101517; --card: #1d2327; --text: #f0f0f1; --muted: #a7aaad; --border: #3c434a; --accent: #72aee6; --accent-text: #101517; }
		}
		* { box-sizing: border-box; }
		body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; background: var(--bg); color: var(--text); font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		main { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 32px; text-align: center; }
		img { width: 48px; height: 48px; border-radius: 8px; }
		.site { margin: 12px 0 24px; color: var(--muted); font-size: 14px; }
		h1 { margin: 0 0 12px; font-size: 22px; line-height: 1.3; }
		p { margin: 0 0 24px; color: var(--muted); }
		.button { display: block; padding: 12px 16px; border-radius: 8px; background: var(--accent); color: var(--accent-text); font-weight: 600; text-decoration: none; }
		.button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
		.secondary { display: inline-block; margin-top: 16px; color: var(--muted); font-size: 14px; }
	</style>
</head>
<body>
	<main>
		<?php if ( $site_icon ) : ?>
			<img src="<?php echo esc_url( $site_icon ); ?>" alt="">
		<?php endif; ?>
		<div class="site"><?php echo esc_html( $site_name ); ?></div>
		<h1><?php echo esc_html( $heading ); ?></h1>
		<p><?php echo esc_html( $message ); ?></p>
		<a class="button" href="<?php echo esc_url( $connect_url ); ?>"><?php echo esc_html( $button ); ?></a>
		<a class="secondary" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Sign out', 'integration-workos' ); ?></a>
	</main>
</body>
</html>
