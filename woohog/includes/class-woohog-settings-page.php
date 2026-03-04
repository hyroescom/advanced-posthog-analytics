<?php
/**
 * WooHog Settings Page.
 *
 * Adds a WooHog tab to WooCommerce > Settings. This file is only
 * loaded when WC_Settings_Page is available (admin context).
 *
 * @package WooHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WooHog_Settings_Page
 *
 * Extends WC_Settings_Page to provide a dedicated settings tab.
 */
class WooHog_Settings_Page extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'woohog';
		$this->label = 'WooHog';

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
				'title' => __( 'PostHog Configuration', 'woohog' ),
				'type'  => 'title',
				'id'    => 'woohog_posthog_configuration',
			),

			array(
				'title'    => __( 'PostHog API Key', 'woohog' ),
				'desc'     => __( 'Your PostHog project API key (starts with phc_).', 'woohog' ),
				'id'       => 'woohog_api_key',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),

			array(
				'title'   => __( 'Region', 'woohog' ),
				'id'      => 'woohog_region',
				'type'    => 'select',
				'default' => 'us',
				'options' => array(
					'us' => __( 'US (us.posthog.com)', 'woohog' ),
					'eu' => __( 'EU (eu.posthog.com)', 'woohog' ),
				),
			),

			array(
				'title'    => __( 'Custom Proxy URL', 'woohog' ),
				'desc'     => __( 'Optional. External reverse proxy domain for first-party tracking (e.g., https://ph.yourdomain.com). Leave empty to use PostHog directly.', 'woohog' ),
				'id'       => 'woohog_custom_proxy_url',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => false,
			),

			array(
				'type' => 'sectionend',
				'id'   => 'woohog_posthog_configuration',
			),

			array(
				'title' => __( 'Tracking Options', 'woohog' ),
				'type'  => 'title',
				'id'    => 'woohog_tracking_options',
			),

			array(
				'title'   => __( 'Enable Server-Side Tracking', 'woohog' ),
				'desc'    => __( 'Track Order Completed, Refunded, and Status Changed events server-side (recommended).', 'woohog' ),
				'id'      => 'woohog_server_tracking',
				'type'    => 'checkbox',
				'default' => 'yes',
			),

			array(
				'title'   => __( 'Enable Frontend Tracking', 'woohog' ),
				'desc'    => __( 'Track browsing events (Product Viewed, Cart Viewed, etc.) via JavaScript.', 'woohog' ),
				'id'      => 'woohog_frontend_tracking',
				'type'    => 'checkbox',
				'default' => 'yes',
			),

			array(
				'title'   => __( 'Person Profiles', 'woohog' ),
				'id'      => 'woohog_person_profiles',
				'type'    => 'select',
				'default' => 'always',
				'options' => array(
					'always'          => __( 'Always create profiles', 'woohog' ),
					'identified_only' => __( 'Identified users only', 'woohog' ),
				),
			),

			array(
				'title'   => __( 'Consent Mode', 'woohog' ),
				'desc'    => __( 'Require cookie consent before tracking. Supports CookieYes and Complianz.', 'woohog' ),
				'id'      => 'woohog_consent_mode',
				'type'    => 'checkbox',
				'default' => 'no',
			),

			array(
				'type' => 'sectionend',
				'id'   => 'woohog_tracking_options',
			),
		);

		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings );
	}
}
