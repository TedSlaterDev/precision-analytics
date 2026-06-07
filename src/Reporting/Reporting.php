<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Wires up the reporting layer: the sync cron plus the consumers that read its
 * cache (dashboard widget, shortcode, block). All consumers read cache only.
 */
final class Reporting implements ModuleInterface {

	private Sync $sync;
	private Reports $reports;

	public function __construct( private Options $options ) {
		$this->sync    = new Sync( $options );
		$this->reports = new Reports( $options );
	}

	public function register(): void {
		$this->sync->register();

		( new Shortcode( $this->options, $this->reports ) )->register();
		( new Block( $this->options, $this->reports ) )->register();

		if ( is_admin() ) {
			( new Widget( $this->options, $this->reports ) )->register();
		}
	}

	public function reports(): Reports {
		return $this->reports;
	}
}
