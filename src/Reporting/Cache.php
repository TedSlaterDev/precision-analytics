<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

defined( 'ABSPATH' ) || exit;

/**
 * Persistent store for synced GA4 report payloads, keyed by query signature.
 *
 * Backed by a single option (not transients) so a failed sync can leave the
 * previous good data in place. Front-end surfaces read from here; a signature
 * never fetched before triggers a one-time warm-up fetch, and an API error with
 * no prior data is negative-cached (an empty placeholder with a `retry_after`
 * timestamp) so page views don't hammer the API during an outage. Freshness is
 * governed by the sync cron cadence, recorded per entry.
 */
final class Cache {

	public const OPTION = 'precision_analytics_reports';

	/** @return array{data:mixed,fetched:int}|null */
	public static function entry( string $signature ): ?array {
		$all = get_option( self::OPTION, [] );
		if ( is_array( $all ) && isset( $all[ $signature ] ) && is_array( $all[ $signature ] ) ) {
			return $all[ $signature ];
		}
		return null;
	}

	public static function get( string $signature ): mixed {
		$entry = self::entry( $signature );
		return $entry['data'] ?? null;
	}

	public static function fetchedAt( string $signature ): int {
		$entry = self::entry( $signature );
		return (int) ( $entry['fetched'] ?? 0 );
	}

	public static function put( string $signature, mixed $data, int $now ): void {
		$all = get_option( self::OPTION, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}
		$all[ $signature ] = [
			'data'    => $data,
			'fetched' => $now,
		];
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Store an error placeholder: empty data servable until `retry_after`, so a
	 * failing API isn't retried on every page view. Never clobbers good data —
	 * stale-on-error keeps serving the last successful payload instead.
	 */
	public static function putNegative( string $signature, int $retry_after ): void {
		$all = get_option( self::OPTION, [] );
		if ( ! is_array( $all ) ) {
			$all = [];
		}
		$existing = $all[ $signature ] ?? null;
		if ( is_array( $existing ) && (int) ( $existing['fetched'] ?? 0 ) > 0 ) {
			return;
		}
		$all[ $signature ] = [
			'data'        => [],
			'fetched'     => 0,
			'retry_after' => $retry_after,
		];
		update_option( self::OPTION, $all, false );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
