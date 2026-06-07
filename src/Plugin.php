<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics;

use OrchardGrove\PrecisionAnalytics\Cli\Commands;
use OrchardGrove\PrecisionAnalytics\Reporting\Reporting;
use OrchardGrove\PrecisionAnalytics\Reporting\Sync;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use OrchardGrove\PrecisionAnalytics\Settings\SettingsPage;
use OrchardGrove\PrecisionAnalytics\Tracking\Events;
use OrchardGrove\PrecisionAnalytics\Tracking\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin container. Boots the enabled modules; a disabled module is never
 * constructed and registers no hooks.
 */
final class Plugin {

	private const VERSION_OPTION = 'precision_analytics_version';

	private static ?self $instance = null;

	private Options $options;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function options(): Options {
		return $this->options;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'precision-analytics', false, dirname( PRECISION_ANALYTICS_BASENAME ) . '/languages' );

		$o = $this->options;

		/** @var ModuleInterface[] $modules */
		$modules = [
			new Tracking( $o ),
			new Reporting( $o ),
		];

		if ( $o->bool( 'events.enabled' ) ) {
			$modules[] = new Events( $o );
		}

		if ( is_admin() ) {
			$modules[] = new SettingsPage( $o );
		}

		foreach ( $modules as $module ) {
			$module->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}

		// On version change, keep the sync schedule current (interval may have changed).
		if ( get_option( self::VERSION_OPTION ) !== PRECISION_ANALYTICS_VERSION ) {
			update_option( self::VERSION_OPTION, PRECISION_ANALYTICS_VERSION );
			Sync::reschedule();
		}
	}

	public static function activate(): void {
		( new Options() )->seedDefaults();
		// Register the custom schedule inline so wp_schedule_event() accepts it
		// regardless of hook order during the activation request.
		add_filter( 'cron_schedules', [ Sync::class, 'addSchedule' ] );
		Sync::ensureScheduled();
	}

	public static function deactivate(): void {
		Sync::clear();
	}
}
