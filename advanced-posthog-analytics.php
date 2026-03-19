<?php
/**
 * Plugin Name: InsightTrail for PostHog
 * Plugin URI:  https://github.com/hyroescom/insighttrail-for-posthog
 * Description: PostHog Analytics for WooCommerce — server-side event tracking, marketing attribution engine, identity stitching, and LTV enrichment.
 * Version: 1.5.0
 * Author: AGStudio.ai
 * Author URI: https://agstudio.ai
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 * Text Domain: insighttrail-for-posthog
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package InsightTrailForPostHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin version.
 */
define( 'APHA_VERSION', '1.5.0' );

/**
 * Plugin file path.
 */
define( 'APHA_PLUGIN_FILE', __FILE__ );

/**
 * Plugin directory path (with trailing slash).
 */
define( 'APHA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin directory URL (with trailing slash).
 */
define( 'APHA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
		$settings_url  = admin_url( 'admin.php?page=wc-settings&tab=insighttrail-for-posthog' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'insighttrail-for-posthog' ) . '</a>';
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

		$links[] = '<a href="https://github.com/hyroescom/insighttrail-for-posthog#readme" target="_blank">' . esc_html__( 'Documentation', 'insighttrail-for-posthog' ) . '</a>';
		$links[] = '<a href="https://github.com/hyroescom/insighttrail-for-posthog/issues" target="_blank">' . esc_html__( 'Support', 'insighttrail-for-posthog' ) . '</a>';

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
							<strong>InsightTrail for PostHog</strong> requires WooCommerce to be installed and active.
						</p>
					</div>
					<?php
				}
			);
			return;
		}

		// Load class files.
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-settings.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-posthog-api.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-attribution.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-identity.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-product-data.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-server-events.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-frontend-events.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha-data-layer.php';
		require_once APHA_PLUGIN_DIR . 'includes/class-apha.php';

		// Initialize the plugin.
		APHA::instance();
	}
);
