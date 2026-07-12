<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\Reporting\Auth\AuthInterface;
use OrchardGrove\PrecisionAnalytics\Reporting\Auth\OAuthAuth;
use OrchardGrove\PrecisionAnalytics\Reporting\Auth\ServiceAccountAuth;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Typed reads over the cached GA4 reports, plus the refresh methods the sync
 * cron calls. Front-end consumers call the read methods only; a cache miss
 * triggers a single best-effort live fetch so data appears before the first
 * cron tick, and a non-default window is registered so the cron keeps it fresh
 * from then on.
 *
 * Failure behavior: API errors fall through to the last good cached value —
 * or, when there is none, are negative-cached briefly so page views don't
 * retry the API. The retry window is armed BEFORE a live fetch, so concurrent
 * requests during the (up to 20s) in-flight call serve empty instead of
 * stacking their own API calls.
 *
 * Cache keys are per WINDOW only. The GA4 request doesn't depend on
 * post_type — that filter is applied at read time in hydrate() — so keying by
 * post_type would only duplicate identical payloads and API calls.
 */
final class Reports {

	/** Windows the sync cron always keeps warm. */
	public const SYNC_WINDOWS = [ '12h', '24h', '7d', '30d' ];

	/** Seconds an API error (or in-flight fetch) blocks further live attempts. */
	public const ERROR_RETRY_DELAY = 60;

	/** Ranked rows stored per window — plenty for count<=50 even after filtering. */
	public const MAX_RANKED = 500;

	public function __construct(
		private Options $options,
		private ?DataApiClient $client = null
	) {}

	public function isConfigured(): bool {
		return $this->options->bool( 'reporting.enabled' )
			&& '' !== trim( $this->options->str( 'reporting.property_id' ) )
			&& $this->auth()->isConfigured();
	}

	/** Newest fetch timestamp across all cached entries (0 if never). */
	public function lastSync(): int {
		$all  = get_option( Cache::OPTION, [] );
		$last = 0;
		if ( is_array( $all ) ) {
			foreach ( $all as $entry ) {
				$last = max( $last, (int) ( $entry['fetched'] ?? 0 ) );
			}
		}
		return $last;
	}

	// --- Summary ----------------------------------------------------------

	/** @return array{totalUsers:int,sessions:int,screenPageViews:int} */
	public function summary(): array {
		$data = self::servable( 'summary:28d' );
		if ( null === $data ) {
			self::arm( 'summary:28d' );
			$data = $this->refreshSummary();
		}
		return [
			'totalUsers'      => (int) ( $data['totalUsers'] ?? 0 ),
			'sessions'        => (int) ( $data['sessions'] ?? 0 ),
			'screenPageViews' => (int) ( $data['screenPageViews'] ?? 0 ),
		];
	}

	/** @return array<string,int> */
	public function refreshSummary(): array {
		$request = [
			'dateRanges' => [ [ 'startDate' => '28daysAgo', 'endDate' => 'today' ] ],
			'metrics'    => [ [ 'name' => 'totalUsers' ], [ 'name' => 'sessions' ], [ 'name' => 'screenPageViews' ] ],
		];

		$response = $this->client()->runReport( $request );
		if ( is_wp_error( $response ) ) {
			return $this->lastGoodOrNegative( 'summary:28d' );
		}

		$values = $response['rows'][0]['metricValues'] ?? [];
		$data   = [
			'totalUsers'      => (int) ( $values[0]['value'] ?? 0 ),
			'sessions'        => (int) ( $values[1]['value'] ?? 0 ),
			'screenPageViews' => (int) ( $values[2]['value'] ?? 0 ),
		];
		Cache::put( 'summary:28d', $data, time() );
		return $data;
	}

	// --- Popular posts ----------------------------------------------------

	/**
	 * Ranked posts for a window. $window is like "12h" (hours) or "7d" (days);
	 * unparseable input falls back to the default 7d window.
	 *
	 * @return array<int,array{post:WP_Post,views:int}>
	 */
	public function popularPosts( string $window = '7d', int $count = 5, string $post_type = '' ): array {
		$window = self::canonicalWindow( $window );

		// Register non-default windows so the sync cron keeps them fresh.
		if ( ! in_array( $window, self::SYNC_WINDOWS, true ) ) {
			SignatureRegistry::touch( $window );
		}

		$signature = self::popularSignature( $window );
		$ranked    = self::servable( $signature );
		if ( null === $ranked ) {
			self::arm( $signature );
			$ranked = $this->refreshPopularPosts( $window );
		}
		return $this->hydrate( is_array( $ranked ) ? $ranked : [], max( 1, $count ), $post_type );
	}

