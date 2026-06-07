<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Google Consent Mode v2 default command and the small JS hook a
 * consent banner calls to grant consent. Used by Gtag / DataLayer, which emit
 * the gtag() shim this relies on first.
 */
final class Consent {

	/**
	 * Regions where consent defaults to denied when "EEA only" is on:
	 * EU-27 + EEA (IS, LI, NO) + UK + Switzerland.
	 */
	private const REGIONS = [
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
		'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
		'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH',
	];

	public function __construct( private Options $options ) {}

	public function enabled(): bool {
		return $this->options->bool( 'consent.enabled' );
	}

	/**
	 * The `consent default` (and optional url_passthrough) JS, or '' if disabled.
	 * Assumes the caller has already defined the `gtag()` shim.
	 */
	public function defaultCommandJs(): string {
		if ( ! $this->enabled() ) {
			return '';
		}

		$ad        = $this->state( 'consent.ad_default' );
		$analytics = $this->state( 'consent.analytics_default' );

		$state = [
			'ad_storage'         => $ad,
			'ad_user_data'       => $ad,
			'ad_personalization' => $ad,
			'analytics_storage'  => $analytics,
			'wait_for_update'    => max( 0, $this->options->int( 'consent.wait_for_update', 500 ) ),
		];

		if ( $this->options->bool( 'consent.eea_only' ) ) {
			$state['region'] = self::REGIONS;
		}

		/**
		 * Filter the Consent Mode v2 default state before output.
		 *
		 * @param array<string,mixed> $state
		 */
		$state = apply_filters( 'precision_analytics/consent_defaults', $state );

		$js = 'gtag("consent","default",' . wp_json_encode( $state ) . ');';
		if ( $this->options->bool( 'consent.url_passthrough' ) ) {
			$js .= 'gtag("set","url_passthrough",true);';
		}
		return $js;
	}

	/**
	 * Exposes `window.precisionAnalytics.updateConsent(state)` so any consent
	 * banner can flip consent to granted after the user accepts.
	 */
	public function updateHelperJs(): string {
		if ( ! $this->enabled() ) {
			return '';
		}
		return 'window.precisionAnalytics=window.precisionAnalytics||{};'
			. 'window.precisionAnalytics.updateConsent=function(s){gtag("consent","update",s);};';
	}

	private function state( string $path ): string {
		return 'granted' === $this->options->str( $path, 'denied' ) ? 'granted' : 'denied';
	}
}
