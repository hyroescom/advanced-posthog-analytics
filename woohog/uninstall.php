<?php
/**
 * WooHog Uninstall.
 *
 * Removes all WooHog data from the database when the plugin is
 * deleted via the WordPress admin. This includes plugin options,
 * order meta entries, and attribution data created during tracking.
 *
 * @package WooHog
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Remove all WooHog plugin options.
 */
delete_option( 'woohog_api_key' );
delete_option( 'woohog_region' );
delete_option( 'woohog_custom_proxy_url' );
delete_option( 'woohog_server_tracking' );
delete_option( 'woohog_frontend_tracking' );
delete_option( 'woohog_person_profiles' );
delete_option( 'woohog_consent_mode' );

/*
 * Remove WooHog order meta data.
 *
 * Includes identity tracking meta, attribution meta (first-touch,
 * last-touch, click IDs), and refund dedup meta.
 */
global $wpdb;

// Build the full list of meta keys to remove.
$meta_keys = array(
	'_woohog_tracked',
	'_woohog_distinct_id',
	// First-touch attribution.
	'_woohog_ft_source',
	'_woohog_ft_medium',
	'_woohog_ft_campaign',
	'_woohog_ft_content',
	'_woohog_ft_term',
	'_woohog_ft_landing_page',
	'_woohog_ft_referrer',
	'_woohog_ft_timestamp',
	// Last-touch attribution.
	'_woohog_lt_source',
	'_woohog_lt_medium',
	'_woohog_lt_campaign',
	'_woohog_lt_content',
	'_woohog_lt_term',
	'_woohog_lt_landing_page',
	'_woohog_lt_referrer',
	'_woohog_lt_timestamp',
	// Click IDs.
	'_woohog_gclid',
	'_woohog_gbraid',
	'_woohog_wbraid',
	'_woohog_fbclid',
	'_woohog_ttclid',
	'_woohog_msclkid',
	'_woohog_li_fat_id',
	// Conversion metrics.
	'_woohog_days_to_conversion',
	'_woohog_session_count',
);

// Build placeholders for the IN clause.
$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );

// Legacy post meta (pre-HPOS orders stored as posts).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		...$meta_keys
	)
);

// Also clean up refund dedup meta (pattern: _woohog_refund_{id}_tracked).
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_woohog_refund_%_tracked'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// HPOS orders meta table (WooCommerce 7.1+ with custom order tables enabled).
$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

$table_exists = $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
		DB_NAME,
		$hpos_meta_table
	)
);

if ( $table_exists ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			...$meta_keys
		)
	);

	$wpdb->query(
		"DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_woohog_refund_%_tracked'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
}
