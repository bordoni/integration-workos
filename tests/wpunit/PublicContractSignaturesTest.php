<?php
/**
 * Public contract: PHP signatures, constants and load order.
 *
 * The Software Portal (stellarwp/software.liquidweb.com) and other
 * integrators call these symbols directly. Each test pins
 * *compatibility*, not identity: a new trailing optional parameter, a
 * widened visibility or a newly added return type on an untyped method
 * still passes, so feature work isn't blocked. Renaming, reordering,
 * retyping or removing a parameter, changing static-ness, or changing a
 * constant's value fails.
 *
 * When one of these fails, the change breaks a downstream caller. Keep
 * the old shape (add a new method beside it) instead of editing the test.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use lucatume\WPBrowser\TestCase\WPTestCase;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use WorkOS\Api\Client;
use WorkOS\App;
use WorkOS\Auth\AuthKit\Profile;
use WorkOS\Auth\AuthKit\Renderer;
use WorkOS\Auth\ChangeEmail\PendingChange;
use WorkOS\Config;
use WorkOS\Options\Staging;
use WorkOS\Plugin;
use WorkOS\Sync\UserSync;
use WorkOS\User;

/**
 * Reflection checks for every Portal-facing and README-documented PHP symbol.
 *
 * @group public-contract
 */
class PublicContractSignaturesTest extends WPTestCase {

	/**
	 * Shorthand for a parameter spec: [ name, type|null, has default, default ].
	 *
	 * @param string      $name     Parameter name (without `$`).
	 * @param string|null $type     Declared type as PHP prints it, or null for untyped.
	 * @param bool        $optional Whether the parameter has a default.
	 * @param mixed       $default  The default value (when optional).
	 *
	 * @return array{name:string,type:?string,optional:bool,default:mixed}
	 */
	private static function param( string $name, ?string $type, bool $optional = false, $default = null ): array {
		return [
			'name'     => $name,
			'type'     => $type,
			'optional' => $optional,
			'default'  => $default,
		];
	}

	/**
	 * Assert a function or method stays call-compatible with a spec.
	 *
	 * - every expected parameter is still there, in order, with the same
	 *   name (PHP 8 named arguments), type and default;
	 * - a parameter that was required may become optional;
	 * - extra parameters are only allowed after the expected ones, and
	 *   must be optional;
	 * - a declared return type must stay the same; an untyped return may
	 *   gain a type.
	 *
	 * @param ReflectionFunctionAbstract $fn     Function under test.
	 * @param array                      $params Expected parameters (see {@see self::param()}).
	 * @param string|null                $return Expected return type, or null when untyped today.
	 */
	private function assertCompatibleSignature( ReflectionFunctionAbstract $fn, array $params, ?string $return ): void {
		$label  = $fn instanceof ReflectionMethod ? $fn->class . '::' . $fn->name . '()' : $fn->name . '()';
		$actual = $fn->getParameters();

		$this->assertGreaterThanOrEqual( count( $params ), count( $actual ), "{$label} lost a parameter." );

		foreach ( $params as $i => $expected ) {
			$param = $actual[ $i ];
			$where = "{$label} parameter #" . ( $i + 1 );

			$this->assertSame( $expected['name'], $param->getName(), "{$where} was renamed or reordered." );
			$this->assertSame(
				$expected['type'],
				$param->hasType() ? (string) $param->getType() : null,
				"{$where} (\${$expected['name']}) changed type."
			);

			if ( $expected['optional'] ) {
				$this->assertTrue( $param->isDefaultValueAvailable(), "{$where} (\${$expected['name']}) is no longer optional." );
				$this->assertSame( $expected['default'], $param->getDefaultValue(), "{$where} (\${$expected['name']}) changed its default." );
			}
		}

		foreach ( array_slice( $actual, count( $params ) ) as $extra ) {
			$this->assertTrue(
				$extra->isOptional(),
				"{$label} gained a required parameter \${$extra->getName()}; new parameters must be optional and trailing."
			);
		}

		if ( null !== $return ) {
			$this->assertTrue( $fn->hasReturnType(), "{$label} dropped its return type." );
			$this->assertSame( $return, (string) $fn->getReturnType(), "{$label} changed its return type." );
		}
	}

