<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Mints a Google OAuth2 access token from a service-account key using the
 * JWT-bearer grant. RS256 signing is done with ext-openssl, so there is no
 * runtime Composer dependency.
 */
final class ServiceAccountToken {

	private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

	/**
	 * @param array<string,mixed> $key   Decoded service-account JSON.
	 * @param string              $scope Space-separated OAuth scopes.
	 * @return array{access_token:string,expires_in:int}|WP_Error
	 */
	public static function fetch( array $key, string $scope ): array|WP_Error {
		$token_uri = is_string( $key['token_uri'] ?? null ) && '' !== $key['token_uri']
			? (string) $key['token_uri']
			: self::DEFAULT_TOKEN_URI;

		$now    = time();
		$claims = [
			'iss'   => (string) ( $key['client_email'] ?? '' ),
			'scope' => $scope,
			'aud'   => $token_uri,
			'iat'   => $now,
			'exp'   => $now + 3600,
		];

		$jwt = self::encodeJwt( $claims, (string) ( $key['private_key'] ?? '' ) );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			$token_uri,
			[
				'timeout' => 15,
				'body'    => [
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$message = is_array( $body ) && isset( $body['error_description'] )
				? (string) $body['error_description']
				: __( 'Could not obtain a Google access token from the service account.', 'precision-analytics' );
			return new WP_Error( 'precision_analytics_token', $message, [ 'status' => $code ] );
		}

		return [
			'access_token' => (string) $body['access_token'],
			'expires_in'   => (int) ( $body['expires_in'] ?? 3600 ),
		];
	}

	/**
	 * @param array<string,mixed> $claims
	 * @return string|WP_Error
	 */
	private static function encodeJwt( array $claims, string $private_key ): string|WP_Error {
		$header   = [ 'alg' => 'RS256', 'typ' => 'JWT' ];
		$segments = [
			self::base64Url( (string) wp_json_encode( $header ) ),
			self::base64Url( (string) wp_json_encode( $claims ) ),
		];
		$input = implode( '.', $segments );

		$resource = openssl_pkey_get_private( $private_key );
		if ( false === $resource ) {
			return new WP_Error( 'precision_analytics_key', __( 'The service-account private key is invalid.', 'precision-analytics' ) );
		}

		$signature = '';
		$signed    = openssl_sign( $input, $signature, $resource, OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return new WP_Error( 'precision_analytics_sign', __( 'Could not sign the authentication token.', 'precision-analytics' ) );
		}

		$segments[] = self::base64Url( $signature );
		return implode( '.', $segments );
	}

	private static function base64Url( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
