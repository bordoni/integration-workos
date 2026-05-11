<?php
/**
 * Tests for Config::sync_constants_to_db().
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use WorkOS\App;
use WorkOS\Config;
use WorkOS\Options\Options;
use WorkOS\Options\Production;
use WorkOS\Options\Staging;

/**
 * Tests the wp-config.php constant seeder.
 *
 * Once defined, a PHP constant lives for the rest of the process. This class
 * defines WORKOS_API_KEY etc. — and Config::get()'s constant-first precedence
 * means those defines would make "plugin not enabled" assertions in sibling
 * test files start failing. Codeception 5 ignores PHPUnit's
 * RunTestsInSeparateProcesses, so the workaround is to tag this class with
 * the `constants` group and have CI run it via a dedicated codecept
 * invocation (separate PHP process = clean parent).
 *
 * Default run: `slic run wpunit --skip-group constants`
 * Isolated:    `slic run wpunit --group constants`
 *
 * The fixture is engineered so a single set of defines exercises every code
 * path in sync_constants_to_db():
 *
 * - WORKOS_CLIENT_ID                          generic string (no env override)
 * - WORKOS_API_KEY / WORKOS_STAGING_API_KEY   env-specific overrides generic
 * - WORKOS_WEBHOOK_SECRET = ''                empty generic is skipped
 * - WORKOS_PRODUCTION_ENVIRONMENT_ID          prod-only constant
 * - WORKOS_ALLOW_PASSWORD_FALLBACK            bool that flips the default
 * - WORKOS_REDIRECT_URLS                      array constant
 *
 * Keys without constants (organization_id, wp_password_fallback_email_confirmation)
 * exercise the absent-constant path.
 *
 * @group constants
 */
#[Group( 'constants' )]
class ConfigSyncConstantsTest extends WPTestCase {

	/**
	 * Reset DB rows, define the constant fixture, and clear in-memory option
	 * caches. Constants are scoped to whichever PHP process loads this class —
	 * keep this class isolated via the `constants` group so the defines don't
	 * leak into the default wpunit run.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WORKOS_CLIENT_ID' ) ) {
			define( 'WORKOS_CLIENT_ID', 'generic-client' );
		}
		if ( ! defined( 'WORKOS_API_KEY' ) ) {
			define( 'WORKOS_API_KEY', 'generic-api' );
		}
		if ( ! defined( 'WORKOS_STAGING_API_KEY' ) ) {
			define( 'WORKOS_STAGING_API_KEY', 'staging-api' );
		}
		if ( ! defined( 'WORKOS_WEBHOOK_SECRET' ) ) {
			define( 'WORKOS_WEBHOOK_SECRET', '' );
		}
		if ( ! defined( 'WORKOS_PRODUCTION_ENVIRONMENT_ID' ) ) {
			define( 'WORKOS_PRODUCTION_ENVIRONMENT_ID', 'prod-env' );
		}
		if ( ! defined( 'WORKOS_ALLOW_PASSWORD_FALLBACK' ) ) {
			define( 'WORKOS_ALLOW_PASSWORD_FALLBACK', false );
		}
		if ( ! defined( 'WORKOS_REDIRECT_URLS' ) ) {
			define( 'WORKOS_REDIRECT_URLS', [ 'https://a.test', 'https://b.test' ] );
		}

		update_option( 'workos_active_environment', 'staging' );
		delete_option( 'workos_staging' );
		delete_option( 'workos_production' );
		delete_option( 'workos_constants_hash' );

		App::container()->get( Staging::class )->reset();
		App::container()->get( Production::class )->reset();
	}

	/**
	 * Tear down each test.
	 */
	public function tearDown(): void {
		delete_option( 'workos_staging' );
		delete_option( 'workos_production' );
		delete_option( 'workos_constants_hash' );
		delete_option( 'workos_active_environment' );

		App::container()->get( Staging::class )->reset();
		App::container()->get( Production::class )->reset();

		parent::tearDown();
	}

	/**
	 * A generic string constant with no env-specific override is seeded into
	 * the active environment's options row.
	 */
	public function test_generic_string_constant_seeds_active_env_option(): void {
		Config::sync_constants_to_db();

		$this->assertSame( 'generic-client', App::container()->get( Staging::class )->get( 'client_id' ) );
	}