	/**
	 * Assert a class method stays public with the given static-ness and signature.
	 *
	 * @param string      $class  Class name.
	 * @param string      $method Method name.
	 * @param bool        $static Whether it is static today.
	 * @param array       $params Expected parameters.
	 * @param string|null $return Expected return type.
	 */
	private function assertPublicMethod( string $class, string $method, bool $static, array $params, ?string $return ): void {
		$this->assertTrue( method_exists( $class, $method ), "{$class}::{$method}() was removed." );

		$ref = new ReflectionMethod( $class, $method );

		$this->assertTrue( $ref->isPublic(), "{$class}::{$method}() must stay public." );
		$this->assertSame( $static, $ref->isStatic(), "{$class}::{$method}() changed between static and instance." );
		$this->assertCompatibleSignature( $ref, $params, $return );
	}

	/**
	 * Assert a public class constant keeps its value.
	 *
	 * @param string $class Class name.
	 * @param string $name  Constant name.
	 * @param mixed  $value Expected value.
	 */
	private function assertPublicConstant( string $class, string $name, $value ): void {
		$ref = new ReflectionClass( $class );

		$this->assertTrue( $ref->hasConstant( $name ), "{$class}::{$name} was removed." );
		$this->assertTrue( $ref->getReflectionConstant( $name )->isPublic(), "{$class}::{$name} must stay public." );
		$this->assertSame( $value, $ref->getConstant( $name ), "{$class}::{$name} changed value." );
	}

	// ------------------------------------------------------------------ Plugin

	public function test_workos_function_returns_the_plugin_singleton(): void {
		// Arrange.
		$fn = new ReflectionFunction( 'workos' );

		// Act.
		$instance = workos();

		// Assert.
		$this->assertCompatibleSignature( $fn, [], 'WorkOS\Plugin' );
		$this->assertInstanceOf( Plugin::class, $instance );
		$this->assertSame( Plugin::instance(), $instance );
	}

	public function test_plugin_is_mockable_and_keeps_its_public_methods(): void {
		// The Portal mocks `workos()` (Brain Monkey + Mockery), which can't mock a final class.
		$this->assertFalse( ( new ReflectionClass( Plugin::class ) )->isFinal(), 'WorkOS\Plugin must not become final.' );

		$this->assertPublicMethod( Plugin::class, 'instance', true, [], '?self' );
		$this->assertPublicMethod( Plugin::class, 'bootstrap', true, [ self::param( 'file', 'string' ) ], 'callable' );
		$this->assertPublicMethod( Plugin::class, 'is_enabled', false, [], 'bool' );
		$this->assertPublicMethod( Plugin::class, 'api', false, [], 'WorkOS\Api\Client' );
		$this->assertPublicMethod(
			Plugin::class,
			'option',
			false,
			[ self::param( 'key', 'string' ), self::param( 'default_value', null, true, '' ) ],
			null
		);
	}

	public function test_workos_version_constant_matches_the_plugin(): void {
		$this->assertTrue( defined( 'WORKOS_VERSION' ) );
		$this->assertSame( workos()->getVersion(), WORKOS_VERSION );
	}

	// ------------------------------------------------------------- Api\Client

	public function test_api_client_methods(): void {
		$this->assertFalse( ( new ReflectionClass( Client::class ) )->isFinal(), 'WorkOS\Api\Client must not become final.' );

		$this->assertPublicMethod( Client::class, 'list_users', false, [ self::param( 'params', 'array', true, [] ) ], null );
		$this->assertPublicMethod( Client::class, 'get_base_url', true, [], 'string' );
		// README tells integrators to call this one.
		$this->assertPublicMethod( Client::class, 'verify_access_token', false, [ self::param( 'token', 'string' ) ], null );
		$this->assertSame( 'https://api.workos.com', Client::get_base_url() );
		$this->assertInstanceOf( Client::class, workos()->api() );
	}

