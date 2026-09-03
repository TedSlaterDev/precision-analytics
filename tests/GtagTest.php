<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use OrchardGrove\PrecisionAnalytics\Tracking\Gtag;

final class GtagTest extends TestCase {

	public function testEmptyParamsEncodeAsObjectNotArray(): void {
		// gtag('config', id, []) is ignored by GA4 — an empty map must be {}.
		$this->assertSame( '{}', Gtag::configJson( [] ) );
	}

	public function testConfigPutsUserScopedValuesUnderUserProperties(): void {
		$config = Gtag::config( [ 'post_type' => 'post' ], [ 'logged_in' => 'yes' ], false );

		$this->assertSame( 'post', $config['post_type'] );
		$this->assertIsObject( $config['user_properties'] );
		$this->assertSame( 'yes', $config['user_properties']->logged_in );
		// Serialises as a nested JSON object, never an array.
		$this->assertStringContainsString( '"user_properties":{"logged_in":"yes"}', Gtag::configJson( $config ) );
	}

	public function testConfigOmitsUserPropertiesWhenEmptyAndPutsDebugFirst(): void {
		$this->assertArrayNotHasKey( 'user_properties', Gtag::config( [ 'a' => '1' ], [], false ) );
		$this->assertSame( 'debug_mode', array_key_first( Gtag::config( [ 'a' => '1' ], [], true ) ) );
	}

	public function testParamsEncodeAsObject(): void {
		$json = Gtag::configJson( [ 'post_id' => '42', 'post_type' => 'post' ] );
		$this->assertStringStartsWith( '{', $json );
		$this->assertStringContainsString( '"post_id":"42"', $json );
		$this->assertStringContainsString( '"post_type":"post"', $json );
	}
}
