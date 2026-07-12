<?php
/**
 * Uninstall handler. Only deletes data when the user opted in via the
 * "Delete all plugin data when the plugin is deleted" setting.
 *
 * @package OrchardGrove\PrecisionAnalytics
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$precision_analytics_option = get_option( 'precision_analytics', [] );

if ( ! is_array( $precision_analytics_option ) || empty( $precision_analytics_option['advanced']['delete_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'precision_analytics' );
delete_option( 'precision_analytics_version' );
delete_option( 'precision_analytics_reports' );
delete_option( 'precision_analytics_signatures' );

wp_clear_scheduled_hook( 'precision_analytics_sync' );

// Drop cached GA4 report payloads and the access token (stored as transients).
global $wpdb;
$precision_analytics_like = $wpdb->esc_like( '_transient_precision_analytics_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $precision_analytics_like ) ); // phpcs:ignore WordPress.DB
$precision_analytics_like = $wpdb->esc_like( '_transient_timeout_precision_analytics_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $precision_analytics_like ) ); // phpcs:ignore WordPress.DB
