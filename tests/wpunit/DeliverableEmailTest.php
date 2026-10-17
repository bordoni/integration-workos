<?php
/**
 * Tests for workos_is_deliverable_email() and the placeholder-email guards
 * in UserSync (profile push and user creation).
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Sync\UserSync;

/**
 * Placeholder email tests.
 */
class DeliverableEmailTest extends WPTestCase {

	/**
	 * WorkOS ID of the linked test user (unique per test).
	 *
	 * @var string
	 */
	private string $workos_id = '';

	/**
	 * Linked WP user ID.
	 *
	 * @var int
	 */
	private int $user_id = 0;

	/**
	 * Captured WorkOS API requests (method, URL, body).
	 *
	 * @var array
	 */
	private array $http_requests = [];

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// The suite bootstrap treats every address as deliverable; test the real rules.
		remove_filter( 'workos_is_deliverable_email', '__return_true' );

		\WorkOS\Config::set_active_environment( 'production' );
		update_option(
			'workos_production',
			[
				'api_key'        => 'sk_test_fake',
				'client_id'      => 'client_fake',
				'environment_id' => 'environment_test',
			]
		);
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
		remove_all_actions( 'user_register' );

		$suffix          = uniqid();
		$this->workos_id = 'user_deliverable_' . $suffix;

		$user_id = self::factory()->user->create(
			[
				'user_email' => 'grace.' . $suffix . '@acme.dev',
				'first_name' => 'Grace',
				'last_name'  => 'Hopper',
			]
		);
		if ( is_wp_error( $user_id ) ) {
			$this->fail( $user_id->get_error_message() );
		}
		$this->user_id = $user_id;
		update_user_meta( $this->user_id, '_workos_user_id', $this->workos_id );

		$this->http_requests = [];
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		delete_option( 'workos_production' );
		\WorkOS\Config::set_active_environment( 'staging' );
		\WorkOS\App::container()->get( \WorkOS\Options\Production::class )->reset();

		foreach ( [ 'syncing' => false, 'pending_names' => [] ] as $name => $value ) {
			$ref = new \ReflectionProperty( UserSync::class, $name );
			$ref->setAccessible( true );
			$ref->setValue( null, $value );
		}

