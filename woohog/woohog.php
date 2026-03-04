<?php
/**
 * Plugin Name: WooHog
 * Plugin URI:  https://github.com/hyroescom/woohog
 * Description: PostHog Analytics for WooCommerce — server-side event tracking, marketing attribution engine, identity stitching, and LTV enrichment. A free, open-source alternative to HYROS.
 * Version: 1.1.0
 * Author: AG Studios
 * Author URI: https://github.com/hyroescom
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 * Text Domain: woohog
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WooHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'WOOHOG_VERSION', '1.1.0' );

/**
 * Plugin file path.
 */
define( 'WOOHOG_PLUGIN_FILE', __FILE__ );

/**
 * Plugin directory path (with trailing slash).
 */
define( 'WOOHOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL (with trailing slash).
 */
define( 'WOOHOG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
 * and Cart/Checkout Blocks.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__ );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__ );
		}
	}
);

/**
 * Add action links on the Plugins page (Settings).
 */
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=woohog' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'woohog' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

/**
 * Add row meta links on the Plugins page (Docs, Support, GitHub).
 */
add_filter(
	'plugin_row_meta',
	function ( $links, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="https://github.com/hyroescom/woohog#readme" target="_blank">' . esc_html__( 'Documentation', 'woohog' ) . '</a>';
		$links[] = '<a href="https://github.com/hyroescom/woohog/issues" target="_blank">' . esc_html__( 'Support', 'woohog' ) . '</a>';

		return $links;
	},
	10,
	2
);

/**
 * Check for WooCommerce dependency and bootstrap the plugin.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					?>
					<div class="notice notice-error">
						<p>
							<strong>WooHog</strong> requires WooCommerce to be installed and active.
						</p>
					</div>
					<?php
				}
			);
			return;
		}

		// Load class files.
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-settings.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-posthog-api.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-attribution.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-identity.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-product-data.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-server-events.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-frontend-events.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog-data-layer.php';
		require_once WOOHOG_PLUGIN_DIR . 'includes/class-woohog.php';

		// Initialize the plugin.
		WooHog::instance();
	}
);
