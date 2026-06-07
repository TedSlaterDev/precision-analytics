<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use OrchardGrove\PrecisionAnalytics\Tracking\Dimensions;

final class DimensionsTest extends TestCase {

	public function testRegistryEntriesAreWellFormed(): void {
		foreach ( Dimensions::REGISTRY as $key => $meta ) {
			$this->assertArrayHasKey( 'param', $meta, "$key missing param" );
			$this->assertArrayHasKey( 'scope', $meta, "$key missing scope" );
			$this->assertArrayHasKey( 'label', $meta, "$key missing label" );
			$this->assertContains( $meta['scope'], [ 'event', 'user' ], "$key has an invalid scope" );
			$this->assertNotSame( '', $meta['param'] );
		}
	}

	public function testParameterNamesAreUnique(): void {
		$params = array_column( Dimensions::REGISTRY, 'param' );
		$this->assertSame( count( $params ), count( array_unique( $params ) ), 'GA4 parameter names must be unique.' );
	}
}
