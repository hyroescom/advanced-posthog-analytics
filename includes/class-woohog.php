<?php
/**
 * WooHog Core Orchestrator.
 *
 * Singleton class that bootstraps all WooHog sub-components
 * and wires them together.
 *
 * @package WooHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WooHog
 *
 * Main plugin class. Initializes settings, the PostHog API client,
 * identity management, attribution engine, and event tracking components.
 */
class WooHog {

	/**
	 * Singleton instance.
	 *
	 * @var WooHog|null
	 */
	private static $instance = null;

	/**
	 * PostHog API client.
	 *
	 * @var WooHog_PostHog_API|null
	 */
	private $api = null;

	/**
	 * Identity manager.
	 *
	 * @var WooHog_Identity|null
	 */
	private $identity = null;

	/**
	 * Settings page instance.
	 *
	 * @var WooHog_Settings|null
	 */
	private $settings = null;

	/**
	 * Product data helper.
	 *
	 * @var WooHog_Product_Data|null
	 */
	private $product_data = null;

	/**
	 * Server-side event tracker.
	 *
	 * @var WooHog_Server_Events|null
	 */
	private $server_events = null;

	/**
	 * Frontend event tracker.
	 *
	 * @var WooHog_Frontend_Events|null
	 */
	private $frontend_events = null;

	/**
	 * Data layer for frontend.
	 *
	 * @var WooHog_Data_Layer|null
	 */
	private $data_layer = null;

	/**
	 * Attribution engine.
	 *
	 * @var WooHog_Attribution|null
	 */
	private $attribution = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WooHog
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Registers the settings tab (always) and conditionally initializes
	 * tracking components when an API key is configured.
	 */
	private function __construct() {
		// Always register the settings tab so users can configure the plugin.
		add_action( 'init', array( $this, 'register_settings_tab' ), 10 );

		// Bail early if no API key is configured.
		if ( empty( WooHog_Settings::get_api_key() ) ) {
			return;
		}

		// Initialize the API client.
		$this->api = new WooHog_PostHog_API();

		// Initialize attribution engine (hooks into init at priority 5).
		$this->attribution = new WooHog_Attribution();

		// Initialize identity management.
		$this->identity = new WooHog_Identity( $this->api );
		$this->identity->set_attribution( $this->attribution );

		// Initialize product data helper.
		$this->product_data = new WooHog_Product_Data();

		// Initialize server-side tracking if enabled.
		if ( WooHog_Settings::is_server_tracking_enabled() ) {
			$this->server_events = new WooHog_Server_Events(
				$this->api,
				$this->identity,
				$this->product_data
			);
			$this->server_events->set_attribution( $this->attribution );
		}

		// Initialize frontend tracking if enabled.
		if ( WooHog_Settings::is_frontend_tracking_enabled() ) {
			$this->data_layer      = new WooHog_Data_Layer( $this->product_data );
			$this->frontend_events = new WooHog_Frontend_Events();
		}
	}

	/**
	 * Register the WooHog settings tab in WooCommerce > Settings.
	 *
	 * @return void
	 */
	public function register_settings_tab() {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
	}

	/**
	 * Add the WooHog settings page to the WooCommerce settings pages array.
	 *
	 * @param array $settings Array of WC_Settings_Page instances.
	 *
	 * @return array Modified settings array.
	 */
	public function add_settings_page( $settings ) {
		// Load the settings page class lazily — WC_Settings_Page is only
		// available when WooCommerce admin is active (this filter only
		// fires in admin context, so it's always safe here).
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-settings-page.php';
		$settings[] = new WooHog_Settings_Page();
		return $settings;
	}

	/**
	 * Get the PostHog API client instance.
	 *
	 * @return WooHog_PostHog_API|null
	 */
	public function get_api() {
		return $this->api;
	}

	/**
	 * Get the identity manager instance.
	 *
	 * @return WooHog_Identity|null
	 */
	public function get_identity() {
		return $this->identity;
	}

	/**
	 * Get the attribution engine instance.
	 *
	 * @return WooHog_Attribution|null
	 */
	public function get_attribution() {
		return $this->attribution;
	}
}
