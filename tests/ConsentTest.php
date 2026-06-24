<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use OrchardGrove\PrecisionAnalytics\Tracking\Consent;

final class ConsentTest extends TestCase {

	/** @param array<string,mixed> $consent */
	private function consent( array $consent ): Consent {
		Functions\when( 'get_option' )->justReturn( [ 'consent' => $consent ] );
		return new Consent( new Options() );
	}

	public function testDisabledEmitsNothing(): void {
		$consent = $this->consent( [ 'enabled' => false ] );
		$this->assertSame( [], $consent->defaultCommands() );
		$this->assertSame( '', $consent->defaultCommandJs() );
	}

	public function testEeaOnlyGrantsWorldwideThenRestrictsEea(): void {
		$commands = $this->consent(
			[
				'enabled'           => true,
				'eea_only'          => true,
				'analytics_default' => 'denied',
				'ad_default'        => 'denied',
				'wait_for_update'   => 500,
			]
		)->defaultCommands();

		$this->assertCount( 2, $commands );

		// First: worldwide grant, no region — this is what keeps US visitors tracked.
		$this->assertSame( 'granted', $commands[0]['analytics_storage'] );
		$this->assertSame( 'granted', $commands[0]['ad_storage'] );
		$this->assertArrayNotHasKey( 'region', $commands[0] );

		// Second: the restricted defaults, scoped to the EEA/UK/CH region.
		$this->assertSame( 'denied', $commands[1]['analytics_storage'] );
		$this->assertSame( 500, $commands[1]['wait_for_update'] );
		$this->assertArrayHasKey( 'region', $commands[1] );
		$this->assertContains( 'DE', $commands[1]['region'] );
		$this->assertContains( 'GB', $commands[1]['region'] );
	}

	public function testGrantIsEmittedBeforeTheEeaDenialInJs(): void {
		$js = $this->consent(
			[ 'enabled' => true, 'eea_only' => true, 'analytics_default' => 'denied' ]
		)->defaultCommandJs();

		$grant = strpos( $js, '"analytics_storage":"granted"' );
		$deny  = strpos( $js, '"analytics_storage":"denied"' );

		$this->assertNotFalse( $grant );
		$this->assertNotFalse( $deny );
		$this->assertLessThan( $deny, $grant, 'Worldwide grant must precede the EEA denial.' );
	}

	public function testWithoutEeaOnlyTheRestrictedDefaultsApplyEverywhere(): void {
		$commands = $this->consent(
			[ 'enabled' => true, 'eea_only' => false, 'analytics_default' => 'denied', 'ad_default' => 'denied' ]
		)->defaultCommands();

		$this->assertCount( 1, $commands );
		$this->assertSame( 'denied', $commands[0]['analytics_storage'] );
		$this->assertArrayNotHasKey( 'region', $commands[0] );
	}

	public function testUrlPassthroughTogglesItsCommand(): void {
		$on = $this->consent( [ 'enabled' => true, 'url_passthrough' => true ] )->defaultCommandJs();
		$this->assertStringContainsString( 'url_passthrough', $on );

		$off = $this->consent( [ 'enabled' => true, 'url_passthrough' => false ] )->defaultCommandJs();
		$this->assertStringNotContainsString( 'url_passthrough', $off );
	}
}
