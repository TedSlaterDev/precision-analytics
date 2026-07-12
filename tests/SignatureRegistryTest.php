<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Reporting\SignatureRegistry;

final class SignatureRegistryTest extends TestCase {

	/** @var array<string,mixed> Fake option store shared by the stubs. */
	private array $store = [];

	/** Wire get_option/update_option/delete_option to an in-memory store. */
	private function useStore( array $initial = [] ): void {
		$this->store = $initial;
		Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->store[ $name ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->store[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->store[ $name ] );
				return true;
			}
		);
	}

	public function testTouchRegistersANewWindow(): void {
		$this->useStore();

		SignatureRegistry::touch( '48h' );

		$stored = $this->store[ SignatureRegistry::OPTION ];
		$this->assertArrayHasKey( '48h', $stored );
		$this->assertSame( '48h', $stored['48h']['window'] );
		$this->assertEqualsWithDelta( time(), $stored['48h']['served'], 5 );
	}

	public function testTouchThrottlesRecentEntries(): void {
		$recent = time() - 60; // Well inside the 12h throttle window.
		$this->useStore(
			[
				SignatureRegistry::OPTION => [
					'48h' => [ 'window' => '48h', 'served' => $recent ],
				],
			]
		);

		SignatureRegistry::touch( '48h' );

		// No write: the served stamp is unchanged.
		$this->assertSame( $recent, $this->store[ SignatureRegistry::OPTION ]['48h']['served'] );
	}

	public function testTouchRefreshesOldEntries(): void {
		$old = time() - 100000; // Past the 12h throttle.
		$this->useStore(
			[
				SignatureRegistry::OPTION => [
					'48h' => [ 'window' => '48h', 'served' => $old ],
				],
			]
		);

		SignatureRegistry::touch( '48h' );

		$this->assertEqualsWithDelta( time(), $this->store[ SignatureRegistry::OPTION ]['48h']['served'], 5 );
	}

	public function testAtCapNewWindowsAreBlockedButExistingOnesStillRefresh(): void {
		$old  = time() - 100000; // Past the 12h throttle.
		$full = [ '3h' => [ 'window' => '3h', 'served' => $old ] ];
		for ( $i = 2; $i <= SignatureRegistry::MAX_SIGNATURES; $i++ ) {
			$full[ "{$i}d" ] = [ 'window' => "{$i}d", 'served' => time() ];
		}
		$this->useStore( [ SignatureRegistry::OPTION => $full ] );

		// A new window is rejected at the cap…
		SignatureRegistry::touch( '99h' );
		$this->assertArrayNotHasKey( '99h', $this->store[ SignatureRegistry::OPTION ] );

		// …but an at-cap EXISTING window still refreshes its served stamp, so
		// active entries can't be starved into prune() while the cap is full.
		SignatureRegistry::touch( '3h' );
		$stored = $this->store[ SignatureRegistry::OPTION ];
		$this->assertEqualsWithDelta( time(), $stored['3h']['served'], 5 );
		$this->assertCount( SignatureRegistry::MAX_SIGNATURES, $stored );
	}

	public function testPruneDropsStaleKeepsFresh(): void {
		$this->useStore(
			[
				SignatureRegistry::OPTION => [
					'3h'  => [ 'window' => '3h', 'served' => time() - 999999 ], // > 1 week.
					'48h' => [ 'window' => '48h', 'served' => time() - 60 ],
				],
			]
		);

		SignatureRegistry::prune();

		$stored = $this->store[ SignatureRegistry::OPTION ];
		$this->assertArrayNotHasKey( '3h', $stored );
		$this->assertArrayHasKey( '48h', $stored );
	}

	public function testAllReturnsWindowsAndSkipsCorruptEntries(): void {
		$this->useStore(
			[
				SignatureRegistry::OPTION => [
					'48h'     => [ 'window' => '48h', 'served' => time() ],
					'3d'      => [ 'window' => '3d', 'served' => time() ],
					'corrupt' => 'not-an-array',
				],
			]
		);

		$this->assertSame( [ '48h', '3d' ], SignatureRegistry::all() );
	}
}
