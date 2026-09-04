<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Plugin;
use OrchardGrove\PrecisionAnalytics\Reporting\Sync;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use OrchardGrove\PrecisionAnalytics\Settings\SettingsPage;

/**
 * WordPress 6.7+ logs "_load_textdomain_just_in_time was called incorrectly"
 * whenever a translation function runs for our domain before `init`. Our
 * modules are constructed on `plugins_loaded`, so construction (and any hook
 * that can fire early) must not translate.
 *
 * This class deliberately does NOT use the shared TestCase stubs: the base
 * class pre-stubs `__()`, and a Brain Monkey `when()` stub silently wins over
 * a later `expect()->never()`, which would make these tests pass vacuously.
 * Here translation functions are defined ONLY as strict Mockery expectations.
 */
final class EarlyTranslationTest extends TestCase {

	private const TRANSLATORS = [ '__', '_e', '_x', '_n', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', 'translate' ];

	protected function setUp(): void {
		// Skip TestCase::setUp() on purpose (see class docblock).
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 ); // Options::defaults() passes through a filter.
		Functions\when( 'get_option' )->justReturn( [] );
		if ( ! defined( 'PRECISION_ANALYTICS_BASENAME' ) ) {
			define( 'PRECISION_ANALYTICS_BASENAME', 'precision-analytics/precision-analytics.php' );
		}
	}

	/** Fail the test if any translation function is called from here on. */
	private function forbidTranslation(): void {
		foreach ( self::TRANSLATORS as $fn ) {
			Functions\expect( $fn )->never();
		}
	}

	public function testSettingsPageConstructionDoesNotTranslate(): void {
		$this->forbidTranslation();

		new SettingsPage( new Options() ); // Constructed on plugins_loaded in production.
		$this->addToAssertionCount( 1 );   // Reaching here without a Mockery failure is the assertion.
	}

	public function testSettingsPageRegisterDoesNotTranslate(): void {
		$this->forbidTranslation();

		( new SettingsPage( new Options() ) )->register();
		$this->addToAssertionCount( 1 );
	}

	/**
	 * The real entry point. Boots the whole plugin exactly as `plugins_loaded`
	 * does on an admin request — every module constructed and registered, plus
	 * the version-change branch (reschedule + cache clear) — with translation
	 * forbidden throughout. Guards against a future early lookup being added
	 * ANYWHERE on the boot path, not just the three places fixed in 0.5.2.
	 */
	public function testFullPluginBootDoesNotTranslate(): void {
		$this->forbidTranslation();
		Functions\when( 'is_admin' )->justReturn( true );          // Admin request: SettingsPage + Widget are built.
		Functions\when( 'did_action' )->justReturn( 0 );           // Before init.
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'add_shortcode' )->justReturn( null );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		// get_option() returns [] (see setUp), so the stored version never matches
		// PRECISION_ANALYTICS_VERSION and the reschedule + Cache::clear branch runs.

		// Plugin is a process-wide singleton with a booted flag — start fresh.
		( new \ReflectionProperty( Plugin::class, 'instance' ) )->setValue( null, null ); // Private props are accessible via reflection since PHP 8.1.

		Plugin::instance()->boot();
		$this->addToAssertionCount( 1 );
	}

	public function testCronScheduleLabelIsNotTranslatedBeforeInit(): void {
		$this->forbidTranslation();
		Functions\when( 'did_action' )->justReturn( 0 ); // Before init.

		$schedules = Sync::addSchedule( [] );

		$this->assertSame( 'Precision Analytics sync interval', $schedules[ Sync::SCHEDULE ]['display'] );
		$this->assertSame( 900, $schedules[ Sync::SCHEDULE ]['interval'] );
	}

	public function testCronScheduleLabelIsTranslatedAfterInit(): void {
		Functions\when( 'did_action' )->justReturn( 1 ); // init has fired.
		Functions\expect( '__' )->once()->with( 'Precision Analytics sync interval', 'precision-analytics' )->andReturn( 'TRANSLATED' );

		$schedules = Sync::addSchedule( [] );

		$this->assertSame( 'TRANSLATED', $schedules[ Sync::SCHEDULE ]['display'] );
	}
}
