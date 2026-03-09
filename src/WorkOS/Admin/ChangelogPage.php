<?php
/**
 * Changelog admin page.
 *
 * @package WorkOS\Admin
 */

namespace WorkOS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Hidden admin page that renders the plugin changelog.
 */
class ChangelogPage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
	}

	/**
	 * Register the hidden submenu page.
	 */
	public function register_menu(): void {
		add_submenu_page(
			'workos',
			__( 'Changelog', 'integration-workos' ),
			__( 'Changelog', 'integration-workos' ),
			'manage_options',
			'workos-changelog',
			[ $this, 'render_page' ]
		);

		// Hidden from the sidebar via admin.css; the page remains accessible by URL.
	}

	/**
	 * Render the changelog page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$changelog_file = workos()->getDir() . 'CHANGELOG.md';
		if ( ! file_exists( $changelog_file ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Changelog', 'integration-workos' ) . '</h1>';
			echo '<p>' . esc_html__( 'Changelog file not found.', 'integration-workos' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $changelog_file );
		if ( false === $content ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Changelog', 'integration-workos' ) . '</h1>';
			echo '<p>' . esc_html__( 'Unable to read changelog.', 'integration-workos' ) . '</p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_version = isset( $_GET['version'] ) ? sanitize_text_field( wp_unslash( $_GET['version'] ) ) : '';

		$versions = $this->parse_changelog( $content );

		if ( $filter_version ) {
			$versions = array_filter(
				$versions,
				static function ( array $entry ) use ( $filter_version ): bool {
					return $entry['version'] === $filter_version;
				}
			);
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Changelog', 'integration-workos' ); ?></h1>

			<?php if ( $filter_version && empty( $versions ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: version number */
						esc_html__( 'No changelog entry found for version %s.', 'integration-workos' ),
						esc_html( $filter_version )
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=workos-changelog' ) ); ?>">
						<?php esc_html_e( 'View full changelog', 'integration-workos' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<?php foreach ( $versions as $entry ) : ?>
				<div class="workos-changelog-entry">
					<h2>
						<?php
						echo esc_html( $entry['version'] );
						if ( $entry['date'] ) {
							echo ' <small>— ' . esc_html( $entry['date'] ) . '</small>';
						}
						?>
					</h2>
					<?php echo wp_kses_post( $entry['html'] ); ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $filter_version && ! empty( $versions ) ) : ?>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=workos-changelog' ) ); ?>">
						<?php esc_html_e( 'View full changelog', 'integration-workos' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Parse a Keep a Changelog-style markdown file into version entries.
	 *
	 * @param string $content Raw markdown content.
	 *
	 * @return array[] Array of [ 'version' => string, 'date' => string, 'html' => string ].
	 */
	private function parse_changelog( string $content ): array {
		// Split by version headings: ## [version] or ## [version] - date.
		$parts = preg_split( '/^## \[/m', $content );
		if ( ! $parts ) {
			return [];
		}

		// First part is the file header (# Changelog), skip it.
		array_shift( $parts );

		$entries = [];

		foreach ( $parts as $part ) {
			// Extract version and optional date from the first line.
			if ( ! preg_match( '/^([^\]]+)\]\s*(?:-\s*(.+))?/m', $part, $matches ) ) {
				continue;
			}

			$version = trim( $matches[1] );
			$date    = isset( $matches[2] ) ? trim( $matches[2] ) : '';

			// Remove the first line (version heading) from content.
			$body = preg_replace( '/^[^\n]*\n/', '', $part );
			$html = $this->markdown_to_html( $body );

			$entries[] = [
				'version' => $version,
				'date'    => $date,
				'html'    => $html,
			];
		}

		return $entries;
	}

	/**
	 * Convert simple changelog markdown to HTML.
	 *
	 * Supports ### headings and - list items.
	 *
	 * @param string $markdown Raw markdown body.
	 *
	 * @return string HTML output.
	 */
	private function markdown_to_html( string $markdown ): string {
		$lines   = explode( "\n", trim( $markdown ) );
		$html    = '';
		$in_list = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				if ( $in_list ) {
					$html   .= "</ul>\n";
					$in_list = false;
				}
				continue;
			}

			// ### Section headings.
			if ( str_starts_with( $trimmed, '### ' ) ) {
				if ( $in_list ) {
					$html   .= "</ul>\n";
					$in_list = false;
				}
				$heading = substr( $trimmed, 4 );
				$html   .= '<h3>' . esc_html( $heading ) . "</h3>\n";
				continue;
			}

			// - List items.
			if ( str_starts_with( $trimmed, '- ' ) ) {
				if ( ! $in_list ) {
					$html   .= "<ul>\n";
					$in_list = true;
				}
				$item  = substr( $trimmed, 2 );
				$html .= '<li>' . esc_html( $item ) . "</li>\n";
				continue;
			}

			// Plain text fallback.
			if ( $in_list ) {
				$html   .= "</ul>\n";
				$in_list = false;
			}
			$html .= '<p>' . esc_html( $trimmed ) . "</p>\n";
		}

		if ( $in_list ) {
			$html .= "</ul>\n";
		}

		return $html;
	}
}
