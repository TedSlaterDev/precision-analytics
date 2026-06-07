<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Cli;

use OrchardGrove\PrecisionAnalytics\Reporting\Reports;
use OrchardGrove\PrecisionAnalytics\Reporting\Sync;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Precision Analytics WP-CLI commands. Registered only when WP-CLI is loaded.
 */
final class Commands {

	public static function register(): void {
		\WP_CLI::add_command( 'precision-analytics', self::class );
	}

	/**
	 * Fetch the GA4 reports now and refresh the cache.
	 *
	 * ## EXAMPLES
	 *     wp precision-analytics sync
	 */
	public function sync(): void {
		$reports = new Reports( new Options() );
		if ( ! $reports->isConfigured() ) {
			\WP_CLI::error( 'Reporting is not configured — set a GA4 property ID and credentials first.' );
		}
		$reports->syncAll();
		\WP_CLI::success( 'GA4 reports synced.' );
	}

	/**
	 * Show the current configuration and last sync time.
	 *
	 * ## EXAMPLES
	 *     wp precision-analytics status
	 */
	public function status(): void {
		$options = new Options();
		$reports = new Reports( $options );

		$transport = $options->str( 'general.transport', 'gtag' );
		$id        = 'gtm' === $transport ? $options->str( 'general.gtm_id' ) : $options->str( 'general.measurement_id' );

		\WP_CLI::log( 'Transport:        ' . $transport );
		\WP_CLI::log( 'Tracking ID:      ' . ( '' !== $id ? $id : '(not set)' ) );
		\WP_CLI::log( 'Sampling:         ' . ( $options->bool( 'sampling.enabled' ) ? $options->int( 'sampling.rate', 100 ) . '%' : 'off (track everyone)' ) );
		\WP_CLI::log( 'Consent Mode v2:  ' . ( $options->bool( 'consent.enabled' ) ? 'on' : 'off' ) );
		\WP_CLI::log( 'Reporting:        ' . ( $reports->isConfigured() ? 'configured' : 'not configured' ) );
		\WP_CLI::log( 'GA4 property:     ' . ( '' !== $options->str( 'reporting.property_id' ) ? $options->str( 'reporting.property_id' ) : '(not set)' ) );

		$last = $reports->lastSync();
		\WP_CLI::log( 'Last sync:        ' . ( $last > 0 ? wp_date( 'Y-m-d H:i', $last ) : 'never' ) );

		$next = wp_next_scheduled( Sync::HOOK );
		\WP_CLI::log( 'Next sync:        ' . ( $next ? wp_date( 'Y-m-d H:i', (int) $next ) : 'not scheduled' ) );
	}
}
