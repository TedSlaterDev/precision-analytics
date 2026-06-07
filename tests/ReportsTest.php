<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use OrchardGrove\PrecisionAnalytics\Reporting\Reports;

final class ReportsTest extends TestCase {

	public function testRankRowsSortsDescAndSkipsNotSet(): void {
		$response = [
			'rows' => [
				[ 'dimensionValues' => [ [ 'value' => '10' ] ], 'metricValues' => [ [ 'value' => '5' ] ] ],
				[ 'dimensionValues' => [ [ 'value' => '11' ] ], 'metricValues' => [ [ 'value' => '9' ] ] ],
				[ 'dimensionValues' => [ [ 'value' => '(not set)' ] ], 'metricValues' => [ [ 'value' => '100' ] ] ],
			],
		];

		$this->assertSame(
			[
				[ 'key' => '11', 'views' => 9 ],
				[ 'key' => '10', 'views' => 5 ],
			],
			Reports::rankRows( $response, false, '' )
		);
	}

	public function testRankRowsHourModeFiltersByCutoffAndAggregates(): void {
		$response = [
			'rows' => [
				[ 'dimensionValues' => [ [ 'value' => '10' ], [ 'value' => '2026010108' ] ], 'metricValues' => [ [ 'value' => '3' ] ] ],
				[ 'dimensionValues' => [ [ 'value' => '10' ], [ 'value' => '2026010110' ] ], 'metricValues' => [ [ 'value' => '4' ] ] ],
				[ 'dimensionValues' => [ [ 'value' => '11' ], [ 'value' => '2026010105' ] ], 'metricValues' => [ [ 'value' => '50' ] ] ],
			],
		];

		// Cutoff 09:00 — only the 10:00 row for post 10 survives.
		$this->assertSame(
			[ [ 'key' => '10', 'views' => 4 ] ],
			Reports::rankRows( $response, true, '2026010109' )
		);
	}

	public function testWindowSpec(): void {
		$this->assertSame( [ 'mode' => 'hour', 'amount' => 12 ], Reports::windowSpec( '12h' ) );
		$this->assertSame( [ 'mode' => 'day', 'amount' => 7 ], Reports::windowSpec( '7d' ) );
		$this->assertSame( [ 'mode' => 'day', 'amount' => 30 ], Reports::windowSpec( '30D' ) );
		$this->assertSame( [ 'mode' => 'day', 'amount' => 7 ], Reports::windowSpec( 'garbage' ) );
		$this->assertSame( [ 'mode' => 'hour', 'amount' => 1 ], Reports::windowSpec( '0h' ) );
	}
}