		parent::tearDown();
	}

	/**
	 * Fake WorkOS API: records requests and answers 200.
	 *
	 * @param false|array $response Pre-filtered response.
	 * @param array       $args     Request arguments.
	 * @param string      $url      Request URL.
	 *
	 * @return false|array
	 */
	public function intercept_http( $response, array $args, string $url ) {
		if ( false === strpos( $url, 'api.workos.com' ) ) {
			return $response;
		}

		$body = json_decode( $args['body'] ?? '', true );

		$this->http_requests[] = [
			'method' => $args['method'] ?? 'GET',
			'url'    => $url,
			'body'   => is_array( $body ) ? $body : [],
		];

		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => wp_json_encode( [ 'id' => $this->workos_id ] ),
		];
	}

	/**
	 * The `update_user` calls made so far.
	 *
	 * @return array
	 */
	private function update_calls(): array {
		$path = '/user_management/users/' . $this->workos_id;

		return array_values(
			array_filter(
				$this->http_requests,
				static function ( $request ) use ( $path ) {
					return 'PUT' === $request['method'] && false !== strpos( $request['url'], $path );
				}
			)
		);
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Addresses the helper rejects.
	 *
	 * @return array
	 */
	public function undeliverable_provider(): array {
		return [
			'.fake'                 => [ 'someone@sanitized.fake' ],
			'.invalid'              => [ 'someone@example.invalid' ],
			'.test'                 => [ 'someone@workos.test' ],
			'.example'              => [ 'someone@acme.example' ],
			'.localhost'            => [ 'someone@app.localhost' ],
			'example.com'           => [ 'someone@example.com' ],
			'example.net'           => [ 'someone@example.net' ],
			'example.org'           => [ 'someone@example.org' ],
			'example.com subdomain' => [ 'someone@mail.example.com' ],
			'upper case'            => [ 'Someone@Sanitized.FAKE' ],
			'trailing dot'          => [ 'someone@example.com.' ],
			'no domain'             => [ 'someone@' ],
			'no @'                  => [ 'someone' ],
		];
	}

	/**
	 * Reserved and malformed addresses aren't deliverable.
	 *
	 * @dataProvider undeliverable_provider
	 *
	 * @param string $email Email address.
	 */
	public function test_undeliverable_addresses( string $email ): void {
		$this->assertFalse( workos_is_deliverable_email( $email ) );
	}

	/**
	 * Addresses the helper accepts.
	 *
	 * @return array
	 */
	public function deliverable_provider(): array {
		return [
			'plain'             => [ 'ada@acme.dev' ],
			'subdomain'         => [ 'ada@mail.acme.co.uk' ],
			'lookalike domain'  => [ 'ada@myexample.com' ],
			'example as label'  => [ 'ada@example.com.au' ],
			'test in the label' => [ 'ada@test.io' ],
		];
	}

	/**
	 * Real addresses are deliverable.
	 *
	 * @dataProvider deliverable_provider
	 *
	 * @param string $email Email address.
	 */
	public function test_deliverable_addresses( string $email ): void {
		$this->assertTrue( workos_is_deliverable_email( $email ) );
	}

	/**
	 * The filter has the last word, and receives the address.
	 */
	public function test_filter_overrides_result(): void {
		$seen = [];
		add_filter(
			'workos_is_deliverable_email',
			static function ( $deliverable, $email ) use ( &$seen ) {
				$seen[] = [ $deliverable, $email ];
				return 'ok@sanitized.fake' === $email ? true : $deliverable;
			},
			10,
			2
		);

		$this->assertTrue( workos_is_deliverable_email( 'ok@sanitized.fake' ) );
		$this->assertFalse( workos_is_deliverable_email( 'no@sanitized.fake' ) );
		$this->assertSame( [ false, 'ok@sanitized.fake' ], $seen[0] );
	}

	// -------------------------------------------------------------------------
	// Profile push
	// -------------------------------------------------------------------------

	/**
	 * A placeholder email isn't pushed; the name change in the same save still is.
	 */
	public function test_push_skips_placeholder_email_but_sends_other_fields(): void {
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Amazing',
				'user_email' => 'grace@sanitized.fake',
			]
		);

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'first_name' => 'Amazing' ], $calls[0]['body'] );
	}

	/**
	 * A save that only changes the email to a placeholder sends nothing.
	 */
	public function test_push_with_only_placeholder_email_sends_nothing(): void {
		wp_update_user(
			[
				'ID'         => $this->user_id,
				'user_email' => 'grace@example.invalid',
			]
		);

		$this->assertCount( 0, $this->update_calls() );
		$this->assertSame( 'grace@example.invalid', get_userdata( $this->user_id )->user_email, 'WordPress keeps the change.' );
	}

	/**
	 * A real address is still pushed.
	 */
	public function test_push_sends_real_email(): void {
		$email = 'grace.' . uniqid() . '@navy.dev';

		wp_update_user(
			[
				'ID'         => $this->user_id,
				'user_email' => $email,
			]
		);

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'email' => $email ], $calls[0]['body'] );
	}

	/**
	 * The pre-push filter receives the update and can veto it with false.
	 */
	public function test_pre_push_filter_false_skips_push(): void {
		$seen = [];
		add_filter(
			'workos_pre_push_profile',
			static function ( $update, $user_id, $workos_id ) use ( &$seen ) {
				$seen[] = [ $update, $user_id, $workos_id ];
				return false;
			},
			10,
			3
		);

		wp_update_user(
			[
				'ID'        => $this->user_id,
				'last_name' => 'Murray',
			]
		);

		$this->assertCount( 0, $this->update_calls() );
		$this->assertSame( [ [ 'last_name' => 'Murray' ], $this->user_id, $this->workos_id ], $seen[0] );
	}

	/**
	 * An empty array from the pre-push filter skips the push too.
	 */
	public function test_pre_push_filter_empty_array_skips_push(): void {
		add_filter( 'workos_pre_push_profile', '__return_empty_array' );

		wp_update_user(
			[
				'ID'        => $this->user_id,
				'last_name' => 'Murray',
			]
		);

		$this->assertCount( 0, $this->update_calls() );
	}

	/**
	 * The pre-push filter can change what is sent.
	 */
	public function test_pre_push_filter_can_change_fields(): void {
		add_filter(
			'workos_pre_push_profile',
			static function ( $update ) {
				unset( $update['first_name'] );
				return $update;
			}
		);

		wp_update_user(
			[
				'ID'         => $this->user_id,
				'first_name' => 'Amazing',
				'last_name'  => 'Murray',
			]
		);

		$calls = $this->update_calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( [ 'last_name' => 'Murray' ], $calls[0]['body'] );
	}

	// -------------------------------------------------------------------------
	// User creation on sign-in
	// -------------------------------------------------------------------------

	/**
	 * No WP user is created from a WorkOS user on a placeholder email.
	 */
	public function test_sign_in_refuses_to_create_user_from_placeholder_email(): void {
		$created = 0;
		add_action(
			'workos_user_created',
			static function () use ( &$created ) {
				++$created;
			}
		);

		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_parked_' . uniqid(),
				'email' => 'parked@sanitized.fake',
			],
			'login'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'workos_undeliverable_email', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertFalse( get_user_by( 'email', 'parked@sanitized.fake' ) );
		$this->assertSame( 0, $created );
	}

	/**
	 * A WorkOS user already linked by ID still signs in, placeholder or not.
	 */
	public function test_sign_in_by_linked_workos_id_still_works(): void {
		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => $this->workos_id,
				'email' => 'grace@sanitized.fake',
			],
			'login'
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $this->user_id, $result->ID );
	}

	/**
	 * A WorkOS user on a real address still gets a new WP user.
	 */
	public function test_sign_in_creates_user_from_real_email(): void {
		$email = 'new.' . uniqid() . '@acme.dev';

		$result = UserSync::find_or_create_wp_user(
			[
				'id'    => 'user_new_' . uniqid(),
				'email' => $email,
			],
			'login'
		);

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $email, $result->user_email );
	}
}
