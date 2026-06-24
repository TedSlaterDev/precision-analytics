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

	/** Worldwide "granted" baseline emitted when consent is gated to the EEA only. */
	private const GRANTED_ALL = [
		'ad_storage'         => 'granted',
		'ad_user_data'       => 'granted',
		'ad_personalization' => 'granted',
		'analytics_storage'  => 'granted',
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
		$commands = $this->defaultCommands();
		if ( ! $commands ) {
			return '';
		}

		$js = '';
		foreach ( $commands as $command ) {
			$js .= 'gtag("consent","default",' . wp_json_encode( $command ) . ');';
		}
		if ( $this->options->bool( 'consent.url_passthrough' ) ) {
			$js .= 'gtag("set","url_passthrough",true);';
		}
		return $js;
	}

	/**
	 * The ordered Consent Mode v2 `default` states to emit, or [] when disabled.
	 *
	 * With "EEA only" on, a worldwide *granted* baseline is emitted first, then a
	 * *restricted* override scoped to the EEA/UK/CH region — so visitors outside
	 * those regions (e.g. the US) are granted by default and tracked without a
	 * consent banner, while EEA visitors stay gated. A region-scoped default with
	 * no worldwide baseline (the old behaviour) left non-EEA visitors ungranted
	 * and silently dropped their hits. With "EEA only" off, the restricted
	 * defaults apply everywhere until a banner grants consent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function defaultCommands(): array {
		if ( ! $this->enabled() ) {
			return [];
		}

		$ad        = $this->state( 'consent.ad_default' );
		$analytics = $this->state( 'consent.analytics_default' );

		$restricted = [
			'ad_storage'         => $ad,
			'ad_user_data'       => $ad,
			'ad_personalization' => $ad,
			'analytics_storage'  => $analytics,
			'wait_for_update'    => max( 0, $this->options->int( 'consent.wait_for_update', 500 ) ),
		];

		if ( $this->options->bool( 'consent.eea_only' ) ) {
			$restricted['region'] = self::REGIONS;
			$commands             = [ self::GRANTED_ALL, $restricted ];
		} else {
			$commands = [ $restricted ];
		}

		/**
		 * Filter the ordered Consent Mode v2 default states before output. Each
		 * entry is the object passed to a `gtag('consent','default', …)` call,
		 * applied in order (later region-scoped states override earlier ones).
		 *
		 * @param array<int,array<string,mixed>> $commands
		 */
		return apply_filters( 'precision_analytics/consent_defaults', $commands );
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
