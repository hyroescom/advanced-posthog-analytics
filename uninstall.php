<?php
/**
 * Advanced PostHog Analytics Uninstall.
 *
 * Removes all Advanced PostHog Analytics data from the database when the plugin is
 * deleted via the WordPress admin. This includes plugin options,
 * order meta entries, and attribution data created during tracking.
 *
 * @package AdvancedPostHogAnalytics
 */

// Exit if not called by WordPress during uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Remove all Advanced PostHog Analytics plugin options.
 */
delete_option( 'apha_api_key' );
delete_option( 'apha_region' );
delete_option( 'apha_custom_proxy_url' );
delete_option( 'apha_server_tracking' );
delete_option( 'apha_frontend_tracking' );
delete_option( 'apha_person_profiles' );
delete_option( 'apha_consent_mode' );
delete_option( 'apha_form_identify' );
delete_option( 'apha_element_visibility' );

/*
 * Remove Advanced PostHog Analytics order meta data.
 *
 * Includes identity tracking meta, attribution meta (first-touch,
 * last-touch, click IDs), and refund dedup meta.
 */
global $wpdb;

// Build the full list of meta keys to remove.
$apha_meta_keys = array(
	'_apha_tracked',
	'_apha_distinct_id',
	// First-touch attribution.
	'_apha_ft_source',
	'_apha_ft_medium',
	'_apha_ft_campaign',
	'_apha_ft_content',
	'_apha_ft_term',
	'_apha_ft_landing_page',
	'_apha_ft_referrer',
	'_apha_ft_timestamp',
	// Last-touch attribution.
	'_apha_lt_source',
	'_apha_lt_medium',
	'_apha_lt_campaign',
	'_apha_lt_content',
	'_apha_lt_term',
	'_apha_lt_landing_page',
	'_apha_lt_referrer',
	'_apha_lt_timestamp',
	// Click IDs.
	'_apha_gclid',
	'_apha_gbraid',
	'_apha_wbraid',
	'_apha_fbclid',
	'_apha_ttclid',
	'_apha_msclkid',
	'_apha_li_fat_id',
	// Conversion metrics.
	'_apha_days_to_conversion',
	'_apha_session_count',
);

// Build placeholders for the IN clause.
$apha_placeholders = implode( ', ', array_fill( 0, count( $apha_meta_keys ), '%s' ) );

// Legacy post meta (pre-HPOS orders stored as posts).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($apha_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		...$apha_meta_keys
	)
);

// Also clean up refund dedup meta (pattern: _apha_refund_{id}_tracked).
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_apha_refund_%_tracked'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
);

// HPOS orders meta table (WooCommerce 7.1+ with custom order tables enabled).
$apha_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

$apha_table_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
		DB_NAME,
		$apha_hpos_meta_table
	)
);

if ( $apha_table_exists ) {
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key IN ($apha_placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			...$apha_meta_keys
		)
	);

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE '_apha_refund_%_tracked'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);
}
