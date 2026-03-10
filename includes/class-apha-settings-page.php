<?php
/**
 * Advanced PostHog Analytics Settings Page.
 *
 * Adds a Advanced PostHog Analytics tab to WooCommerce > Settings. This file is only
 * loaded when WC_Settings_Page is available (admin context).
 *
 * @package AdvancedPostHogAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Settings_Page
 *
 * Extends WC_Settings_Page to provide a dedicated settings tab.
 */
class APHA_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'advanced-posthog-analytics';
		$this->label = 'PostHog Analytics';

		parent::__construct();
	}

	/**
	 * Get settings array.
	 *
	 * @return array Settings fields.
	 */
	public function get_settings() {
		$settings = array(
			array(
				'title' => __( 'PostHog Configuration', 'advanced-posthog-analytics' ),
				'type'  => 'title',
				'id'    => 'apha_posthog_configuration',
			),

			array(
				'title'    => __( 'PostHog API Key', 'advanced-posthog-analytics' ),
				'desc'     => __( 'Your PostHog project API key (starts with phc_).', 'advanced-posthog-analytics' ),
				'id'       => 'apha_api_key',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),

			array(
				'title'   => __( 'Region', 'advanced-posthog-analytics' ),
				'id'      => 'apha_region',
				'type'    => 'select',
				'default' => 'us',
				'options' => array(
					'us' => __( 'US (us.posthog.com)', 'advanced-posthog-analytics' ),
					'eu' => __( 'EU (eu.posthog.com)', 'advanced-posthog-analytics' ),
				),
			),

			array(
				'title'    => __( 'Custom Proxy URL', 'advanced-posthog-analytics' ),
				'desc'     => __( 'Optional. External reverse proxy domain for first-party tracking (e.g., https://ph.yourdomain.com). Leave empty to use PostHog directly.', 'advanced-posthog-analytics' ),
				'id'       => 'apha_custom_proxy_url',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => false,
			),

			array(
				'type' => 'sectionend',
				'id'   => 'apha_posthog_configuration',
			),

			array(
				'title' => __( 'Tracking Options', 'advanced-posthog-analytics' ),
				'type'  => 'title',
				'id'    => 'apha_tracking_options',
			),

			array(
				'title'   => __( 'Enable Server-Side Tracking', 'advanced-posthog-analytics' ),
				'desc'    => __( 'Track Order Completed, Refunded, and Status Changed events server-side (recommended).', 'advanced-posthog-analytics' ),
				'id'      => 'apha_server_tracking',
				'type'    => 'checkbox',
				'default' => 'yes',
			),

			array(
				'title'   => __( 'Enable Frontend Tracking', 'advanced-posthog-analytics' ),
				'desc'    => __( 'Track browsing events (Product Viewed, Cart Viewed, etc.) via JavaScript.', 'advanced-posthog-analytics' ),
				'id'      => 'apha_frontend_tracking',
				'type'    => 'checkbox',
				'default' => 'yes',
			),

			array(
				'title'   => __( 'Person Profiles', 'advanced-posthog-analytics' ),
				'id'      => 'apha_person_profiles',
				'type'    => 'select',
				'default' => 'always',
				'options' => array(
					'always'          => __( 'Always create profiles', 'advanced-posthog-analytics' ),
					'identified_only' => __( 'Identified users only', 'advanced-posthog-analytics' ),
				),
			),

			array(
				'title'   => __( 'Consent Mode', 'advanced-posthog-analytics' ),
				'desc'    => __( 'Require cookie consent before tracking. Supports CookieYes and Complianz.', 'advanced-posthog-analytics' ),
				'id'      => 'apha_consent_mode',
				'type'    => 'checkbox',
				'default' => 'no',
			),

			array(
				'title'   => __( 'Form Identification', 'advanced-posthog-analytics' ),
				'desc'    => __( 'Identify visitors when they enter an email address in any form (checkout, contact, signup).', 'advanced-posthog-analytics' ),
				'id'      => 'apha_form_identify',
				'type'    => 'checkbox',
				'default' => 'yes',
			),

			array(
				'title'   => __( 'Element Visibility Tracking', 'advanced-posthog-analytics' ),
				'desc'    => __( 'Track when visitors see key page elements. Add CSS class <code>apha-track-view</code> to any element.', 'advanced-posthog-analytics' ),
				'id'      => 'apha_element_visibility',
				'type'    => 'checkbox',
				'default' => 'no',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'apha_tracking_options',
			),
		);

		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Standard WooCommerce settings filter.
	}
}