	/**
	 * Fetch, rank, and cache the popular-posts list for a window.
	 *
	 * @return array<int,array{key:string,views:int}>
	 */
	public function refreshPopularPosts( string $window ): array {
		$window    = self::canonicalWindow( $window );
		$signature = self::popularSignature( $window );
		$spec      = self::windowSpec( $window );
		$by_id     = $this->trackingByPostId();
		$dimension = $by_id ? 'customEvent:post_id' : 'pagePath';

		if ( 'hour' === $spec['mode'] ) {
			$days_back = (int) ceil( $spec['amount'] / 24 ) + 1;
			$request   = [
				'dateRanges' => [ [ 'startDate' => $days_back . 'daysAgo', 'endDate' => 'today' ] ],
				'dimensions' => [ [ 'name' => $dimension ], [ 'name' => 'dateHour' ] ],
				'metrics'    => [ [ 'name' => 'screenPageViews' ] ],
				'limit'      => 10000,
			];
			$cutoff = $this->hourCutoff( $spec['amount'] );
		} else {
			$request = [
				'dateRanges' => [ [ 'startDate' => $spec['amount'] . 'daysAgo', 'endDate' => 'today' ] ],
				'dimensions' => [ [ 'name' => $dimension ] ],
				'metrics'    => [ [ 'name' => 'screenPageViews' ] ],
				'orderBys'   => [ [ 'desc' => true, 'metric' => [ 'metricName' => 'screenPageViews' ] ] ],
				'limit'      => 100,
			];
			$cutoff = '';
		}

		$response = $this->client()->runReport( $request );
		if ( is_wp_error( $response ) ) {
			return $this->lastGoodOrNegative( $signature );
		}

		// Cap the stored list: hour-mode aggregation can rank thousands of
		// keys, but reads never need more than MAX_RANKED even after
		// post-type filtering — don't bloat the cache option.
		$ranked = array_slice( self::rankRows( $response, 'hour' === $spec['mode'], $cutoff ), 0, self::MAX_RANKED );
		Cache::put( $signature, $ranked, time() );
		return $ranked;
	}

	/**
	 * Verify credentials with a minimal live request.
	 *
	 * @return true|\WP_Error
	 */
	public function testConnection(): bool|\WP_Error {
		if ( '' === trim( $this->options->str( 'reporting.property_id' ) ) ) {
			return new \WP_Error( 'precision_analytics_no_property', __( 'No GA4 property ID is set.', 'precision-analytics' ) );
		}

		$response = $this->client()->runReport(
			[
				'dateRanges' => [ [ 'startDate' => '1daysAgo', 'endDate' => 'today' ] ],
				'metrics'    => [ [ 'name' => 'activeUsers' ] ],
				'limit'      => 1,
			]
		);
		return is_wp_error( $response ) ? $response : true;
	}

	/** Refresh everything the cron keeps warm — defaults plus served windows. */
	public function syncAll(): void {
		if ( ! $this->isConfigured() ) {
			return;
		}
		SignatureRegistry::prune();
		$this->refreshSummary();

		$windows = self::SYNC_WINDOWS;
		foreach ( SignatureRegistry::all() as $extra ) {
			$extra = self::canonicalWindow( $extra );
			if ( ! in_array( $extra, $windows, true ) ) {
				$windows[] = $extra;
			}
		}
		foreach ( $windows as $window ) {
			$this->refreshPopularPosts( $window );
		}
	}

	// --- Pure helpers (unit-tested) --------------------------------------

	/**
	 * Aggregate a runReport response into a ranked key => views list.
	 *
	 * @param array<string,mixed> $response
	 * @return array<int,array{key:string,views:int}>
	 */
	public static function rankRows( array $response, bool $hour_mode, string $cutoff ): array {
		$totals = [];
		foreach ( $response['rows'] ?? [] as $row ) {
			$dimensions = $row['dimensionValues'] ?? [];
			$metrics    = $row['metricValues'] ?? [];

			$key = (string) ( $dimensions[0]['value'] ?? '' );
			if ( '' === $key || '(not set)' === $key ) {
				continue;
			}
			if ( $hour_mode ) {
				$hour = (string) ( $dimensions[1]['value'] ?? '' );
				if ( '' !== $cutoff && $hour < $cutoff ) {
					continue;
				}
			}

			$totals[ $key ] = ( $totals[ $key ] ?? 0 ) + (int) ( $metrics[0]['value'] ?? 0 );
		}

		arsort( $totals );

		$ranked = [];
		foreach ( $totals as $key => $views ) {
			$ranked[] = [ 'key' => (string) $key, 'views' => (int) $views ];
		}
		return $ranked;
	}

	/**
	 * @return array{mode:string,amount:int}
	 */
	public static function windowSpec( string $window ): array {
		$window = strtolower( trim( $window ) );
		if ( preg_match( '/^(\d+)h$/', $window, $m ) ) {
			return [ 'mode' => 'hour', 'amount' => max( 1, (int) $m[1] ) ];
		}
		if ( preg_match( '/^(\d+)d$/', $window, $m ) ) {
			return [ 'mode' => 'day', 'amount' => max( 1, (int) $m[1] ) ];
		}
		return [ 'mode' => 'day', 'amount' => 7 ];
	}

