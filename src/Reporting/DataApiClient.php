<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\Reporting\Auth\AuthInterface;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Thin client for the GA4 Data API `runReport` method.
 */
final class DataApiClient {

	private const ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta/properties/';

	public function __construct(
		private AuthInterface $auth,
		private string $property_id
	) {}

	/**
	 * @param array<string,mixed> $request runReport request body.
	 * @return array<string,mixed>|WP_Error Decoded response.
	 */
	public function runReport( array $request ): array|WP_Error {
		if ( '' === trim( $this->property_id ) ) {
			return new WP_Error( 'precision_analytics_no_property', __( 'No GA4 property ID is set.', 'precision-analytics' ) );
		}

		$token = $this->auth->accessToken();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::ENDPOINT . rawurlencode( $this->property_id ) . ':runReport';

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $request ),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = is_array( $body ) && isset( $body['error']['message'] )
				? (string) $body['error']['message']
				: __( 'The GA4 Data API request failed.', 'precision-analytics' );
			return new WP_Error( 'precision_analytics_api', $message, [ 'status' => $code ] );
		}

		return is_array( $body ) ? $body : [];
	}
}
