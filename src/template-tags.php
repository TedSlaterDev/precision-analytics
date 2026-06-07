<?php
/**
 * Global template tags for theme authors. Loaded in the global namespace.
 *
 * @package OrchardGrove\PrecisionAnalytics
 */

use OrchardGrove\PrecisionAnalytics\Plugin;
use OrchardGrove\PrecisionAnalytics\Reporting\PopularPosts;
use OrchardGrove\PrecisionAnalytics\Reporting\Reports;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'pa_popular_posts' ) ) {
	/**
	 * The most popular posts for a window, as an array of
	 * [ 'post' => WP_Post, 'views' => int ] rows.
	 *
	 * @param array<string,mixed> $args window|count|post_type.
	 * @return array<int,array{post:WP_Post,views:int}>
	 */
	function pa_popular_posts( array $args = [] ): array {
		$args    = PopularPosts::normalize( $args );
		$reports = new Reports( Plugin::instance()->options() );
		return $reports->popularPosts( $args['window'], $args['count'], $args['post_type'] );
	}
}

if ( ! function_exists( 'pa_the_popular_posts' ) ) {
	/**
	 * Echo the popular-posts list markup.
	 *
	 * @param array<string,mixed> $args window|count|post_type|show_views.
	 */
	function pa_the_popular_posts( array $args = [] ): void {
		$normal = PopularPosts::normalize( $args );
		$items  = pa_popular_posts( $args );
		echo PopularPosts::render( $items, $normal ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped within render().
	}
}

if ( ! function_exists( 'pa_get_report' ) ) {
	/**
	 * Fetch a cached report by name. Currently: "summary".
	 *
	 * @return array<string,mixed>
	 */
	function pa_get_report( string $type = 'summary' ): array {
		$reports = new Reports( Plugin::instance()->options() );
		return 'summary' === $type ? $reports->summary() : [];
	}
}
