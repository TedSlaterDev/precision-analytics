<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tests;

use Brain\Monkey\Functions;
use OrchardGrove\PrecisionAnalytics\Settings\Options;
use OrchardGrove\PrecisionAnalytics\Tracking\Dimensions;

final class DimensionsTest extends TestCase {

	public function testRegistryEntriesAreWellFormed(): void {
		foreach ( Dimensions::REGISTRY as $key => $meta ) {
			$this->assertArrayHasKey( 'param', $meta, "$key missing param" );
			$this->assertArrayHasKey( 'scope', $meta, "$key missing scope" );
			$this->assertArrayHasKey( 'label', $meta, "$key missing label" );
			$this->assertContains( $meta['scope'], [ 'event', 'user' ], "$key has an invalid scope" );
			$this->assertNotSame( '', $meta['param'] );
			$this->assertSame( $meta['param'], Dimensions::sanitizeParam( $meta['param'] ), "$key default is not a valid GA4 name" );
		}
	}

	public function testParameterNamesAreUnique(): void {
		$params = array_column( Dimensions::REGISTRY, 'param' );
		$this->assertSame( count( $params ), count( array_unique( $params ) ), 'GA4 parameter names must be unique.' );
	}

	public function testMonsterInsightsHintOnlyCoversEventScopedAttributes(): void {
		foreach ( array_keys( Dimensions::MONSTERINSIGHTS_NAMES ) as $key ) {
			$this->assertArrayHasKey( $key, Dimensions::REGISTRY );
			// MI's dimensions were event-scoped; a user-scoped attribute can't continue them.
			$this->assertSame( 'event', Dimensions::REGISTRY[ $key ]['scope'], "$key is not event-scoped" );
		}
	}

	public function testSanitizeParamEnforcesGa4Rules(): void {
		$this->assertSame( 'author', Dimensions::sanitizeParam( ' author ' ) );
		$this->assertSame( 'Post_Type2', Dimensions::sanitizeParam( 'Post_Type2' ) );
		$this->assertSame( '', Dimensions::sanitizeParam( '2fast' ) );       // Must start with a letter.
		$this->assertSame( '', Dimensions::sanitizeParam( 'has-dash' ) );    // Letters/digits/underscore only.
		$this->assertSame( '', Dimensions::sanitizeParam( 'ga_secret' ) );   // Reserved prefix.
		$this->assertSame( '', Dimensions::sanitizeParam( 'google_x' ) );
		$this->assertSame( '', Dimensions::sanitizeParam( 'firebase_x' ) );
		$this->assertSame( '', Dimensions::sanitizeParam( str_repeat( 'a', 41 ) ) ); // > 40 chars.
		$this->assertSame( str_repeat( 'a', 40 ), Dimensions::sanitizeParam( str_repeat( 'a', 40 ) ) );
		$this->assertSame( '', Dimensions::sanitizeParam( '' ) );
	}

	public function testParamNameUsesAValidOverrideElseTheDefault(): void {
		Functions\when( 'get_option' )->justReturn(
			[ 'attributes' => [ 'params' => [ 'author' => 'author', 'category' => 'bad-name' ] ] ]
		);
		$dimensions = new Dimensions( new Options() );

		$this->assertSame( 'author', $dimensions->paramName( 'author' ) );            // Override honored.
		$this->assertSame( 'primary_category', $dimensions->paramName( 'category' ) ); // Invalid override → default.
		$this->assertSame( 'page_type', $dimensions->paramName( 'page_type' ) );       // No override → default.
	}

	public function testSplitRoutesUserScopedValuesToUserProperties(): void {
		Functions\when( 'get_option' )->justReturn( [] );
		$dimensions = new Dimensions( new Options() );

		$scoped = $dimensions->split(
			[
				'post_author'        => 'Jane',
				'logged_in'          => 'yes',
				'user_role'          => 'editor',
				'custom_from_filter' => 'x', // Unknown name → event-scoped.
			]
		);

		$this->assertSame( [ 'post_author' => 'Jane', 'custom_from_filter' => 'x' ], $scoped['event'] );
		$this->assertSame( [ 'logged_in' => 'yes', 'user_role' => 'editor' ], $scoped['user'] );
	}

	public function testSplitFollowsOverriddenNames(): void {
		Functions\when( 'get_option' )->justReturn( [ 'attributes' => [ 'params' => [ 'user_role' => 'member_tier' ] ] ] );
		$dimensions = new Dimensions( new Options() );

		$scoped = $dimensions->split( [ 'member_tier' => 'gold' ] );
		$this->assertSame( [ 'member_tier' => 'gold' ], $scoped['user'] );
	}
}