	/**
	 * The canonical window string for any input: "12H " → "12h", "0h" → "1h",
	 * and anything unparseable → "7d" (the windowSpec fallback). Everything
	 * that touches a cache key or the registry goes through this, so typo'd
	 * shortcode attributes can't mint junk cache entries or registry slots.
	 */
	public static function canonicalWindow( string $window ): string {
		$spec = self::windowSpec( $window );
		return $spec['amount'] . ( 'hour' === $spec['mode'] ? 'h' : 'd' );
	}

	// --- Internals --------------------------------------------------------

	/**
	 * Servable cached data for a signature, or null when a refresh is due.
	 *
	 * Good entries are always servable (freshness is the sync cron's job); a
	 * negative entry (error placeholder / in-flight marker) is servable only
	 * until its `retry_after` passes.
	 */
	private static function servable( string $signature ): mixed {
		$entry = Cache::entry( $signature );
		if ( null === $entry ) {
			return null;
		}
		if ( (int) ( $entry['fetched'] ?? 0 ) > 0 ) {
			return $entry['data'];
		}
		if ( isset( $entry['retry_after'] ) && time() < (int) $entry['retry_after'] ) {
			return $entry['data'];
		}
		return null;
	}

	/**
	 * Arm the retry window BEFORE a live fetch. The GA4 call can block for up
	 * to 20 seconds; without this, every request arriving during that window
	 * would see the same cache miss and stack its own API call. With it, an
	 * outage or cold cache costs at most ~one live attempt per retry window.
	 * A successful fetch immediately overwrites the marker with good data.
	 */
	private static function arm( string $signature ): void {
		Cache::putNegative( $signature, time() + self::retryDelay() );
	}

	/**
	 * Error fallback: serve the last good payload if one exists; otherwise
	 * re-arm the negative entry (pushing retry_after past the failed call)
	 * and serve empty.
	 *
	 * @return array<string,mixed>|array<int,mixed>
	 */
	private function lastGoodOrNegative( string $signature ): array {
		$entry = Cache::entry( $signature );
		if ( null !== $entry && (int) ( $entry['fetched'] ?? 0 ) > 0 ) {
			return is_array( $entry['data'] ) ? $entry['data'] : [];
		}
		Cache::putNegative( $signature, time() + self::retryDelay() );
		return [];
	}

	private static function retryDelay(): int {
		/**
		 * Filter how long (seconds) a failed or in-flight live fetch blocks
		 * further live attempts for the same report.
		 *
		 * @param int $delay Default 60.
		 */
		return max( 1, (int) apply_filters( 'precision_analytics/error_retry_delay', self::ERROR_RETRY_DELAY ) );
	}

	/**
	 * @param array<int,array{key:string,views:int}> $ranked
	 * @return array<int,array{post:WP_Post,views:int}>
	 */
	private function hydrate( array $ranked, int $count, string $post_type ): array {
		$by_id = $this->trackingByPostId();
		$out   = [];

		foreach ( $ranked as $row ) {
			if ( count( $out ) >= $count ) {
				break;
			}
			$post = $by_id ? get_post( (int) $row['key'] ) : $this->postFromPath( $row['key'] );
			if ( ! $post instanceof WP_Post || 'publish' !== get_post_status( $post ) ) {
				continue;
			}
			if ( '' !== $post_type && get_post_type( $post ) !== $post_type ) {
				continue;
			}
			$out[] = [
				'post'  => $post,
				'views' => (int) $row['views'],
			];
		}
		return $out;
	}

	private function postFromPath( string $path ): ?WP_Post {
		$id = url_to_postid( home_url( $path ) );
		return $id ? get_post( $id ) : null;
	}

	private static function popularSignature( string $window ): string {
		return 'pop:' . self::canonicalWindow( $window );
	}

	private function trackingByPostId(): bool {
		return $this->options->bool( 'attributes.post_id' );
	}

	private function hourCutoff( int $hours ): string {
		$now = new \DateTimeImmutable( 'now', wp_timezone() );
		return $now->modify( '-' . max( 1, $hours ) . ' hours' )->format( 'YmdH' );
	}

	private function auth(): AuthInterface {
		return 'oauth' === $this->options->str( 'reporting.auth_method', 'service_account' )
			? new OAuthAuth( $this->options )
			: new ServiceAccountAuth( $this->options );
	}

	private function client(): DataApiClient {
		return $this->client ??= new DataApiClient( $this->auth(), trim( $this->options->str( 'reporting.property_id' ) ) );
	}
}
