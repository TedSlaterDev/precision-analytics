<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

final class OptionsTest extends TestCase {

	/** @param array<string,mixed> $stored */
	private function options( array $stored = [] ): Options {
		Functions\when( 'get_option' )->justReturn( $stored );
		return new Options();
	}

	public function testReturnsDefaultsWhenUnset(): void {
		$options = $this->options();
		$this->assertSame( 'gtag', $options->str( 'general.transport' ) );
		$this->assertTrue( $options->bool( 'attributes.author' ) );
		$this->assertSame( 100, $options->int( 'sampling.rate' ) );
		$this->assertSame( 900, $options->int( 'reporting.sync_interval' ) );
	}

	public function testStoredValueOverridesDefaultButSiblingsRemain(): void {
		$options = $this->options( [ 'general' => [ 'measurement_id' => 'G-ABC123' ] ] );
		$this->assertSame( 'G-ABC123', $options->str( 'general.measurement_id' ) );
		// Deep merge keeps untouched defaults from the same and other branches.
		$this->assertSame( 'gtag', $options->str( 'general.transport' ) );
		// Consent Mode ships off (US-first default); the sibling branch survives the merge.
		$this->assertFalse( $options->bool( 'consent.enabled' ) );
	}

	public function testListFieldReplacesRatherThanIndexMerges(): void {
		$options = $this->options( [ 'sampling' => [ 'exclude_roles' => [ 'editor', 'author' ] ] ] );
		$this->assertSame( [ 'editor', 'author' ], $options->arr( 'sampling.exclude_roles' ) );
	}

	public function testMissingPathReturnsProvidedDefault(): void {
		$this->assertSame( 'fallback', $this->options()->str( 'does.not.exist', 'fallback' ) );
	}
}
