<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Credential strategy for the GA4 Data API. Swappable so a service account or
 * interactive OAuth can back the same reporting pipeline.
 */
interface AuthInterface {

	/** Whether enough credentials are present to attempt a token. */
	public function isConfigured(): bool;

	/**
	 * A bearer access token for the GA4 Data API.
	 *
	 * @return string|WP_Error
	 */
	public function accessToken(): string|WP_Error;
}
