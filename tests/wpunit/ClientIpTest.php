<?php
/**
 * Tests for client IP resolution.
 *
 * @package WorkOS\Tests\Wpunit
 */

namespace WorkOS\Tests\Wpunit;

use Codeception\Attribute\DataProvider;
use lucatume\WPBrowser\TestCase\WPTestCase;
use WorkOS\Http\ClientIp;

/**
 * Who the request came from, and who gets to decide that.
 *
 * The security-relevant cases here are the ones where a header is present
 * but not configured: a forwarded-for header is caller-supplied, so
 * trusting it without an operator opting in would let anyone mint a fresh
 * rate-limit bucket, or forge the address in the activity log, per request.
 */
class ClientIpTest extends WPTestCase {

	/**
	 * Resolver trusting nothing but REMOTE_ADDR.
	 *
	 * @var ClientIp
	 */
	private ClientIp $client_ip;

	/**
	 * Server state to restore.
	 *
	 * @var array
	 */
	private array $server_backup = [];

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->server_backup = $_SERVER;
		$this->client_ip     = new ClientIp();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$_SERVER = $this->server_backup;

		parent::tearDown();
	}

	/**
	 * A resolver for an operator who has named the header their proxy
	 * overwrites.
	 *
	 * @param string $header Header name.
	 *
	 * @return ClientIp
	 */
	private function trusting( string $header ): ClientIp {
		return new ClientIp( $header );
	}

	/**
	 * With nothing configured, REMOTE_ADDR is the answer.
	 */
	public function test_uses_remote_addr_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.4';

		$this->assertSame( '203.0.113.4', $this->client_ip->get() );
	}

	/**
	 * A forwarded header is ignored unless an operator names it. This is
	 * the spoofing case: trusting it by default would hand the bucket key
	 * to the caller.
	 *
	 * @param string $header Header a caller might try.
	 */
	#[DataProvider( 'spoofable_header_provider' )]
	public function test_ignores_untrusted_headers( string $header ): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.4';
		$_SERVER[ $header ]     = '198.51.100.99';

		$this->assertSame( '203.0.113.4', $this->client_ip->get() );
	}

	/**
	 * Once named, the header wins, because the proxy overwrites it.
	 */
	public function test_uses_configured_header(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.4';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.99';

		$this->assertSame( '198.51.100.99', $this->trusting( 'CF-Connecting-IP' )->get() );
	}

	/**
	 * In a list, the right-most public entry wins. Everything left of the
	 * infrastructure hops is whatever the caller chose to send.
	 */
	public function test_reads_right_most_public_entry_from_a_list(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.4';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.5.5.5, 198.51.100.99';

		$this->assertSame( '198.51.100.99', $this->trusting( 'X-Forwarded-For' )->get() );
	}

	/**
	 * Private hops don't shadow the client: they're skipped while walking
	 * leftwards.
	 */
	public function test_skips_private_hops_in_a_list(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.4';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '5.5.5.5, 198.51.100.99, 10.0.0.1';

		$this->assertSame( '198.51.100.99', $this->trusting( 'X-Forwarded-For' )->get() );
	}

	/**
	 * An all-private list still yields the right-most valid entry, which
	 * is the local-proxy case in development.
	 */
	public function test_falls_back_to_right_most_valid_when_all_private(): void {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.4';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 192.168.1.7';

		$this->assertSame( '192.168.1.7', $this->trusting( 'X-Forwarded-For' )->get() );
	}

	/**
	 * A configured header that isn't present falls through rather than
	 * failing.
	 */
	public function test_falls_back_to_remote_addr_when_header_absent(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.4';

		$this->assertSame( '203.0.113.4', $this->trusting( 'CF-Connecting-IP' )->get() );
	}

	/**
	 * Garbage in the trusted header doesn't win either.
	 */
	public function test_falls_back_when_header_holds_no_valid_address(): void {
		$_SERVER['REMOTE_ADDR']           = '203.0.113.4';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip, also-not';

		$this->assertSame( '203.0.113.4', $this->trusting( 'CF-Connecting-IP' )->get() );
	}

	/**
	 * IPv6 survives round-tripping.
	 */
	public function test_accepts_ipv6(): void {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';

		$this->assertSame( '2001:db8::1', $this->client_ip->get() );
	}

	/**
	 * Missing or unusable REMOTE_ADDR resolves to the sentinel rather than
	 * an empty subject, so callers still have something stable to key on.
	 *
	 * @param mixed $value REMOTE_ADDR value, or null to unset it.
	 */
	#[DataProvider( 'unusable_remote_addr_provider' )]
	public function test_unusable_remote_addr_returns_sentinel( $value ): void {
		if ( null === $value ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $value;
		}

		$this->assertSame( ClientIp::UNKNOWN, $this->client_ip->get() );
	}

	/**
	 * The container hands out one configured resolver.
	 *
	 * With no header named, the bound instance must trust nothing but
	 * REMOTE_ADDR, which is the safe posture for a site that never
	 * configures this.
	 */
	public function test_container_resolves_a_configured_singleton(): void {
		$resolved = \WorkOS\App::container()->get( ClientIp::class );

		$this->assertInstanceOf( ClientIp::class, $resolved );
		$this->assertSame( $resolved, \WorkOS\App::container()->get( ClientIp::class ) );

		$_SERVER['REMOTE_ADDR']          = '203.0.113.4';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		$this->assertSame( '203.0.113.4', $resolved->get() );
	}

	// -------------------------------------------------------------------------
	// Providers
	// -------------------------------------------------------------------------

	/**
	 * Headers a caller can set that must not be trusted by default.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function spoofable_header_provider(): array {
		return [
			'X-Forwarded-For' => [ 'HTTP_X_FORWARDED_FOR' ],
			'CF-Connecting-IP' => [ 'HTTP_CF_CONNECTING_IP' ],
			'X-Real-IP'       => [ 'HTTP_X_REAL_IP' ],
			'Client-IP'       => [ 'HTTP_CLIENT_IP' ],
		];
	}

	/**
	 * REMOTE_ADDR values that yield no usable address.
	 *
	 * @return array<string, array{0:mixed}>
	 */
	public function unusable_remote_addr_provider(): array {
		return [
			'unset'   => [ null ],
			'empty'   => [ '' ],
			'garbage' => [ 'not-an-ip' ],
			'list'    => [ '203.0.113.4, 198.51.100.99' ],
		];
	}
}
