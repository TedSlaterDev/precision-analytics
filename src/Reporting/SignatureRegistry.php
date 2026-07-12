<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which non-default popular-posts windows the site has actually
 * served, so the sync cron can keep their caches fresh. Without this, a
 * `window="48h"` list would be fetched live once and then never refreshed.
 *
 * Windows only: the GA4 request (and therefore the cache payload) does not
 * depend on post_type — that filter is applied at read time — so variations
 * are tracked per window. Callers must pass a CANONICAL window (see
 * Reports::canonicalWindow()), which keeps typo'd inputs from ever reaching
 * the registry.
 *
 * Bounded and self-cleaning: at most MAX_SIGNATURES windows, writes are
 * throttled so steady-state renders touch the database zero times, and
 * windows not served for a week are pruned by the cron.
 */
final class SignatureRegistry {

	public const OPTION = 'precision_analytics_signatures';

	/** Hard cap on tracked windows — also caps the cron's extra API calls. */
	public const MAX_SIGNATURES = 20;

	/** Re-stamp `served` at most every 12 hours (throttles front-end writes). */
	private const TOUCH_INTERVAL = 43200;

	/** Drop windows not served within a week. */
	private const STALE_AFTER = 604800;

	/** Record (or freshen) a served window. Cheap no-op in the steady state. */
	public static function touch( string $window ): void {
		$all = self::raw();
		$now = time();

		if ( isset( $all[ $window ] ) && is_array( $all[ $window ] ) ) {
			if ( $now - (int) ( $all[ $window ]['served'] ?? 0 ) < self::TOUCH_INTERVAL ) {
				return;
			}
		} elseif ( count( $all ) >= self::MAX_SIGNATURES ) {
			return;
		}

		$all[ $window ] = [
			'window' => $window,
			'served' => $now,
		];
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Every tracked window, for the sync cron to refresh.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$out = [];
		foreach ( self::raw() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['window'] ) && '' !== (string) $entry['window'] ) {
				$out[] = (string) $entry['window'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Drop stale windows (run from the sync cron, off the request path). */
	public static function prune(): void {
		$all  = self::raw();
		$now  = time();
		$kept = [];
		foreach ( $all as $key => $entry ) {
			if ( is_array( $entry ) && $now - (int) ( $entry['served'] ?? 0 ) <= self::STALE_AFTER ) {
				$kept[ $key ] = $entry;
			}
		}
		if ( count( $kept ) !== count( $all ) ) {
			update_option( self::OPTION, $kept, false );
		}
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/** @return array<string,mixed> */
	private static function raw(): array {
		$all = get_option( self::OPTION, [] );
		return is_array( $all ) ? $all : [];
	}
}
