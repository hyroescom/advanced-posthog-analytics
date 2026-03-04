<?php
/**
 * WooHog Data Layer.
 *
 * Outputs a JavaScript data layer object in the footer containing
 * structured e-commerce data for the current page context.
 *
 * @package WooHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WooHog_Data_Layer
 *
 * Injects `window.woohogDataLayer` with page-specific product and
 * cart data so the frontend tracker script can capture events.
 */
class WooHog_Data_Layer {

	/**
	 * Product data helper.
	 *
	 * @var WooHog_Product_Data
	 */
	private $product_data;

	/**
	 * Constructor.
	 *
	 * @param WooHog_Product_Data $product_data Product data helper instance.
	 */
	public function __construct( WooHog_Product_Data $product_data ) {
		$this->product_data = $product_data;

		add_action( 'wp_footer', array( $this, 'output_data_layer' ), 5 );
	}

	/**
	 * Output the data layer script tag in the footer.
	 *
	 * Builds a structured data array based on the current page context
	 * and outputs it as a JSON object assigned to `window.woohogDataLayer`.
	 *
	 * @return void
	 */
	public function output_data_layer() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$data = array(
			'page_type' => $this->get_page_type(),
		);

		switch ( $data['page_type'] ) {
			case 'product':
				$data = array_merge( $data, $this->get_product_data() );
				break;

			case 'product_list':
				$data = array_merge( $data, $this->get_product_list_data() );
				break;

			case 'search':
				$data = array_merge( $data, $this->get_search_data() );
				break;

			case 'cart':
				$data = array_merge( $data, $this->get_cart_data() );
				break;

			case 'checkout':
				$data = array_merge( $data, $this->get_checkout_data() );
				break;
		}

		/**
		 * Filter the data layer output before it is rendered.
		 *
		 * @param array $data The data layer array.
		 */
		$data = apply_filters( 'woohog_data_layer', $data );

		echo '<script>window.woohogDataLayer = ' . wp_json_encode( $data ) . ';</script>' . "\n";
	}

	/**
	 * Determine the current page type.
	 *
	 * @return string One of: product, product_list, search, cart, checkout, order_received, other.
	 */
	private function get_page_type() {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return 'order_received';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}

		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}

		if ( is_search() && $this->is_product_search() ) {
			return 'search';
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}

		if ( $this->is_product_list_page() ) {
			return 'product_list';
		}

		return 'other';
	}

	/**
	 * Check if the current page is a product list page.
	 *
	 * @return bool
	 */
	private function is_product_list_page() {
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return true;
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return true;
		}

		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return true;
		}

		if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the current search is a product search.
	 *
	 * @return bool
	 */
	private function is_product_search() {
		if ( get_query_var( 'post_type' ) === 'product' ) {
			return true;
		}

		// Check if WooCommerce product search was explicitly triggered.
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			return true;
		}

		return false;
	}

	/**
	 * Get data for a single product page.
	 *
	 * @return array Product data including variation info for variable products.
	 */
	private function get_product_data() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product ) {
			return array();
		}

		// Use get_product_properties_for_js() which includes variations
		// in a single pass, avoiding the N+1 of a separate variation query.
		$js_props = $this->product_data->get_product_properties_for_js( $product );

		$data = array(
			'product' => $js_props,
		);

		// Hoist variations to top level for the tracker script.
		if ( isset( $js_props['variations'] ) ) {
			$data['variations'] = $js_props['variations'];
		}

		return $data;
	}

	/**
	 * Get data for product list pages (shop, category, tag, taxonomy).
	 *
	 * @return array List metadata and products array.
	 */
	private function get_product_list_data() {
		global $wp_query;

		$queried_object = get_queried_object();
		$list_name      = wp_get_document_title();

		if ( $queried_object && isset( $queried_object->name ) ) {
			$list_name = $queried_object->name;
		}

		$list_type = 'shop';
		if ( is_product_category() ) {
			$list_type = 'category';
		} elseif ( is_product_tag() ) {
			$list_type = 'tag';
		} elseif ( is_product_taxonomy() && $queried_object ) {
			$list_type = $queried_object->taxonomy;
		}

		$products = array();

		/**
		 * Maximum number of products to include in the data layer.
		 *
		 * @param int $max Max products. Default 48.
		 */
		$max_products = apply_filters( 'woohog_max_products_in_data_layer', 48 );

		if ( ! empty( $wp_query->posts ) ) {
			$count = 0;
			foreach ( $wp_query->posts as $post ) {
				if ( $count >= $max_products ) {
					break;
				}

				$product = wc_get_product( $post );

				if ( ! $product ) {
					continue;
				}

				$products[] = $this->product_data->get_product_properties( $product );
				$count++;
			}
		}

		return array(
			'list_name' => $list_name,
			'list_type' => $list_type,
			'products'  => $products,
		);
	}

	/**
	 * Get data for product search results pages.
	 *
	 * @return array Search query string.
	 */
	private function get_search_data() {
		return array(
			'query' => get_search_query(),
		);
	}

	/**
	 * Get data for the cart page.
	 *
	 * @return array Cart products, total, and currency.
	 */
	private function get_cart_data() {
		if ( ! WC()->cart ) {
			return array();
		}

		return array(
			'products'   => $this->product_data->get_cart_products(),
			'cart_total'  => (float) WC()->cart->get_total( 'edit' ),
			'currency'   => get_woocommerce_currency(),
		);
	}

	/**
	 * Get data for the checkout page (excludes order-received endpoint).
	 *
	 * @return array Checkout totals, products, and currency.
	 */
	private function get_checkout_data() {
		if ( ! WC()->cart ) {
			return array();
		}

		return array(
			'products' => $this->product_data->get_cart_products(),
			'total'    => (float) WC()->cart->get_total( 'edit' ),
			'subtotal' => (float) WC()->cart->get_subtotal(),
			'shipping' => (float) WC()->cart->get_shipping_total(),
			'tax'      => (float) WC()->cart->get_total_tax(),
			'coupon'   => implode( ', ', WC()->cart->get_applied_coupons() ),
			'currency' => get_woocommerce_currency(),
		);
	}
}
