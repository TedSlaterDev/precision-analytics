<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

defined( 'ABSPATH' ) || exit;

/**
 * GTM transport: pushes the custom-dimension parameters (and Consent Mode v2
 * defaults) onto the dataLayer, then prints the Google Tag Manager container.
 */
final class DataLayer {

	public function __construct( private Consent $consent ) {}

	/**
	 * @param array<string,string> $params Resolved dimension parameter map.
	 */
	public function render( string $gtm_id, array $params ): void {
		$js = 'window.dataLayer=window.dataLayer||[];';

		$consent = $this->consent->defaultCommandJs();
		if ( '' !== $consent ) {
			$js .= 'function gtag(){dataLayer.push(arguments);}';
			$js .= $consent;
			$js .= $this->consent->updateHelperJs();
		}

		if ( $params ) {
			$js .= 'window.dataLayer.push(' . wp_json_encode( $params, JSON_HEX_TAG | JSON_HEX_AMP ) . ');';
		}

		wp_print_inline_script_tag( $js, [ 'id' => 'precision-analytics-datalayer' ] );

		$container = "(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});"
			. "var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
			. "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})"
			. '(window,document,"script","dataLayer",' . wp_json_encode( $gtm_id ) . ');';

		wp_print_inline_script_tag( $container, [ 'id' => 'precision-analytics-gtm' ] );
	}

	/** The GTM <noscript> fallback, printed on wp_body_open. */
	public function renderNoscript( string $gtm_id ): void {
		$src = 'https://www.googletagmanager.com/ns.html?id=' . rawurlencode( $gtm_id );
		printf(
			'<noscript><iframe src="%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
			esc_url( $src )
		);
	}
}
