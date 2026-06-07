<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting\Auth;

use OrchardGrove\PrecisionAnalytics\Settings\Options;
use OrchardGrove\PrecisionAnalytics\Support\ServiceAccountToken;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Service-account auth. Reads the JSON key from the
 * PA_GA4_SERVICE_ACCOUNT_JSON constant (preferred, keeps it out of the DB) or
 * the stored option, mints a token, and caches it as a transient until expiry.
 */
final class ServiceAccountAuth implements AuthInterface {

	private const SCOPE           = 'https://www.googleapis.com/auth/analytics.readonly';
	private const TOKEN_TRANSIENT = 'precision_analytics_token';

	public function __construct( private Options $options ) {}

	public function isConfigured(): bool {
		return null !== $this->keyArray();
	}

	public function accessToken(): string|WP_Error {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$key = $this->keyArray();
		if ( null === $key ) {
			return new WP_Error( 'precision_analytics_no_key', __( 'No GA4 service account is configured.', 'precision-analytics' ) );
		}

		$result = ServiceAccountToken::fetch( $key, self::SCOPE );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$ttl = max( 60, $result['expires_in'] - 60 );
		set_transient( self::TOKEN_TRANSIENT, $result['access_token'], $ttl );

		return $result['access_token'];
	}

	/** Forget the cached token (call when the key changes). */
	public static function forgetToken(): void {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/** @return array<string,mixed>|null */
	private function keyArray(): ?array {
		if ( defined( 'PA_GA4_SERVICE_ACCOUNT_JSON' ) && is_string( PA_GA4_SERVICE_ACCOUNT_JSON ) ) {
			$json = PA_GA4_SERVICE_ACCOUNT_JSON;
		} else {
			$json = $this->options->str( 'reporting.service_account_json' );
		}

		$json = trim( $json );
		if ( '' === $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
			return null;
		}
		return $data;
	}
}
