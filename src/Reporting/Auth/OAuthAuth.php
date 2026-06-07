<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting\Auth;

use OrchardGrove\PrecisionAnalytics\Settings\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Interactive OAuth auth — a conforming stub for now. Implementing this
 * (Connect-with-Google + refresh tokens) is on the roadmap; the rest of the
 * reporting pipeline is already written against AuthInterface so it can drop in
 * without further changes.
 */
final class OAuthAuth implements AuthInterface {

	public function __construct( private Options $options ) {}

	public function isConfigured(): bool {
		return false;
	}

	public function accessToken(): string|WP_Error {
		return new WP_Error(
			'precision_analytics_oauth',
			__( 'OAuth sign-in is not available yet — use the service-account method.', 'precision-analytics' )
		);
	}
}
