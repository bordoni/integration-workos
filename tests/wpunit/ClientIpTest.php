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
 * Which address a request is attributed to.
 *
 * @covers \WorkOS\Http\ClientIp
 */
class ClientIpTest extends WPTestCase {

	/**
	 * Resolver under test.
	 *
	 * @var ClientIp
	 */
	private ClientIp $client_ip;

	/**
	 * Set up each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->client_ip = new ClientIp();
	}

	/**
	 * Which address a request is attributed to.
	 *
	 * @param string|null $remote_addr  REMOTE_ADDR, or null when absent.
	 * @param string|null $trusted      Header an operator has declared trusted.
	 * @param string|null $header_value Value that header carries.
	 * @param string      $expected     Address the resolver should return.
	 */
	#[DataProvider( 'clientIpCases' )]
	public function test_client_ip_resolution( ?string $remote_addr, ?string $trusted, ?string $header_value, string $expected ): void {
		$previous = $_SERVER['REMOTE_ADDR'] ?? null;

		if ( null === $remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $remote_addr;
		}

		if ( null !== $header_value ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $header_value;
		}

		$filter = static fn(): string => (string) $trusted;

		if ( null !== $trusted ) {
			add_filter( 'workos_trusted_proxy_header', $filter );
		}

		try {
			$this->assertSame( $expected, $this->client_ip->get() );
		} finally {
			if ( null !== $trusted ) {
				remove_filter( 'workos_trusted_proxy_header', $filter );
			}

			unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

			if ( null === $previous ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $previous;
			}
		}
	}

	// ------------------------------------------------------------ data providers

	/**
	 * Request conditions paired with the address they resolve to.
	 *
	 * Columns: REMOTE_ADDR, trusted header name, header value, expected.
	 *
	 * @return array<string, array{0:?string, 1:?string, 2:?string, 3:string}>
	 */
	public function clientIpCases(): array {
		return [
			'remote addr only' => [
				'203.0.113.4',
				null,
				null,
				'203.0.113.4',
			],
			'no remote addr at all' => [
				null,
				null,
				null,
				'0.0.0.0',
			],
			'undeclared header is ignored' => [
				'203.0.113.4',
				null,
				'198.51.100.7',
				'203.0.113.4',
			],
			'declared header wins' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'198.51.100.7',
				'198.51.100.7',
			],
			'declared header absent falls back' => [
				'203.0.113.4',
				'HTTP_X_FORWARDED_FOR',
				null,
				'203.0.113.4',
			],
			'declared header is garbage' => [
				'203.0.113.4',
				'HTTP_X_FORWARDED_FOR',
				'not-an-ip',
				'203.0.113.4',
			],
			'spoofed prefix is not trusted' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'6.6.6.1, 198.51.100.7',
				'198.51.100.7',
			],
			'private tail is skipped' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'6.6.6.1, 198.51.100.7, 10.0.0.2',
				'198.51.100.7',
			],
			'multiple private hops are skipped' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'6.6.6.1, 198.51.100.7, 10.0.0.2, 172.16.0.9',
				'198.51.100.7',
			],
			'all private falls back to the right-most' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'10.0.0.1, 192.168.1.7',
				'192.168.1.7',
			],
			'single entry is unaffected' => [
				'10.0.0.5',
				'HTTP_X_FORWARDED_FOR',
				'198.51.100.7',
				'198.51.100.7',
			],
		];
	}
}
