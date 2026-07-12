<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Reporting\Auth\AuthInterface;
use OrchardGrove\PrecisionAnalytics\Reporting\Cache;
use OrchardGrove\PrecisionAnalytics\Reporting\DataApiClient;
use OrchardGrove\PrecisionAnalytics\Reporting\Reports;
use OrchardGrove\PrecisionAnalytics\Reporting\SignatureRegistry;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

/**
 * Cache behavior of Reports: negative caching of API errors (no per-pageview
 * retries), the pre-armed in-flight guard, stale-on-error, window
 * canonicalization, and cron refresh of registered windows. Runs the real
 * DataApiClient against a stubbed wp_remote_post.
 */
final class ReportsCacheTest extends TestCase {

	/** @var array<string,mixed> Fake option store shared by the stubs. */
	private array $store = [];

	private int $httpCalls = 0;

	/** Snapshot of whether a negative entry existed when the HTTP call fired. */
	private ?bool $armedAtCallTime = null;

	protected function setUp(): void {
		parent::setUp();
		$this->store           = [];
		$this->httpCalls       = 0;
		$this->armedAtCallTime = null;

		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->store[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( (string) $key ) );
	}

	private function reports( mixed $httpResult, string $watch_signature = '' ): Reports {
		Functions\when( 'wp_remote_post' )->alias(
			function () use ( $httpResult, $watch_signature ) {
				++$this->httpCalls;
				if ( '' !== $watch_signature && null === $this->armedAtCallTime ) {
					$entry                 = ( $this->store[ Cache::OPTION ] ?? [] )[ $watch_signature ] ?? null;
					$this->armedAtCallTime = is_array( $entry ) && time() < (int) ( $entry['retry_after'] ?? 0 );
				}
				return $httpResult;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $response ) => (int) ( $response['response']['code'] ?? 0 )
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $response ) => (string) ( $response['body'] ?? '' )
		);

		$auth = new class() implements AuthInterface {
			public function isConfigured(): bool {
				return true;
			}
			public function accessToken(): string|\WP_Error {
				return 'test-token';
			}
		};

