<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the gtag.js loader and inline config, including Consent Mode v2
 * defaults and the resolved custom-dimension parameters.
 */
final class Gtag {

	public function __construct(
		private Options $options,
		private Consent $consent
	) {}

	/**
	 * @param array<string,string> $params          Event-scoped parameter map.
	 * @param array<string,string> $user_properties User-scoped values (GA4 user properties).
	 */
	public function render( string $measurement_id, array $params, array $user_properties = [] ): void {
		$config = self::config( $params, $user_properties, $this->options->bool( 'general.debug_mode' ) );

		$js  = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}';
		$js .= $this->consent->defaultCommandJs();
		$js .= $this->consent->updateHelperJs();
		$js .= 'gtag("js",new Date());';
		$js .= 'gtag("config",' . wp_json_encode( $measurement_id )
			. ',' . self::configJson( $config ) . ');';

		wp_print_script_tag(
			[
				'async' => true,
				'src'   => 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $measurement_id ),
				'id'    => 'precision-analytics-gtag-js',
			]
		);
		wp_print_inline_script_tag( $js, [ 'id' => 'precision-analytics-gtag' ] );
	}

	/**
	 * The gtag config object: event parameters at the top level, user-scoped
	 * values under `user_properties` (GA4 populates user-scoped custom
	 * dimensions only from user properties — as an event parameter they stay
	 * "(not set)"), and debug_mode first when enabled.
	 *
	 * @param array<string,string> $params
	 * @param array<string,string> $user_properties
	 * @return array<string,mixed>
	 */
	public static function config( array $params, array $user_properties, bool $debug ): array {
		$config = $params;
		if ( $user_properties ) {
			$config['user_properties'] = (object) $user_properties;
		}
		if ( $debug ) {
			$config = array_merge( [ 'debug_mode' => true ], $config );
		}
		return $config;
	}

	/**
	 * Encode the gtag config parameters as a JSON object — never a JSON array.
	 *
	 * `gtag('config', id, [])` is silently ignored by GA4 and sends no page_view,
	 * so an empty parameter set (e.g. on the homepage, where no post-scoped
	 * dimension resolves) must serialise to `{}`, not `[]`. Casting to object
	 * also guarantees an object regardless of the map's PHP key shape.
	 *
	 * @param array<string,string> $config
	 */
	public static function configJson( array $config ): string {
		return (string) wp_json_encode( (object) $config, JSON_HEX_TAG | JSON_HEX_AMP );
	}
}
