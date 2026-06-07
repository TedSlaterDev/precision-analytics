<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * [pa_popular_posts window="12h" count="5" post_type="post" show_views="true"]
 */
final class Shortcode implements ModuleInterface {

	public function __construct(
		private Options $options,
		private Reports $reports
	) {}

	public function register(): void {
		add_shortcode( 'pa_popular_posts', [ $this, 'render' ] );
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts( PopularPosts::defaults(), (array) $atts, 'pa_popular_posts' );
		$args = PopularPosts::normalize( $atts );

		$items = $this->reports->popularPosts( $args['window'], $args['count'], $args['post_type'] );
		return PopularPosts::render( $items, $args );
	}
}