	// ----------------------------------------------------------- Sync\UserSync

	public function test_user_sync_statics(): void {
		$this->assertPublicMethod( UserSync::class, 'sync_existing_user', true, [ self::param( 'user_id', 'int' ) ], null );
		$this->assertPublicMethod(
			UserSync::class,
			'link_user',
			true,
			[
				self::param( 'wp_user_id', 'int' ),
				self::param( 'workos_user', 'array' ),
				self::param( 'source', 'string', true, '' ),
				self::param( 'force', 'bool', true, false ),
			],
			'bool'
		);
		$this->assertPublicMethod(
			UserSync::class,
			'get_wp_user_id_by_workos_id',
			true,
			[ self::param( 'workos_id', 'string' ) ],
			'?int'
		);
	}

	// ---------------------------------------------------------------- User

	public function test_user_helper_statics(): void {
		$user_id = [ self::param( 'user_id', 'int', true, 0 ) ];

		foreach ( [ 'is_sso', 'has_active_session' ] as $method ) {
			$this->assertPublicMethod( User::class, $method, true, $user_id, 'bool' );
		}
		foreach ( [ 'get_workos_id', 'get_access_token', 'get_refresh_token', 'get_session_id', 'get_organization_id' ] as $method ) {
			$this->assertPublicMethod( User::class, $method, true, $user_id, 'string' );
		}
		$this->assertPublicMethod( User::class, 'snapshot', true, $user_id, 'array' );
	}

	public function test_user_meta_constants(): void {
		$expected = [
			'META_WORKOS_ID'     => '_workos_user_id',
			'META_ACCESS_TOKEN'  => '_workos_access_token',
			'META_REFRESH_TOKEN' => '_workos_refresh_token',
			'META_SESSION_ID'    => '_workos_session_id',
			'META_ORG_ID'        => '_workos_org_id',
			'META_LAST_SYNCED'   => '_workos_last_synced_at',
			'META_PROFILE_HASH'  => '_workos_profile_hash',
			'META_DEACTIVATED'   => '_workos_deactivated',
			'META_FIRST_LOGIN'   => '_workos_first_login',
		];

		foreach ( $expected as $name => $value ) {
			$this->assertPublicConstant( User::class, $name, $value );
		}
	}

	public function test_global_helper_functions(): void {
		$user_id = [ self::param( 'user_id', 'int', true, 0 ) ];

		$this->assertCompatibleSignature(
			new ReflectionFunction( 'workos_log' ),
			[ self::param( 'message', 'string' ), self::param( 'level', 'string', true, 'debug' ) ],
			'void'
		);
		$this->assertCompatibleSignature( new ReflectionFunction( 'workos_is_sso_user' ), $user_id, 'bool' );
		$this->assertCompatibleSignature( new ReflectionFunction( 'workos_has_active_session' ), $user_id, 'bool' );
		$this->assertCompatibleSignature( new ReflectionFunction( 'workos_get_user_id' ), $user_id, 'string' );
		$this->assertCompatibleSignature( new ReflectionFunction( 'workos_get_access_token' ), $user_id, 'string' );
	}

	// ---------------------------------------------------------------- Config

	public function test_config_credentials_getters(): void {
		$this->assertPublicMethod( Config::class, 'get_api_key', true, [], 'string' );
		$this->assertPublicMethod( Config::class, 'get_client_id', true, [], 'string' );
	}

	// ------------------------------------------------------- AuthKit Profile

	public function test_profile_slug_api(): void {
		$this->assertPublicConstant( Profile::class, 'DEFAULT_SLUG', 'default' );
		$this->assertPublicMethod( Profile::class, 'get_slug', false, [], 'string' );
	}

	// ------------------------------------------------------- Other constants

	public function test_other_portal_constants(): void {
		$this->assertPublicConstant( PendingChange::class, 'META_KEY', '_workos_pending_email_change' );
		$this->assertPublicConstant( Renderer::class, 'SCRIPT_HANDLE', 'workos-authkit' );
		$this->assertPublicConstant( Renderer::class, 'STYLE_HANDLE', 'workos-authkit' );
	}

