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

		$scoped = $this->dimensions->collectScoped( $context );

		if ( 'gtm' === $transport ) {
			// GTM users map scopes themselves — push everything flat.
			( new DataLayer( $this->consent ) )->render( $id, array_merge( $scoped['event'], $scoped['user'] ) );
			$this->renderedGtm = true;
		} else {
			( new Gtag( $this->options, $this->consent ) )->render( $id, $scoped['event'], $scoped['user'] );
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
