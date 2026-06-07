<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

defined( 'ABSPATH' ) || exit;

/**
 * Shared "popular posts" list logic + markup, reused by the shortcode, block,
 * and template tags so they render identically.
 */
final class PopularPosts {

	/** @return array{window:string,count:int,post_type:string,show_views:bool} */
	public static function defaults(): array {
		return [
			'window'     => '7d',
			'count'      => 5,
			'post_type'  => '',
			'show_views' => false,
		];
	}

	/**
	 * Normalize raw attributes (strings from shortcodes/blocks) to typed args.
	 *
	 * @param array<string,mixed> $args
	 * @return array{window:string,count:int,post_type:string,show_views:bool}
	 */
	public static function normalize( array $args ): array {
		$args = array_merge( self::defaults(), $args );
		return [
			'window'     => strtolower( trim( (string) $args['window'] ) ),
			'count'      => max( 1, min( 50, (int) $args['count'] ) ),
			'post_type'  => sanitize_key( (string) $args['post_type'] ),
			'show_views' => (bool) wp_validate_boolean( $args['show_views'] ),
		];
	}

	/**
	 * @param array<int,array{post:\WP_Post,views:int}> $items
	 * @param array{show_views:bool}                     $args
	 */
	public static function render( array $items, array $args ): string {
		if ( ! $items ) {
			return '<p class="pa-popular-empty">' . esc_html__( 'No analytics data yet.', 'precision-analytics' ) . '</p>';
		}

		$show_views = ! empty( $args['show_views'] );
		$html       = '<ol class="pa-popular-posts">';
		foreach ( $items as $item ) {
			$post  = $item['post'];
			$html .= '<li class="pa-popular-post">';
			$html .= '<a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>';
			if ( $show_views ) {
				$html .= ' <span class="pa-views">' . esc_html( number_format_i18n( (int) $item['views'] ) ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';

		return $html;
	}
}
