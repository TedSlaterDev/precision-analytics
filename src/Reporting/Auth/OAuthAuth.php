<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting\Auth;

use OrchardGrove\PrecisionAnalytics\Settings\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "Sign in with Google" auth for the GA4 Data API, without a third-party
 * broker: the site owner creates an OAuth client in their own Google Cloud
 * project, pastes its ID + secret, and clicks Connect. The authorization-code
 * flow returns a long-lived refresh token (stored in a non-autoloaded option)
 * that mints short-lived access tokens, cached as a transient until expiry.
 *
 * The client ID/secret may live in wp-config.php instead of the database via
 * the PA_GA4_OAUTH_CLIENT_ID / PA_GA4_OAUTH_CLIENT_SECRET constants.
 */
final class OAuthAuth implements AuthInterface {

	public const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

	public const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	public const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	/** Refresh token + metadata (autoload=false). */
	public const TOKENS_OPTION = 'precision_analytics_oauth';

	private const TOKEN_TRANSIENT = 'precision_analytics_oauth_token';
	private const STATE_TRANSIENT = 'precision_analytics_oauth_state';

	public function __construct( private Options $options ) {}

	// --- AuthInterface --------------------------------------------------------

	public function isConfigured(): bool {
		return $this->hasClient() && $this->isConnected();
	}

	public function accessToken(): string|WP_Error {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		if ( ! $this->hasClient() ) {
			return new WP_Error( 'precision_analytics_oauth_client', __( 'No Google OAuth client ID and secret are configured.', 'precision-analytics' ) );
		}
		$refresh = $this->refreshToken();
		if ( '' === $refresh ) {
			return new WP_Error( 'precision_analytics_oauth_disconnected', __( 'Google is not connected — click "Connect Google Analytics" on the Reporting tab.', 'precision-analytics' ) );
		}

		$result = $this->tokenRequest(
			[
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->cacheAccessToken( $result );
		return (string) $result['access_token'];
	}

	// --- Connect / disconnect ------------------------------------------------

	public function hasClient(): bool {
		return '' !== $this->clientId() && '' !== $this->clientSecret();
	}

	public function isConnected(): bool {
		return '' !== $this->refreshToken();
	}

	/** Unix time of the last successful connect, 0 if never. */
	public function connectedAt(): int {
		return (int) ( $this->tokens()['connected_at'] ?? 0 );
	}

	/** The redirect URI to register on the Google Cloud OAuth client. */
	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=precision_analytics_oauth_callback' );
	}

	/** Start the flow: mint a state nonce (10 min) and return the Google consent URL. */
	public function beginAuthorization(): string {
		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT, $state, 10 * MINUTE_IN_SECONDS );
		return self::authorizationUrl( $this->clientId(), self::redirectUri(), $state );
	}

	/** Pure URL builder (unit-tested). */
	public static function authorizationUrl( string $client_id, string $redirect_uri, string $state ): string {
		return self::AUTH_URL . '?' . http_build_query(
			[
				'client_id'              => $client_id,
				'redirect_uri'           => $redirect_uri,
				'response_type'          => 'code',
				'scope'                  => self::SCOPE,
				'access_type'            => 'offline',  // Ask for a refresh token…
				'prompt'                 => 'consent',  // …and always get one, even on re-connect.
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/** Consume the one-time state nonce; true only if it matches. */
	public function verifyState( string $state ): bool {
		$expected = get_transient( self::STATE_TRANSIENT );
		delete_transient( self::STATE_TRANSIENT );
		return is_string( $expected ) && '' !== $expected && hash_equals( $expected, $state );
	}

	/**
	 * Finish the flow: exchange the authorization code for tokens and store
	 * the refresh token.
	 *
	 * @return true|WP_Error
	 */
	public function exchangeCode( string $code ): bool|WP_Error {
		$result = $this->tokenRequest(
			[
				'grant_type'   => 'authorization_code',
				'code'         => $code,
				'redirect_uri' => self::redirectUri(),
			]
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['refresh_token'] ) ) {
			return new WP_Error( 'precision_analytics_oauth_no_refresh', __( 'Google did not return a refresh token. Remove the app under your Google Account → Security → Third-party access, then connect again.', 'precision-analytics' ) );
		}

		update_option(
			self::TOKENS_OPTION,
			[
				'refresh_token' => (string) $result['refresh_token'],
				'connected_at'  => time(),
			],
			false
		);
		$this->cacheAccessToken( $result );
		return true;
	}

	/** Revoke (best effort) and forget the stored tokens. */
	public function disconnect(): void {
		$refresh = $this->refreshToken();
		if ( '' !== $refresh ) {
			wp_remote_post(
				self::REVOKE_URL,
				[
					'timeout' => 10,
					'body'    => [ 'token' => $refresh ],
				]
			);
		}
		delete_option( self::TOKENS_OPTION );
		self::forgetToken();
	}

	/** Forget the cached access token (call when the client changes). */
	public static function forgetToken(): void {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	// --- Internals -------------------------------------------------------------

	/**
	 * POST to the token endpoint with the client credentials merged in.
	 *
	 * @param array<string,string> $body
	 * @return array<string,mixed>|WP_Error Decoded token response.
	 */
	private function tokenRequest( array $body ): array|WP_Error {
		$response = wp_remote_post(
			self::TOKEN_URL,
			[
				'timeout' => 15,
				'body'    => $body + [
					'client_id'     => $this->clientId(),
					'client_secret' => $this->clientSecret(),
				],
			]
		);
		return self::parseTokenResponse( $response );
	}

	/**
	 * Pure parser (unit-tested): a token response → array or WP_Error.
	 *
	 * @param array<string,mixed>|WP_Error $response wp_remote_post() result.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function parseTokenResponse( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$detail = is_array( $data ) && ! empty( $data['error_description'] )
				? (string) $data['error_description']
				: ( is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : __( 'The Google token request failed.', 'precision-analytics' ) );
			return new WP_Error( 'precision_analytics_oauth_token', $detail, [ 'status' => $code ] );
		}
		return $data;
	}

	/** @param array<string,mixed> $result */
	private function cacheAccessToken( array $result ): void {
		$ttl = max( 60, (int) ( $result['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT, (string) $result['access_token'], $ttl );
	}

	/** @return array<string,mixed> */
	private function tokens(): array {
		$stored = get_option( self::TOKENS_OPTION, [] );
		return is_array( $stored ) ? $stored : [];
	}

	private function refreshToken(): string {
		return trim( (string) ( $this->tokens()['refresh_token'] ?? '' ) );
	}

	private function clientId(): string {
		if ( defined( 'PA_GA4_OAUTH_CLIENT_ID' ) && is_string( PA_GA4_OAUTH_CLIENT_ID ) ) {
			return trim( PA_GA4_OAUTH_CLIENT_ID );
		}
		return trim( $this->options->str( 'reporting.oauth_client_id' ) );
	}

	private function clientSecret(): string {
		if ( defined( 'PA_GA4_OAUTH_CLIENT_SECRET' ) && is_string( PA_GA4_OAUTH_CLIENT_SECRET ) ) {
			return trim( PA_GA4_OAUTH_CLIENT_SECRET );
		}
		return trim( $this->options->str( 'reporting.oauth_client_secret' ) );
	}
}
