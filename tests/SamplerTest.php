<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Context;
use OrchardGrove\PrecisionAnalytics\Sampling\Sampler;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use WP_Post;
use WP_User;

final class SamplerTest extends TestCase {

	/** @param array<string,mixed> $sampling */
	private function sampler( array $sampling ): Sampler {
		Functions\when( 'get_option' )->justReturn( [ 'sampling' => $sampling ] );
		return new Sampler( new Options() );
	}

	/** Stub "nobody excluded" + a stable sampling seed. */
	private function noExclusions(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$user        = new WP_User();
		$user->ID    = 0;
		$user->roles = [];
		Functions\when( 'wp_get_current_user' )->justReturn( $user );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.5';
		$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
	}

	public function testParsesRulesIgnoringCommentsAndJunkAndClampsRate(): void {
		$sampler = $this->sampler(
			[ 'rules' => "post_type:product:100\n# a comment\nbad line\nauthor:ted:150\npage_type:search:-5" ]
		);

		$this->assertSame(
			[
				[ 'attribute' => 'post_type', 'value' => 'product', 'rate' => 100 ],
				[ 'attribute' => 'author', 'value' => 'ted', 'rate' => 100 ],
				[ 'attribute' => 'page_type', 'value' => 'search', 'rate' => 0 ],
			],
			$sampler->rules()
		);
	}

	public function testDisabledTracksEveryone(): void {
		$this->noExclusions();
		$sampler = $this->sampler( [ 'enabled' => false, 'rate' => 0 ] );
		$this->assertTrue( $sampler->shouldTrack( Context::instance() ) );
	}

	public function testRateBoundaries(): void {
		$this->noExclusions();
		$this->assertTrue( $this->sampler( [ 'enabled' => true, 'rate' => 100 ] )->shouldTrack( Context::instance() ) );
		$this->assertFalse( $this->sampler( [ 'enabled' => true, 'rate' => 0 ] )->shouldTrack( Context::instance() ) );
	}

	public function testExcludesLoggedInUsers(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$user        = new WP_User();
		$user->ID    = 7;
		$user->roles = [ 'subscriber' ];
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$sampler = $this->sampler( [ 'enabled' => false, 'exclude_logged_in' => true ] );
		$this->assertFalse( $sampler->shouldTrack( Context::instance() ) );
	}

	public function testExcludesByRole(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$user        = new WP_User();
		$user->ID    = 3;
		$user->roles = [ 'administrator' ];
		Functions\when( 'wp_get_current_user' )->justReturn( $user );

		$sampler = $this->sampler( [ 'enabled' => false, 'exclude_roles' => [ 'administrator' ] ] );
		$this->assertFalse( $sampler->shouldTrack( Context::instance() ) );
	}

	public function testDecisionIsDeterministicForTheSameVisitor(): void {
		$this->noExclusions();
		$sampler = $this->sampler( [ 'enabled' => true, 'rate' => 50 ] );
		$context = Context::instance();
		$this->assertSame( $sampler->shouldTrack( $context ), $sampler->shouldTrack( $context ) );
	}

	public function testPerSegmentRuleOverridesGlobalRate(): void {
		$this->noExclusions();
		foreach ( [ 'is_404', 'is_feed', 'is_search', 'is_front_page', 'is_home' ] as $fn ) {
			Functions\when( $fn )->justReturn( false );
		}
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( new WP_Post( [ 'ID' => 10, 'post_type' => 'post' ] ) );
		Context::reset();

		// Global rate would track everyone, but the rule forces 0% for posts.
		$sampler = $this->sampler( [ 'enabled' => true, 'rate' => 100, 'rules' => 'post_type:post:0' ] );
		$this->assertFalse( $sampler->shouldTrack( Context::instance() ) );
	}
}