	/**
	 * When both WORKOS_FOO and WORKOS_{ENV}_FOO are defined, the env-specific
	 * constant wins.
	 */
	public function test_env_specific_string_constant_overrides_generic(): void {
		Config::sync_constants_to_db();

		$this->assertSame( 'staging-api', App::container()->get( Staging::class )->get( 'api_key' ) );
	}

	/**
	 * WORKOS_PRODUCTION_* constants must not be applied when the active
	 * environment is staging.
	 */
	public function test_production_specific_constant_ignored_when_active_env_is_staging(): void {
		Config::sync_constants_to_db();

		$stored = get_option( 'workos_staging', [] );

		$this->assertArrayNotHasKey( 'environment_id', $stored );
		$this->assertSame( '', App::container()->get( Staging::class )->get( 'environment_id' ) );
	}

	/**
	 * Switching the active environment to production makes WORKOS_PRODUCTION_*
	 * constants take effect on the workos_production row.
	 */
	public function test_production_specific_constant_seeded_when_active_env_is_production(): void {
		update_option( 'workos_active_environment', 'production' );
		App::container()->get( Production::class )->reset();
		App::container()->get( Staging::class )->reset();

		Config::sync_constants_to_db();

		$this->assertSame( 'prod-env', App::container()->get( Production::class )->get( 'environment_id' ) );
	}

	/**
	 * A generic string constant set to '' is treated as undefined — any
	 * preexisting DB value for that key is preserved.
	 */
	public function test_empty_string_generic_constant_is_skipped(): void {
		$options = App::container()->get( Staging::class );
		$options->set( 'webhook_secret', 'preexisting-secret' );

		Config::sync_constants_to_db();

		$this->assertSame( 'preexisting-secret', App::container()->get( Staging::class )->get( 'webhook_secret' ) );
	}

	/**
	 * A bool constant of `false` overrides the default of `true` and is
	 * actually written to the stored array (not just defaulted).
	 */
	public function test_boolean_false_constant_written_even_when_default_is_true(): void {
		Config::sync_constants_to_db();

		$stored = get_option( 'workos_staging', [] );

		$this->assertArrayHasKey( 'allow_password_fallback', $stored );
		$this->assertFalse( $stored['allow_password_fallback'] );
	}

	/**
	 * A bool key whose constant is *not* defined must not appear in the
	 * stored array — the sync only writes keys whose constants are present.
	 */
	public function test_boolean_constant_not_defined_leaves_key_absent(): void {
		Config::sync_constants_to_db();

		$stored = get_option( 'workos_staging', [] );

		$this->assertArrayNotHasKey( 'wp_password_fallback_email_confirmation', $stored );
	}

	/**
	 * An array constant is written verbatim into redirect_urls.
	 */
	public function test_array_constant_seeded_into_redirect_urls(): void {
		Config::sync_constants_to_db();

		$this->assertSame(
			[ 'https://a.test', 'https://b.test' ],
			App::container()->get( Staging::class )->get( 'redirect_urls' )
		);
	}

	/**
	 * After a sync, the workos_constants_hash option is populated with a
	 * 32-char md5 — this is what gates subsequent calls into a no-op.
	 */
	public function test_hash_option_stored_after_first_sync(): void {
		Config::sync_constants_to_db();

		$hash = get_option( 'workos_constants_hash' );

		$this->assertIsString( $hash );
		$this->assertSame( 32, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $hash );
	}

	/**
	 * A second sync with the same constants is a complete no-op — even if
	 * the DB row was mutated externally between calls, the hash short-circuit
	 * leaves the mutation untouched.
	 */
	public function test_second_sync_with_matching_hash_is_a_no_op(): void {
		Config::sync_constants_to_db();

		update_option( 'workos_staging', [ 'api_key' => 'mutated-by-test' ] );
		App::container()->get( Staging::class )->reset();

		Config::sync_constants_to_db();

		$this->assertSame(
			[ 'api_key' => 'mutated-by-test' ],
			get_option( 'workos_staging' )
		);
	}

	/**
	 * sync_constants_to_db() ends with $options->reset() so any same-request
	 * reader picks up the freshly written DB row instead of a stale cache.
	 */
	public function test_in_memory_cache_reset_after_sync(): void {
		$options = App::container()->get( Staging::class );
		$options->all();

		Config::sync_constants_to_db();

		// The private $options cache lives on the abstract parent class.
		$property = ( new ReflectionClass( Options::class ) )->getProperty( 'options' );
		$property->setAccessible( true );

		$this->assertNull( $property->getValue( $options ) );
	}
}
