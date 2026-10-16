<?php
/**
 * Tests for the refused re-link notice on the user edit page.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Admin\UserProfile;
use WorkOS\Sync\UserSync;

/**
 * UserProfile::render_link_refused_notice() tests.
 */
class UserProfileLinkRefusedNoticeTest extends WPTestCase {

	/**
	 * Render the notice for a user and return its markup.
	 *
	 * @param int $wp_user_id WP user ID.
	 *
	 * @return string
	 */
	private function render_notice( int $wp_user_id ): string {
		$method = new \ReflectionMethod( UserProfile::class, 'render_link_refused_notice' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( new UserProfile(), $wp_user_id, 'user_current' );

		return (string) ob_get_clean();
	}

	/**
	 * Create a user linked to user_current with a refused re-link from user_attempted.
	 *
	 * @return int WP user ID.
	 */
	private function create_refused_user(): int {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, '_workos_user_id', 'user_current' );
		update_user_meta(
			$user_id,
			UserSync::META_LINK_REFUSED,
			[
				'workos_user_id' => 'user_attempted',
				'email'          => 'attempted@example.com',
				'source'         => 'authkit',
				'time'           => time(),
			]
		);

		return $user_id;
	}

	/**
	 * No refused re-link, no notice.
	 */
	public function test_no_notice_without_refused_relink(): void {
		$user_id = self::factory()->user->create();

		$this->assertSame( '', $this->render_notice( $user_id ) );
	}

	/**
	 * An active account gets the --force command only.
	 */
	public function test_active_account_suggests_force(): void {
		$user_id = $this->create_refused_user();

		$html = $this->render_notice( $user_id );

		$this->assertStringContainsString( "<code>wp workos user link {$user_id} user_attempted --force</code>", $html );
		$this->assertStringNotContainsString( '--reactivate', $html );
	}

	/**
	 * A deactivated account needs --reactivate, or the CLI refuses the link.
	 */
	public function test_deactivated_account_suggests_reactivate(): void {
		$user_id = $this->create_refused_user();
		update_user_meta( $user_id, '_workos_deactivated', true );

		$html = $this->render_notice( $user_id );

		$this->assertStringContainsString( "<code>wp workos user link {$user_id} user_attempted --force --reactivate</code>", $html );
	}
}
