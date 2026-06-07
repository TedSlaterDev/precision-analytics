<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered "Popular Posts" block. The editor script is plain JS against
 * the global wp.* runtime (no build step); the front end renders via PHP, so it
 * always reflects current titles, permalinks, and cached rankings.
 */
final class Block implements ModuleInterface {

	public function __construct(
		private Options $options,
		private Reports $reports
	) {}

	public function register(): void {
		add_action( 'init', [ $this, 'registerBlock' ] );
	}

	public function registerBlock(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'precision-analytics-popular-block',
			PRECISION_ANALYTICS_URL . 'assets/block/edit.js',
			[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ],
			PRECISION_ANALYTICS_VERSION,
			true
		);

		register_block_type(
			PRECISION_ANALYTICS_DIR . 'assets/block',
			[
				'editor_script'   => 'precision-analytics-popular-block',
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public function render( array $attributes = [] ): string {
		$args = PopularPosts::normalize(
			[
				'window'     => $attributes['window'] ?? '7d',
				'count'      => $attributes['count'] ?? 5,
				'post_type'  => $attributes['postType'] ?? '',
				'show_views' => $attributes['showViews'] ?? false,
			]
		);

		$items   = $this->reports->popularPosts( $args['window'], $args['count'], $args['post_type'] );
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes() : '';

		return '<div ' . $wrapper . '>' . PopularPosts::render( $items, $args ) . '</div>';
	}
}