		return new Reports( new Options(), new DataApiClient( $auth, '123456' ) );
	}

	private function okResponse(): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => (string) json_encode(
				[
					'rows' => [
						[ 'dimensionValues' => [ [ 'value' => '/hello/' ] ], 'metricValues' => [ [ 'value' => '7' ] ] ],
					],
				]
			),
		];
	}

	// --- Negative caching ---------------------------------------------------

	public function testApiErrorIsNegativeCachedAndNotRetriedPerPageview(): void {
		$reports = $this->reports( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$this->assertSame( [], $reports->popularPosts( '7d' ) );
		$this->assertSame( 1, $this->httpCalls );

		// The failure left a negative entry with a retry_after in the future.
		$entry = $this->store[ Cache::OPTION ]['pop:7d'];
		$this->assertSame( [], $entry['data'] );
		$this->assertSame( 0, $entry['fetched'] );
		$this->assertGreaterThan( time(), $entry['retry_after'] );

		// Further renders inside the backoff window make NO further API calls.
		$this->assertSame( [], $reports->popularPosts( '7d' ) );
		$this->assertSame( [], $reports->popularPosts( '7d' ) );
		$this->assertSame( 1, $this->httpCalls );
	}

	public function testSummaryErrorIsNegativeCachedAndNotRetriedPerPageview(): void {
		$reports = $this->reports( new \WP_Error( 'http_request_failed', 'timeout' ) );

		$zeros = [ 'totalUsers' => 0, 'sessions' => 0, 'screenPageViews' => 0 ];
		$this->assertSame( $zeros, $reports->summary() );
		$this->assertSame( 1, $this->httpCalls );

		$entry = $this->store[ Cache::OPTION ]['summary:28d'];
		$this->assertSame( 0, $entry['fetched'] );
		$this->assertGreaterThan( time(), $entry['retry_after'] );

		// Repeated dashboard renders don't retry the API inside the backoff.
		$this->assertSame( $zeros, $reports->summary() );
		$this->assertSame( 1, $this->httpCalls );
	}

	public function testSummaryRecoversAfterTheNegativeEntryExpires(): void {
		// An expired negative entry must count as a miss — otherwise one API
		// error would freeze the dashboard at zeros forever.
		$this->store[ Cache::OPTION ] = [
			'summary:28d' => [ 'data' => [], 'fetched' => 0, 'retry_after' => time() - 5 ],
		];

		$body    = [ 'rows' => [ [ 'metricValues' => [ [ 'value' => '9' ], [ 'value' => '8' ], [ 'value' => '7' ] ] ] ] ];
		$reports = $this->reports( [ 'response' => [ 'code' => 200 ], 'body' => (string) json_encode( $body ) ] );

		$this->assertSame(
			[ 'totalUsers' => 9, 'sessions' => 8, 'screenPageViews' => 7 ],
			$reports->summary()
		);
		$this->assertSame( 1, $this->httpCalls );
		$this->assertGreaterThan( 0, $this->store[ Cache::OPTION ]['summary:28d']['fetched'] );
	}

	public function testRetryWindowIsArmedBeforeTheLiveCallFires(): void {
		// The in-flight guard: by the time the (blocking, up to 20s) HTTP call
		// starts, the negative marker must already be in the cache so
		// concurrent requests serve empty instead of stacking API calls.
		$reports = $this->reports( $this->okResponse(), 'pop:7d' );
		Functions\when( 'get_post' )->justReturn( null );

		$reports->popularPosts( '7d' );

		$this->assertTrue( $this->armedAtCallTime, 'Negative marker must be armed before wp_remote_post fires.' );
		// And the successful fetch replaced the marker with good data.
		$this->assertGreaterThan( 0, $this->store[ Cache::OPTION ]['pop:7d']['fetched'] );
	}

	public function testRetryDelayFilterIsHonored(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'precision_analytics/error_retry_delay' === $hook ? 7 : $value
		);

		$reports = $this->reports( new \WP_Error( 'http_request_failed', 'down' ) );
		$reports->popularPosts( '7d' );

		$retry_after = $this->store[ Cache::OPTION ]['pop:7d']['retry_after'];
		$this->assertEqualsWithDelta( time() + 7, $retry_after, 3 );
	}

	public function testExpiredNegativeEntryAllowsARetry(): void {
		$this->store[ Cache::OPTION ] = [
			'pop:7d' => [ 'data' => [], 'fetched' => 0, 'retry_after' => time() - 5 ],
		];

		Functions\when( 'get_post' )->justReturn( null ); // Hydration is out of scope here.
		$reports = $this->reports( $this->okResponse() );
		$reports->popularPosts( '7d', 5 );

		$this->assertSame( 1, $this->httpCalls );
		// The retry succeeded and replaced the negative entry with good data.
		$this->assertGreaterThan( 0, $this->store[ Cache::OPTION ]['pop:7d']['fetched'] );
	}

	public function testStaleOnErrorServesLastGoodDataAndNeverClobbersIt(): void {
		$good                         = [ [ 'key' => '42', 'views' => 9 ] ];
		$this->store[ Cache::OPTION ] = [
			'pop:7d' => [ 'data' => $good, 'fetched' => time() - 999999 ],
		];

		$reports = $this->reports( new \WP_Error( 'http_request_failed', 'down' ) );
		$ranked  = $reports->refreshPopularPosts( '7d' );

		$this->assertSame( $good, $ranked );
		// The good entry survived — no negative overwrite.
		$this->assertSame( $good, $this->store[ Cache::OPTION ]['pop:7d']['data'] );
		$this->assertGreaterThan( 0, $this->store[ Cache::OPTION ]['pop:7d']['fetched'] );
	}

	public function testPutNegativeNeverClobbersGoodData(): void {
		$good                         = [ [ 'key' => '42', 'views' => 9 ] ];
		$this->store[ Cache::OPTION ] = [
			'pop:7d' => [ 'data' => $good, 'fetched' => 123 ],
		];

		Cache::putNegative( 'pop:7d', time() + 60 );

		$this->assertSame( $good, $this->store[ Cache::OPTION ]['pop:7d']['data'] );
		$this->assertSame( 123, $this->store[ Cache::OPTION ]['pop:7d']['fetched'] );
	}

	// --- Window canonicalization + registry ----------------------------------

	public function testCanonicalWindow(): void {
		$this->assertSame( '48h', Reports::canonicalWindow( '48H ' ) );
		$this->assertSame( '7d', Reports::canonicalWindow( 'garbage' ) );
		$this->assertSame( '7d', Reports::canonicalWindow( '' ) );
		$this->assertSame( '1h', Reports::canonicalWindow( '0h' ) );
		$this->assertSame( '30d', Reports::canonicalWindow( '30D' ) );
	}

	public function testNonDefaultWindowIsRegisteredForTheCron(): void {
		$this->store[ Cache::OPTION ] = [
			'pop:48h' => [ 'data' => [], 'fetched' => time() ],
			'pop:12h' => [ 'data' => [], 'fetched' => time() ],
			'pop:7d'  => [ 'data' => [], 'fetched' => time() ],
		];

		$reports = $this->reports( $this->okResponse() );

		$reports->popularPosts( '48H' );          // Non-default window → registered, normalized.
		$reports->popularPosts( '12h' );          // Default window → not registered.
		$reports->popularPosts( '7d', 5, 'post' ); // post_type is a read-time filter → no extra registration.
		$reports->popularPosts( 'junk' );         // Unparseable → canonicalizes to the default 7d → not registered.

		$this->assertSame( [ '48h' ], SignatureRegistry::all() );
		$this->assertSame( 0, $this->httpCalls ); // All served from cache.
	}

	public function testPostTypeVariantsShareOneCacheEntryAndOneFetch(): void {
		Functions\when( 'get_post' )->justReturn( null );
		$reports = $this->reports( $this->okResponse() );

		$reports->popularPosts( '7d' );
		$reports->popularPosts( '7d', 5, 'post' );
		$reports->popularPosts( '7d', 5, 'page' );

		// One window → one signature → one API call, however it's filtered.
		$this->assertSame( 1, $this->httpCalls );
		$this->assertArrayHasKey( 'pop:7d', $this->store[ Cache::OPTION ] );
		$this->assertCount( 1, $this->store[ Cache::OPTION ] );
	}

	// --- syncAll --------------------------------------------------------------

	/** Configure reporting so syncAll proceeds. */
	private function configure(): void {
		$this->store['precision_analytics'] = [
			'reporting' => [
				'enabled'              => true,
				'property_id'          => '123456',
				'service_account_json' => '{"client_email":"x","private_key":"y"}',
			],
		];
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
	}

	public function testSyncAllRefreshesRegisteredWindows(): void {
		$this->configure();
		$this->store[ SignatureRegistry::OPTION ] = [
			'48h' => [ 'window' => '48h', 'served' => time() ],
		];

		$reports = $this->reports( $this->okResponse() );
		$reports->syncAll();

		// summary + 4 default windows + 1 registered window = 6 API calls.
		$this->assertSame( 6, $this->httpCalls );
		$this->assertArrayHasKey( 'pop:48h', $this->store[ Cache::OPTION ] );
	}

	public function testSyncAllPrunesStaleWindowsInsteadOfFetchingThem(): void {
		$this->configure();
		$this->store[ SignatureRegistry::OPTION ] = [
			'48h' => [ 'window' => '48h', 'served' => time() - 999999 ], // > 1 week stale.
		];

		$reports = $this->reports( $this->okResponse() );
		$reports->syncAll();

		// The stale window was pruned, not fetched: summary + 4 defaults only.
		$this->assertSame( 5, $this->httpCalls );
		$this->assertArrayNotHasKey( '48h', $this->store[ SignatureRegistry::OPTION ] );
		$this->assertArrayNotHasKey( 'pop:48h', $this->store[ Cache::OPTION ] );
	}

	public function testSyncAllDedupesRegistryEntriesThatCanonicalizeToDefaults(): void {
		$this->configure();
		// Legacy/corrupt registry rows that normalize into an already-synced window.
		$this->store[ SignatureRegistry::OPTION ] = [
			'junk' => [ 'window' => 'junk', 'served' => time() ], // → 7d (default).
			'12h'  => [ 'window' => '12h', 'served' => time() ],  // Already a default.
		];

		$reports = $this->reports( $this->okResponse() );
		$reports->syncAll();

		// No duplicate fetches: summary + the 4 default windows only.
		$this->assertSame( 5, $this->httpCalls );
	}
}
