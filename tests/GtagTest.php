<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use OrchardGrove\PrecisionAnalytics\Tracking\Gtag;

final class GtagTest extends TestCase {

	public function testEmptyParamsEncodeAsObjectNotArray(): void {
		// gtag('config', id, []) is ignored by GA4 — an empty map must be {}.
		$this->assertSame( '{}', Gtag::configJson( [] ) );
	}

	public function testParamsEncodeAsObject(): void {
		$json = Gtag::configJson( [ 'post_id' => '42', 'post_type' => 'post' ] );
		$this->assertStringStartsWith( '{', $json );
		$this->assertStringContainsString( '"post_id":"42"', $json );
		$this->assertStringContainsString( '"post_type":"post"', $json );
	}
}
