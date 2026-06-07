<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron job that refreshes the cached GA4 reports on a schedule, so front-end
 * surfaces never call the API. The custom interval comes from the
 * reporting.sync_interval option (floored at 5 minutes to respect API quotas).
 */
final class Sync {

	public const HOOK     = 'precision_analytics_sync';
	public const SCHEDULE = 'precision_analytics_interval';

	public function __construct( private Options $options ) {}

	public function register(): void {
		add_filter( 'cron_schedules', [ self::class, 'addSchedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	public function run(): void {
		( new Reports( $this->options ) )->syncAll();
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public static function addSchedule( array $schedules ): array {
		$interval = max( 300, ( new Options() )->int( 'reporting.sync_interval', 900 ) );

		$schedules[ self::SCHEDULE ] = [
			'interval' => $interval,
			'display'  => __( 'Precision Analytics sync interval', 'precision-analytics' ),
		];
		return $schedules;
	}

	public static function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	public static function reschedule(): void {
		self::clear();
		self::ensureScheduled();
	}

	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
