<?php
/**
 * InsightTrail for PostHog Product Data Helpers.
 *
 * Provides utility methods for extracting structured product and order
 * data suitable for PostHog analytics events.
 *
 * @package InsightTrailForPostHog
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class APHA_Product_Data
 *
 * Extracts product, cart, and order data into associative arrays
 * for use in both server-side and client-side tracking events.
 */
class APHA_Product_Data {

	/**
	 * Get structured product properties for a WooCommerce product.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return array Associative array of product properties.
	 */
	public function get_product_properties( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$product_id = $product->get_id();

		// For variations, use the parent product ID for taxonomy lookups.
		$taxonomy_product_id = ( $product instanceof WC_Product_Variation )
			? $product->get_parent_id()
			: $product_id;

		$properties = array(
			'product_id' => $product_id,
			'sku'        => $product->get_sku(),
			'name'       => $product->get_name(),
			'price'      => (float) $product->get_price(),
			'category'   => $this->get_product_category( $taxonomy_product_id ),
			'brand'      => $this->get_product_brand( $taxonomy_product_id ),
			'url'        => $product->get_permalink(),
			'image_url'  => wp_get_attachment_url( $product->get_image_id() ),
			'currency'   => get_woocommerce_currency(),
		);

		// Add variant information for product variations.
		if ( $product instanceof WC_Product_Variation ) {
			$attributes                  = $product->get_variation_attributes();
			$properties['variant']       = implode( ', ', array_filter( $attributes ) );
			$properties['variation_id']  = $product->get_id();
			$properties['product_id']    = $product->get_parent_id();
		}

		return $properties;
	}

	/**
	 * Get product properties formatted for the JavaScript data layer.
	 *
	 * Includes all standard properties plus a default quantity and
	 * variation data for variable products.
	 *
	 * @param WC_Product $product WooCommerce product object.
	 * @return array Associative array of product properties for JS.
	 */
	public function get_product_properties_for_js( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$properties             = $this->get_product_properties( $product );
		$properties['quantity'] = 1;

		// Include available variations for variable products.
		if ( $product instanceof WC_Product_Variable ) {
			$variations      = array();
			$available       = $product->get_available_variations();

			foreach ( $available as $variation_data ) {
				$variation_product = wc_get_product( $variation_data['variation_id'] );

				if ( ! $variation_product ) {
					continue;
				}

				$variations[] = array(
					'variation_id' => $variation_data['variation_id'],
					'sku'          => $variation_product->get_sku(),
					'price'        => (float) $variation_product->get_price(),
					'attributes'   => $variation_data['attributes'],
					'variant'      => implode( ', ', array_filter( $variation_product->get_variation_attributes() ) ),
				);
			}

			$properties['variations'] = $variations;
		}

		return $properties;
	}

	/**
	 * Get product properties for all items in the current cart.
	 *
	 * @return array Array of product property arrays with quantities.
	 */
	public function get_cart_products() {
		if ( ! WC()->cart ) {
			return array();
		}

		$products = array();

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$item               = $this->get_product_properties( $product );
			$item['quantity']   = (int) $cart_item['quantity'];
			$products[]         = $item;
		}

		return $products;
	}

	/**
	 * Get product properties for all line items in an order.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array Array of product property arrays.
	 */
	public function get_order_products( $order ) {
		$products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product  = $item->get_product();
			$quantity = $item->get_quantity();

			// Calculate unit price from line subtotal (before discounts).
			$unit_price = $quantity > 0
				? (float) $item->get_subtotal() / $quantity
				: 0.0;

			$product_id = $item->get_product_id();
			$variation_id = $item->get_variation_id();

			// For taxonomy lookups, always use the parent product ID.
			$taxonomy_product_id = $product_id;

			$product_entry = array(
				'product_id' => $variation_id ? $variation_id : $product_id,
				'sku'        => $product ? $product->get_sku() : '',
				'name'       => $item->get_name(),
				'price'      => $unit_price,
				'quantity'   => $quantity,
				'category'   => $this->get_product_category( $taxonomy_product_id ),
				'brand'      => $this->get_product_brand( $taxonomy_product_id ),
			);

			// Add variant info if this is a variation.
			if ( $variation_id ) {
				$product_entry['variation_id'] = $variation_id;
				$product_entry['product_id']   = $product_id;

				if ( $product instanceof WC_Product_Variation ) {
					$product_entry['variant'] = implode( ', ', array_filter( $product->get_variation_attributes() ) );
				}
			}

			$products[] = $product_entry;
		}

		return $products;
	}

	/**
	 * Get structured order properties for a WooCommerce order.
	 *
	 * @param WC_Order $order WooCommerce order object.
	 * @return array Associative array of order properties.
	 */
	public function get_order_properties( $order ) {
		$shipping_methods = $order->get_shipping_methods();
		$shipping_titles  = array_map(
			function ( $item ) {
				return $item->get_method_title();
			},
			$shipping_methods
		);

		return array(
			'checkout_id'      => $order->get_order_key(),
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
			'affiliation'      => get_bloginfo( 'name' ),
			'total'            => (float) $order->get_total(),
			'subtotal'         => (float) $order->get_subtotal(),
			'revenue'          => (float) $order->get_total(),
			'shipping'         => (float) $order->get_shipping_total(),
			'tax'              => (float) $order->get_total_tax(),
			'discount'         => (float) $order->get_total_discount(),
			'coupon'           => implode( ', ', $order->get_coupon_codes() ),
			'currency'         => $order->get_currency(),
			'payment_method'   => $order->get_payment_method_title(),
			'shipping_method'  => implode( ', ', $shipping_titles ),
			'billing_country'  => $order->get_billing_country(),
			'shipping_country' => $order->get_shipping_country(),
			'products'         => $this->get_order_products( $order ),
		);
	}

	/**
	 * Get the primary category name for a product.
	 *
	 * @param int $product_id Product ID (parent ID for variations).
	 * @return string Category name or empty string.
	 */
	private function get_product_category( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );

		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return $terms[0]->name;
		}

		return '';
	}

	/**
	 * Get the brand name for a product.
	 *
	 * Checks multiple brand taxonomy slugs for compatibility with
	 * popular brand plugins: WooCommerce Brands, Perfect WooCommerce
	 * Brands, and YITH WooCommerce Brands.
	 *
	 * @param int $product_id Product ID (parent ID for variations).
	 * @return string Brand name or empty string.
	 */
	private function get_product_brand( $product_id ) {
		$brand_taxonomies = array(
			'product_brand',
			'pwb-brand',
			'yith_product_brand',
		);

		foreach ( $brand_taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_the_terms( $product_id, $taxonomy );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				return $terms[0]->name;
			}
		}

		return '';
	}
}
