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
 * cron tick. API errors fall through to the last good cached value.
 */
final class Reports {

	/** Windows the sync cron keeps warm. */
	public const SYNC_WINDOWS = [ '12h', '24h', '7d', '30d' ];

	public function __construct( private Options $options ) {}

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
		$data = Cache::get( 'summary:28d' );
		if ( null === $data ) {
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
			return Cache::get( 'summary:28d' ) ?? [];
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
	 * Ranked posts for a window. $window is like "12h" (hours) or "7d" (days).
	 *
	 * @return array<int,array{post:WP_Post,views:int}>
	 */
	public function popularPosts( string $window = '7d', int $count = 5, string $post_type = '' ): array {
		$signature = $this->popularSignature( $window, $post_type );
		$ranked    = Cache::get( $signature );
		if ( null === $ranked ) {
			$ranked = $this->refreshPopularPosts( $window, $post_type );
		}
		return $this->hydrate( is_array( $ranked ) ? $ranked : [], max( 1, $count ), $post_type );
	}

	/**
	 * Fetch, rank, and cache the popular-posts list for a window.
	 *
	 * @return array<int,array{key:string,views:int}>
	 */
	public function refreshPopularPosts( string $window, string $post_type = '' ): array {
		$signature = $this->popularSignature( $window, $post_type );
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
			return Cache::get( $signature ) ?? [];
		}

		$ranked = self::rankRows( $response, 'hour' === $spec['mode'], $cutoff );
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

	/** Refresh everything the cron keeps warm. */
	public function syncAll(): void {
		if ( ! $this->isConfigured() ) {
			return;
		}
		$this->refreshSummary();
		foreach ( self::SYNC_WINDOWS as $window ) {
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

	// --- Internals --------------------------------------------------------

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

	private function popularSignature( string $window, string $post_type ): string {
		return 'pop:' . strtolower( trim( $window ) ) . ':' . ( '' === $post_type ? 'any' : $post_type );
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
		return new DataApiClient( $this->auth(), trim( $this->options->str( 'reporting.property_id' ) ) );
	}
}
