<?php
/**
 * InsightTrail for PostHog Settings Helpers.
 *
 * Provides static accessor methods for plugin options. This class
 * does NOT extend WC_Settings_Page so it can be safely loaded on
 * every request (frontend, admin, AJAX, REST) without requiring
 * WooCommerce admin classes to be available.
 *
 * The actual WC_Settings_Page tab is registered lazily via the
 * `woocommerce_get_settings_pages` filter in APHA::add_settings_page().
 *
 * @package InsightTrailForPostHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Settings
 *
 * Lightweight static helper for reading InsightTrail for PostHog options from the database.
 */
class APHA_Settings {

	/**
	 * Get the PostHog API key.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		return get_option( 'apha_api_key', '' );
	}

	/**
	 * Get the configured PostHog region.
	 *
	 * @return string 'us' or 'eu'.
	 */
	public static function get_region() {
		return get_option( 'apha_region', 'us' );
	}

	/**
	 * Get the PostHog host URL for API requests.
	 *
	 * Returns the custom proxy URL if configured, otherwise the
	 * appropriate PostHog regional endpoint.
	 *
	 * @return string Host URL without trailing slash.
	 */
	public static function get_posthog_host() {
		$custom_proxy = get_option( 'apha_custom_proxy_url', '' );

		if ( ! empty( $custom_proxy ) ) {
			return untrailingslashit( $custom_proxy );
		}

		return 'eu' === self::get_region()
			? 'https://eu.posthog.com'
			: 'https://us.posthog.com';
	}

	/**
	 * Get the real PostHog UI host (ignores proxy setting).
	 *
	 * Used for the `ui_host` parameter in the JavaScript SDK configuration
	 * so that the toolbar and other UI features work correctly even when
	 * a reverse proxy is in use.
	 *
	 * @return string PostHog UI host URL without trailing slash.
	 */
	public static function get_posthog_ui_host() {
		return 'eu' === self::get_region()
			? 'https://eu.posthog.com'
			: 'https://us.posthog.com';
	}

	/**
	 * Check whether server-side tracking is enabled.
	 *
	 * @return bool
	 */
	public static function is_server_tracking_enabled() {
		return get_option( 'apha_server_tracking', 'yes' ) === 'yes';
	}

	/**
	 * Check whether frontend tracking is enabled.
	 *
	 * @return bool
	 */
	public static function is_frontend_tracking_enabled() {
		return get_option( 'apha_frontend_tracking', 'yes' ) === 'yes';
	}

	/**
	 * Get the person profiles setting.
	 *
	 * @return string 'always' or 'identified_only'.
	 */
	public static function get_person_profiles() {
		return get_option( 'apha_person_profiles', 'always' );
	}

	/**
	 * Check whether consent mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_consent_mode_enabled() {
		return get_option( 'apha_consent_mode', 'no' ) === 'yes';
	}

	/**
	 * Check whether client-side form identification is enabled.
	 *
	 * @return bool
	 */
	public static function is_form_identify_enabled() {
		return get_option( 'apha_form_identify', 'yes' ) === 'yes';
	}

	/**
	 * Check whether element visibility tracking is enabled.
	 *
	 * @return bool
	 */
	public static function is_element_visibility_enabled() {
		return get_option( 'apha_element_visibility', 'no' ) === 'yes';
	}
}
