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
	 * @param array<string,string> $params Resolved dimension parameter map.
	 */
	public function render( string $measurement_id, array $params ): void {
		$config = $params;
		if ( $this->options->bool( 'general.debug_mode' ) ) {
			$config = array_merge( [ 'debug_mode' => true ], $config );
		}

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
