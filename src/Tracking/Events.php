<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

use OrchardGrove\PrecisionAnalytics\Context;
use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Optional client-side events: outbound-link clicks and file downloads, sent as
 * GA4 events via gtag(). Off by default and guarded by `typeof gtag` so it is a
 * no-op for unsampled visitors. Note: GA4 Enhanced Measurement may already track
 * these — enable only one to avoid double counting.
 */
final class Events implements ModuleInterface {

	public function __construct( private Options $options ) {}

	public function register(): void {
		// gtag-only: in GTM mode these are configured with triggers in GTM.
		if ( 'gtag' !== $this->options->str( 'general.transport', 'gtag' ) ) {
			return;
		}
		add_action( 'wp_footer', [ $this, 'script' ], 20 );
	}

	public function script(): void {
		if ( ! Context::instance()->isTrackable() ) {
			return;
		}

		$outbound  = $this->options->bool( 'events.outbound_links' );
		$downloads = $this->options->bool( 'events.file_downloads' );
		if ( ! $outbound && ! $downloads ) {
			return;
		}

		$exts = array_filter( array_map( 'trim', explode( ',', strtolower( $this->options->str( 'events.download_exts' ) ) ) ) );

		$js = '(function(){if(typeof gtag!=="function")return;'
			. 'var ex=' . wp_json_encode( array_values( $exts ), JSON_HEX_TAG ) . ','
			. 'ob=' . ( $outbound ? 'true' : 'false' ) . ',dl=' . ( $downloads ? 'true' : 'false' ) . ';'
			. 'document.addEventListener("click",function(e){'
			. 'var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;if(!a)return;'
			. 'var u;try{u=new URL(a.href,location.href);}catch(_){return;}'
			. 'if(!/^https?:$/.test(u.protocol))return;'
			. 'var seg=u.pathname.split("/").pop()||"",dot=seg.lastIndexOf("."),x=dot>-1?seg.slice(dot+1).toLowerCase():"";'
			. 'if(dl&&x&&ex.indexOf(x)>-1){gtag("event","file_download",{file_name:seg,file_extension:x,link_url:a.href});return;}'
			. 'if(ob&&u.host!==location.host){gtag("event","click",{link_url:a.href,link_domain:u.host,outbound:true});}'
			. '},true);})();';

		wp_print_inline_script_tag( $js, [ 'id' => 'precision-analytics-events' ] );
	}
}
