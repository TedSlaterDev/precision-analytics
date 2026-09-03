<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Reporting\Auth\OAuthAuth;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use WP_Error;

final class OAuthAuthTest extends TestCase {

	/** @var array<string,mixed> */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store = [];
		Functions\when( 'get_option' )->alias( fn( $n, $d = false ) => $this->store[ $n ] ?? $d );
		Functions\when( 'update_option' )->alias(
			function ( $n, $v ) {
				$this->store[ $n ] = $v;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $n ) {
				unset( $this->store[ $n ] );
				return true;
			}
		);
		Functions\when( 'admin_url' )->alias( static fn( $p = '' ) => 'https://example.com/wp-admin/' . $p );
	}

	private function auth( array $reporting = [], array $tokens = [] ): OAuthAuth {
		$this->store['precision_analytics'] = [ 'reporting' => $reporting ];
		if ( $tokens ) {
			$this->store[ OAuthAuth::TOKENS_OPTION ] = $tokens;
		}
		return new OAuthAuth( new Options() );
	}

	// --- Authorization URL ---------------------------------------------------

	public function testAuthorizationUrlRequestsOfflineConsentForTheReadonlyScope(): void {
		$url = OAuthAuth::authorizationUrl( 'cid.apps.googleusercontent.com', 'https://example.com/cb', 'st4te' );

		$this->assertStringStartsWith( OAuthAuth::AUTH_URL . '?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'cid.apps.googleusercontent.com', $q['client_id'] );
		$this->assertSame( 'https://example.com/cb', $q['redirect_uri'] );
		$this->assertSame( 'code', $q['response_type'] );
		$this->assertSame( OAuthAuth::SCOPE, $q['scope'] );
		// offline + consent are what guarantee a refresh token on every connect.
		$this->assertSame( 'offline', $q['access_type'] );
		$this->assertSame( 'consent', $q['prompt'] );
		$this->assertSame( 'st4te', $q['state'] );
	}

	public function testRedirectUriPointsAtTheCallbackAction(): void {
		$this->assertSame(
			'https://example.com/wp-admin/admin-post.php?action=precision_analytics_oauth_callback',
			OAuthAuth::redirectUri()
		);
	}

	// --- State (CSRF) ---------------------------------------------------------

	public function testStateIsSingleUseAndMustMatch(): void {
		$stored = null;
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) use ( &$stored ) {
				$stored = $v;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function () use ( &$stored ) {
				return $stored;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function () use ( &$stored ) {
				$stored = null;
				return true;
			}
		);
		Functions\when( 'wp_generate_password' )->justReturn( 'abc123' );

		$auth = $this->auth( [ 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'sec' ] );
		$auth->beginAuthorization();

		$this->assertFalse( $auth->verifyState( 'wrong' ), 'A mismatched state must be rejected.' );

		$auth->beginAuthorization();
		$this->assertTrue( $auth->verifyState( 'abc123' ) );
		// Consumed — a replay of the same state fails.
		$this->assertFalse( $auth->verifyState( 'abc123' ) );
	}

	public function testVerifyStateRejectsEmptyState(): void {
		Functions\when( 'get_transient' )->justReturn( '' );
		Functions\when( 'delete_transient' )->justReturn( true );
		$this->assertFalse( $this->auth()->verifyState( '' ) );
	}

	// --- Configuration gates ---------------------------------------------------

	public function testIsConfiguredNeedsBothAClientAndAConnection(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$this->assertFalse( $this->auth()->isConfigured(), 'no client, no token' );
		$this->assertFalse(
			$this->auth( [ 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'sec' ] )->isConfigured(),
			'client but never connected'
		);
		$this->assertFalse(
			$this->auth( [], [ 'refresh_token' => 'rt' ] )->isConfigured(),
			'connected but client credentials removed'
		);
		$this->assertTrue(
			$this->auth( [ 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'sec' ], [ 'refresh_token' => 'rt' ] )->isConfigured()
		);
	}

	public function testAccessTokenErrorsClearlyWhenNotConnected(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$err = $this->auth( [ 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'sec' ] )->accessToken();
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'precision_analytics_oauth_disconnected', $err->get_error_code() );

		$err2 = $this->auth( [], [ 'refresh_token' => 'rt' ] )->accessToken();
		$this->assertInstanceOf( WP_Error::class, $err2 );
		$this->assertSame( 'precision_analytics_oauth_client', $err2->get_error_code() );
	}

	public function testCachedAccessTokenShortCircuits(): void {
		Functions\when( 'get_transient' )->justReturn( 'cached-token' );
		$this->assertSame( 'cached-token', $this->auth()->accessToken() );
	}

	// --- Token response parsing --------------------------------------------------

	public function testParseTokenResponseAcceptsAValidBody(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

		$parsed = OAuthAuth::parseTokenResponse(
			[
				'response' => [ 'code' => 200 ],
				'body'     => (string) json_encode( [ 'access_token' => 'at', 'expires_in' => 3599, 'refresh_token' => 'rt' ] ),
			]
		);
		$this->assertSame( 'at', $parsed['access_token'] );
		$this->assertSame( 'rt', $parsed['refresh_token'] );
	}

	public function testParseTokenResponseSurfacesGooglesErrorDescription(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

		$err = OAuthAuth::parseTokenResponse(
			[
				'response' => [ 'code' => 400 ],
				'body'     => (string) json_encode( [ 'error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.' ] ),
			]
		);
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'Token has been expired or revoked.', $err->message );
	}

	public function testParseTokenResponsePassesThroughTransportErrors(): void {
		$wp_error = new WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame( $wp_error, OAuthAuth::parseTokenResponse( $wp_error ) );
	}

	public function testParseTokenResponseRejectsA200WithNoAccessToken(): void {
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => $r['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => $r['body'] );

		$err = OAuthAuth::parseTokenResponse( [ 'response' => [ 'code' => 200 ], 'body' => '{}' ] );
		$this->assertInstanceOf( WP_Error::class, $err );
	}

	// --- Disconnect ----------------------------------------------------------------

	public function testDisconnectRevokesAndForgetsTheRefreshToken(): void {
		$revoked = null;
		Functions\when( 'wp_remote_post' )->alias(
			function ( $url, $args ) use ( &$revoked ) {
				$revoked = [ 'url' => $url, 'token' => $args['body']['token'] ];
				return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );

		$auth = $this->auth( [ 'oauth_client_id' => 'cid', 'oauth_client_secret' => 'sec' ], [ 'refresh_token' => 'rt-123' ] );
		$auth->disconnect();

		$this->assertSame( OAuthAuth::REVOKE_URL, $revoked['url'] );
		$this->assertSame( 'rt-123', $revoked['token'] );
		$this->assertArrayNotHasKey( OAuthAuth::TOKENS_OPTION, $this->store );
		$this->assertFalse( $auth->isConnected() );
	}
}
