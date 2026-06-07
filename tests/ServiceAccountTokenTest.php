<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use OrchardGrove\PrecisionAnalytics\Support\ServiceAccountToken;
use WP_Error;

final class ServiceAccountTokenTest extends TestCase {

	public function testInvalidPrivateKeyReturnsErrorWithoutNetwork(): void {
		$result = ServiceAccountToken::fetch(
			[
				'client_email' => 'svc@project.iam.gserviceaccount.com',
				'private_key'  => 'not-a-real-key',
			],
			'https://www.googleapis.com/auth/analytics.readonly'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'precision_analytics_key', $result->get_error_code() );
	}

	public function testSignsAndExchangesWithAValidKey(): void {
		$keypair = openssl_pkey_new(
			[
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		if ( false === $keypair ) {
			$this->markTestSkipped( 'OpenSSL could not generate a test key.' );
		}
		openssl_pkey_export( $keypair, $pem );

		// Capture the JWT-bearer request and short-circuit the network call.
		$captured = null;
		\Brain\Monkey\Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$captured ) {
				$captured = $args['body'];
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);
		\Brain\Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		\Brain\Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"access_token":"ya29.test","expires_in":3600}' );

		$result = ServiceAccountToken::fetch(
			[
				'client_email' => 'svc@project.iam.gserviceaccount.com',
				'private_key'  => $pem,
			],
			'scope'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'ya29.test', $result['access_token'] );
		$this->assertSame( 3600, $result['expires_in'] );
		$this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $captured['grant_type'] );
		// A three-segment JWT was produced and sent as the assertion.
		$this->assertSame( 2, substr_count( (string) $captured['assertion'], '.' ) );
	}
}