	// ---------------------------------------------- App + Options (test fake)

	public function test_container_options_fake_used_by_portal_tests(): void {
		// The Portal's tests fake credentials with
		// App::container()->get( Options\Staging::class )->set( … ) and
		// assume the active environment defaults to staging.
		$this->assertPublicMethod( App::class, 'container', true, [], null );
		$this->assertPublicMethod( Staging::class, 'set', false, [ self::param( 'key', 'string' ), self::param( 'value', null ) ], 'void' );

		// Arrange.
		delete_option( 'workos_active_environment' );
		$staging = App::container()->get( Staging::class );
		$staging->reset();

		// Act.
		$staging->set( 'api_key', 'sk_test_contract' );
		$staging->set( 'client_id', 'client_contract' );

		// Assert.
		$this->assertInstanceOf( Staging::class, $staging );
		$this->assertSame( 'staging', Config::get_active_environment() );
		$this->assertSame( 'sk_test_contract', Config::get_api_key() );
		$this->assertSame( 'client_contract', Config::get_client_id() );

		$staging->reset();
	}

	// ------------------------------------------------------------ Load order

	public function test_user_class_is_self_contained(): void {
		// A Portal test `require_once`s src/WorkOS/User.php by path, with no autoloader.
		$ref = new ReflectionClass( User::class );

		$this->assertFalse( $ref->getParentClass(), 'WorkOS\User must not extend a class.' );
		$this->assertSame( [], $ref->getInterfaceNames(), 'WorkOS\User must not implement an interface.' );
		$this->assertSame( [], $ref->getTraitNames(), 'WorkOS\User must not use a trait.' );
		$this->assertSame( WORKOS_DIR . 'src/WorkOS/User.php', $ref->getFileName() );

		$output = $this->run_isolated_php(
			"define( 'ABSPATH', '/' );\n"
			. 'require_once ' . var_export( WORKOS_DIR . 'src/WorkOS/User.php', true ) . ";\n"
			. "echo class_exists( 'WorkOS\\\\User', false ) ? 'declared' : 'missing';\n"
		);

		$this->assertSame( 'declared', $output );
	}

	public function test_plugin_class_is_declared_when_the_main_file_loads(): void {
		// The Portal gates on class_exists( '\WorkOS\Plugin' ) before any autoloader runs.
		$output = $this->run_isolated_php(
			"define( 'ABSPATH', '/' );\n"
			. "\$GLOBALS['hooks'] = [];\n"
			. "function add_action( \$hook, \$cb ) { \$GLOBALS['hooks'][] = \$hook; }\n"
			. "function register_activation_hook( \$file, \$cb ) {}\n"
			. "function register_deactivation_hook( \$file, \$cb ) {}\n"
			. 'require_once ' . var_export( WORKOS_FILE, true ) . ";\n"
			. "echo class_exists( 'WorkOS\\\\Plugin', false ) ? 'declared' : 'missing';\n"
			. "echo ' ', implode( ',', \$GLOBALS['hooks'] );\n"
			. "echo ' ', class_exists( 'WorkOS\\\\Api\\\\Client', false ) ? 'autoloaded' : 'lazy';\n"
		);

		$this->assertSame( 'declared plugins_loaded lazy', $output );
	}

	/**
	 * Run PHP code in a fresh interpreter (no WordPress, no autoloader).
	 *
	 * @param string $code PHP code without the opening tag.
	 *
	 * @return string Trimmed stdout (stderr is appended when the process fails).
	 */
	private function run_isolated_php( string $code ): string {
		$script = wp_tempnam( 'workos-contract' );
		file_put_contents( $script, "<?php\n" . $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$output = [];
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script ) . ' 2>&1', $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		unlink( $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		$this->assertSame( 0, $status, implode( "\n", $output ) );

		return trim( implode( "\n", $output ) );
	}
}
