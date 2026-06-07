<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Tracking;

use OrchardGrove\PrecisionAnalytics\Context;
use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Sampling\Sampler;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end tracking. On wp_head it decides whether to emit output (request is
 * trackable, an ID is configured, and the visitor is in the sample) and prints
 * via the configured transport — gtag.js or the GTM dataLayer.
 */
final class Tracking implements ModuleInterface {

	private Dimensions $dimensions;
	private Consent $consent;
	private Sampler $sampler;

	private bool $renderedGtm = false;

	public function __construct( private Options $options ) {
		$this->dimensions = new Dimensions( $options );
		$this->consent    = new Consent( $options );
		$this->sampler    = new Sampler( $options );
	}

	public function register(): void {
		add_action( 'wp_head', [ $this, 'output' ], 1 );
		add_action( 'wp_body_open', [ $this, 'bodyNoscript' ] );
	}

	public function output(): void {
		$context = Context::instance();
		if ( ! $context->isTrackable() ) {
			return;
		}

		$transport = $this->options->str( 'general.transport', 'gtag' );
		$id        = 'gtm' === $transport
			? trim( $this->options->str( 'general.gtm_id' ) )
			: trim( $this->options->str( 'general.measurement_id' ) );

		if ( '' === $id ) {
			return;
		}
		if ( ! $this->sampler->shouldTrack( $context ) ) {
			return;
		}

		$params = $this->dimensions->collect( $context );

		if ( 'gtm' === $transport ) {
			( new DataLayer( $this->consent ) )->render( $id, $params );
			$this->renderedGtm = true;
		} else {
			( new Gtag( $this->options, $this->consent ) )->render( $id, $params );
		}
	}

	public function bodyNoscript(): void {
		if ( ! $this->renderedGtm ) {
			return;
		}
		$id = trim( $this->options->str( 'general.gtm_id' ) );
		if ( '' !== $id ) {
			( new DataLayer( $this->consent ) )->renderNoscript( $id );
		}
	}
}
