<?php
declare( strict_types=1 );

namespace OrchardGrove\PrecisionAnalytics\Reporting;

use OrchardGrove\PrecisionAnalytics\ModuleInterface;
use OrchardGrove\PrecisionAnalytics\Settings\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard summary widget: 28-day totals + the week's most-read posts, read
 * entirely from cache.
 */
final class Widget implements ModuleInterface {

	public function __construct(
		private Options $options,
		private Reports $reports
	) {}

	public function register(): void {
		if ( ! $this->options->bool( 'reporting.widget_enabled' ) ) {
			return;
		}
		add_action( 'wp_dashboard_setup', [ $this, 'add' ] );
	}

	public function add(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'precision_analytics_widget',
			__( 'Precision Analytics', 'precision-analytics' ),
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! $this->reports->isConfigured() ) {
			$url = admin_url( 'admin.php?page=precision-analytics&tab=reporting' );
			printf(
				'<p>%s</p>',
				wp_kses_post(
					sprintf(
						/* translators: %s: settings page URL. */
						__( 'Connect a GA4 property in <a href="%s">Precision Analytics → Reporting</a> to see data here.', 'precision-analytics' ),
						esc_url( $url )
					)
				)
			);
			return;
		}

		$summary = $this->reports->summary();

		echo '<ul class="pa-widget-stats">';
		printf( '<li><strong>%s</strong> %s</li>', esc_html( number_format_i18n( $summary['totalUsers'] ) ), esc_html__( 'users (28d)', 'precision-analytics' ) );
		printf( '<li><strong>%s</strong> %s</li>', esc_html( number_format_i18n( $summary['sessions'] ) ), esc_html__( 'sessions (28d)', 'precision-analytics' ) );
		printf( '<li><strong>%s</strong> %s</li>', esc_html( number_format_i18n( $summary['screenPageViews'] ) ), esc_html__( 'views (28d)', 'precision-analytics' ) );
		echo '</ul>';

		$popular = $this->reports->popularPosts( '7d', 5 );
		echo '<h3>' . esc_html__( 'Most read this week', 'precision-analytics' ) . '</h3>';
		echo wp_kses_post( PopularPosts::render( $popular, [ 'show_views' => true ] ) );

		$last = $this->reports->lastSync();
		if ( $last > 0 ) {
			printf(
				'<p class="pa-widget-foot description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable time difference. */
						__( 'Updated %s ago', 'precision-analytics' ),
						human_time_diff( $last )
					)
				)
			);
		}
	}
}
